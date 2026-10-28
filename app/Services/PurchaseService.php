<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\PurchaseStatus;
use App\Exceptions\BusinessException;
use App\Models\Article;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchasePayment;
use App\Models\Receipt;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Acquisitions, réceptions et paiements fournisseurs (SFD-ACH, RG-ACH-01 à 06).
 *
 * - Une acquisition n'a aucun effet sur le stock ; seule une réception validée en fait entrer.
 * - Brouillon modifiable ; une fois commandée, les lignes sont figées.
 * - Le coût d'entrée en stock inclut la remise de la ligne et une part des frais supplémentaires,
 *   répartis au prorata de la valeur nette des lignes : le CMP reflète le coût de revient réel.
 */
class PurchaseService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly DocumentNumber $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  en-tête, lines[], initial_payment éventuel
     */
    public function create(array $data, User $by, bool $confirm): Purchase
    {
        return DB::transaction(function () use ($data, $by, $confirm) {
            $purchase = new Purchase([
                'reference' => $this->numbers->next('ACH'),
                'status' => PurchaseStatus::Draft,
                'created_by' => $by->id,
            ]);
            $this->fill($purchase, $data);
            $this->audit->log('purchase.created', $purchase, after: $this->snapshot($purchase), userId: $by->id);

            if ($confirm) {
                $this->confirm($purchase, $by);
                if (! empty($data['initial_payment']['amount'])) {
                    $this->addPayment($purchase, $data['initial_payment'], $by);
                }
            }

            return $purchase->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Purchase $purchase, array $data, User $by, bool $confirm): Purchase
    {
        return DB::transaction(function () use ($purchase, $data, $by, $confirm) {
            $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);
            if ($purchase->status !== PurchaseStatus::Draft) {
                throw new BusinessException('Seul un brouillon peut être modifié.');
            }

            $before = $this->snapshot($purchase);
            $this->fill($purchase, $data);
            $this->audit->log('purchase.updated', $purchase, before: $before, after: $this->snapshot($purchase), userId: $by->id);

            if ($confirm) {
                $this->confirm($purchase, $by);
                if (! empty($data['initial_payment']['amount'])) {
                    $this->addPayment($purchase, $data['initial_payment'], $by);
                }
            }

            return $purchase->refresh();
        });
    }

    /**
     * Valide la commande : les lignes ne sont plus modifiables, les réceptions deviennent possibles.
     */
    public function confirm(Purchase $purchase, User $by): Purchase
    {
        return DB::transaction(function () use ($purchase, $by) {
            $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);
            if ($purchase->status !== PurchaseStatus::Draft) {
                throw new BusinessException('Cette acquisition est déjà validée.');
            }
            if (! $purchase->lines()->exists()) {
                throw new BusinessException('Ajoutez au moins une ligne avant de valider l’acquisition.');
            }

            $purchase->update(['status' => PurchaseStatus::Ordered, 'ordered_at' => now(), 'ordered_by' => $by->id]);
            $this->audit->log('purchase.ordered', $purchase, after: ['total' => $purchase->total], userId: $by->id);

            return $purchase;
        });
    }

    public function cancel(Purchase $purchase, User $by, string $reason): Purchase
    {
        return DB::transaction(function () use ($purchase, $by, $reason) {
            $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);
            if (! in_array($purchase->status, [PurchaseStatus::Draft, PurchaseStatus::Ordered], true)) {
                throw new BusinessException('Une acquisition déjà (partiellement) réceptionnée ou annulée ne peut pas être annulée.');
            }
            if (bccomp($purchase->paid_amount, '0', 2) > 0) {
                throw new BusinessException('Des paiements sont enregistrés sur cette acquisition : elle ne peut pas être annulée.');
            }

            $purchase->update([
                'status' => PurchaseStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancel_reason' => $reason,
            ]);
            $this->audit->log('purchase.cancelled', $purchase, reason: $reason, userId: $by->id);

            return $purchase;
        });
    }

    /**
     * Réception (partielle ou complète) : seules ces quantités entrent en stock, à l'emplacement indiqué.
     *
     * @param  array{location_id: int, received_at?: string|null, notes?: string|null, lines: list<array{purchase_line_id: int, quantity: int|string}>}  $data
     */
    public function receive(Purchase $purchase, array $data, User $by): Receipt
    {
        return DB::transaction(function () use ($purchase, $data, $by) {
            // Verrou sur l'acquisition : deux réceptions simultanées ne peuvent pas dépasser le commandé.
            $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);
            if (! $purchase->status->canReceive()) {
                throw new BusinessException(match ($purchase->status) {
                    PurchaseStatus::Draft => 'Validez l’acquisition avant de réceptionner.',
                    PurchaseStatus::Received => 'Cette acquisition est entièrement réceptionnée.',
                    default => 'Cette acquisition est annulée.',
                });
            }

            $lines = $purchase->lines()->with('article')->get()->keyBy('id');
            $requested = [];
            foreach ($data['lines'] as $row) {
                $line = $lines->get((int) $row['purchase_line_id'])
                    ?? throw new BusinessException('Une ligne ne fait pas partie de cette acquisition.');
                if (isset($requested[$line->id])) {
                    throw new BusinessException("La ligne « {$line->article->designation} » apparaît deux fois.");
                }
                $quantity = $this->quantity($row['quantity'], $line->article);
                if (Decimal::isZero($quantity)) {
                    continue;
                }
                if (bccomp($quantity, $line->quantity_remaining, 3) > 0) {
                    throw new BusinessException(sprintf(
                        'Quantité reçue trop élevée pour « %s » : %s restant(s) à recevoir.',
                        $line->article->designation,
                        str_replace('.', ',', Decimal::trim($line->quantity_remaining)),
                    ));
                }
                $requested[$line->id] = $quantity;
            }
            if ($requested === []) {
                throw new BusinessException('Indiquez au moins une quantité reçue.');
            }

            $receipt = Receipt::create([
                'reference' => $this->numbers->next('REC'),
                'purchase_id' => $purchase->id,
                'location_id' => (int) $data['location_id'],
                'received_at' => isset($data['received_at']) ? Carbon::parse($data['received_at']) : now(),
                'notes' => $data['notes'] ?? null,
                'received_by' => $by->id,
            ]);

            $changes = [];
            foreach ($requested as $lineId => $quantity) {
                $line = $lines[$lineId];
                $unitCost = $this->landedUnitCost($purchase, $line);

                $receipt->lines()->create([
                    'purchase_line_id' => $line->id,
                    'article_id' => $line->article_id,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                ]);
                $line->update(['quantity_received' => bcadd($line->quantity_received, $quantity, 3)]);
                $changes[] = StockChange::in($line->article_id, $receipt->location_id, MovementType::PurchaseReceipt, $quantity, $unitCost);
            }

            // Entrée en stock par le moteur unique (contrôles d'emplacement, CMP, mouvements tracés).
            $this->stock->apply($changes, $by, $receipt, "Réception {$receipt->reference} ({$purchase->reference})");

            $complete = $lines->every(fn (PurchaseLine $l) => Decimal::isZero($l->quantity_remaining));
            $purchase->update(['status' => $complete ? PurchaseStatus::Received : PurchaseStatus::PartiallyReceived]);

            $this->audit->log('receipt.validated', $receipt, after: [
                'purchase' => $purchase->reference,
                'location_id' => $receipt->location_id,
                'lines' => $requested,
            ], userId: $by->id);

            return $receipt;
        });
    }

    /**
     * @param  array<string, mixed>  $data  amount, payment_method_id, paid_at, reference, notes
     */
    public function addPayment(Purchase $purchase, array $data, User $by): PurchasePayment
    {
        return DB::transaction(function () use ($purchase, $data, $by) {
            $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);
            if (! $purchase->status->canPay()) {
                throw new BusinessException('Un paiement ne peut être enregistré que sur une acquisition validée.');
            }

            $amount = $this->money($data['amount'] ?? null, 'Le montant du paiement');
            if (bccomp($amount, '0', 2) <= 0) {
                throw new BusinessException('Le montant du paiement doit être positif.');
            }
            if (bccomp($amount, $purchase->balance_due, 2) > 0) {
                throw new BusinessException(sprintf('Le paiement dépasse le solde dû (%s).', str_replace('.', ',', Decimal::trim($purchase->balance_due))));
            }
            $method = PaymentMethod::where('active', true)->find($data['payment_method_id'] ?? 0)
                ?? throw new BusinessException('Mode de paiement invalide.');

            $payment = PurchasePayment::create([
                'purchase_id' => $purchase->id,
                'payment_method_id' => $method->id,
                'amount' => $amount,
                'paid_at' => isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(),
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $by->id,
            ]);
            $purchase->update(['paid_amount' => bcadd($purchase->paid_amount, $amount, 2)]);

            $this->audit->log('purchase.payment_added', $purchase, after: [
                'amount' => $amount, 'method' => $method->code, 'balance_due' => $purchase->balance_due,
            ], userId: $by->id);

            return $payment;
        });
    }

    /**
     * Coût de revient unitaire d'une ligne : (montant net de la ligne + part des frais) / quantité commandée.
     */
    public function landedUnitCost(Purchase $purchase, PurchaseLine $line): string
    {
        $scale = 10;
        $netGoods = bcsub($purchase->subtotal, $purchase->discount_total, 2);
        $lineValue = $line->line_total;

        if (bccomp($netGoods, '0', 2) > 0 && bccomp($purchase->extra_costs, '0', 2) > 0) {
            $share = bcdiv(bcmul($purchase->extra_costs, $lineValue, $scale), $netGoods, $scale);
            $lineValue = bcadd($lineValue, $share, $scale);
        }

        return Decimal::round(bcdiv($lineValue, $line->quantity_ordered, $scale), Decimal::COST_SCALE);
    }

    /**
     * Renseigne l'en-tête, remplace les lignes et recalcule les montants (brouillon uniquement).
     *
     * @param  array<string, mixed>  $data
     */
    private function fill(Purchase $purchase, array $data): void
    {
        $supplier = Supplier::find($data['supplier_id'] ?? 0)
            ?? throw new BusinessException('Fournisseur introuvable.');
        if (! $supplier->active && $supplier->id !== $purchase->supplier_id) {
            throw new BusinessException("Le fournisseur « {$supplier->name} » est désactivé.");
        }
        if (empty($data['lines'])) {
            throw new BusinessException('Ajoutez au moins une ligne d’article.');
        }

        $articles = Article::whereIn('id', array_column($data['lines'], 'article_id'))->get()->keyBy('id');
        $rows = [];
        $subtotal = '0.00';
        $discountTotal = '0.00';

        foreach (array_values($data['lines']) as $position => $row) {
            $article = $articles->get((int) $row['article_id']) ?? throw new BusinessException('Article introuvable.');
            if (! $article->active) {
                throw new BusinessException("L’article « {$article->designation} » est désactivé.");
            }
            if (isset($rows[$article->id])) {
                throw new BusinessException("L’article « {$article->designation} » figure deux fois : regroupez les quantités.");
            }

            $quantity = $this->quantity($row['quantity'], $article);
            if (bccomp($quantity, '0', 3) <= 0) {
                throw new BusinessException("La quantité de « {$article->designation} » doit être positive.");
            }
            $unitPrice = $this->money($row['unit_price'], 'Le prix unitaire');
            $discount = $this->money($row['discount'] ?? '0', 'La remise');
            $gross = Decimal::round(bcmul($quantity, $unitPrice, 5), 2);
            if (bccomp($unitPrice, '0', 2) < 0 || bccomp($discount, '0', 2) < 0 || bccomp($discount, $gross, 2) > 0) {
                throw new BusinessException("Prix ou remise invalide pour « {$article->designation} ».");
            }

            $rows[$article->id] = [
                'article_id' => $article->id,
                'quantity_ordered' => $quantity,
                'unit_price' => $unitPrice,
                'discount' => $discount,
                'line_total' => bcsub($gross, $discount, 2),
                'position' => $position,
            ];
            $subtotal = bcadd($subtotal, $gross, 2);
            $discountTotal = bcadd($discountTotal, $discount, 2);
        }

        $extraCosts = $this->money($data['extra_costs'] ?? '0', 'Les frais supplémentaires');
        if (bccomp($extraCosts, '0', 2) < 0) {
            throw new BusinessException('Les frais supplémentaires ne peuvent pas être négatifs.');
        }

        $purchase->fill([
            'supplier_id' => $supplier->id,
            'purchase_date' => $data['purchase_date'] ?? now()->toDateString(),
            'supplier_reference' => $data['supplier_reference'] ?? null,
            'payment_terms' => $data['payment_terms'] ?? null,
            'notes' => $data['notes'] ?? null,
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'extra_costs' => $extraCosts,
            'total' => bcadd(bcsub($subtotal, $discountTotal, 2), $extraCosts, 2),
        ])->save();

        $purchase->lines()->delete();
        $purchase->lines()->createMany(array_values($rows));
    }

    private function quantity(int|string|float $value, Article $article): string
    {
        try {
            $quantity = Decimal::normalize((string) $value, Decimal::QUANTITY_SCALE);
        } catch (InvalidArgumentException) {
            throw new BusinessException("Quantité invalide pour « {$article->designation} ».");
        }
        if (! $article->allow_decimal_quantity && ! Decimal::isInteger($quantity)) {
            throw new BusinessException("L’article « {$article->designation} » se compte en unités entières.");
        }

        return $quantity;
    }

    private function money(int|string|float|null $value, string $label): string
    {
        try {
            return Decimal::normalize((string) ($value ?? '0'), 2);
        } catch (InvalidArgumentException) {
            throw new BusinessException("{$label} doit être un montant avec au plus 2 décimales.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Purchase $purchase): array
    {
        return [
            'reference' => $purchase->reference,
            'supplier_id' => $purchase->supplier_id,
            'total' => $purchase->total,
            'lines' => $purchase->lines()->get(['article_id', 'quantity_ordered', 'unit_price', 'discount'])->toArray(),
        ];
    }
}
