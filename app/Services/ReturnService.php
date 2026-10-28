<?php

namespace App\Services;

use App\Enums\LocationType;
use App\Enums\MovementType;
use App\Enums\Permission;
use App\Exceptions\BusinessException;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use App\Models\Setting;
use App\Models\User;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Retours, reprises et remboursements (SFD-RET, RG-RET-01 à 06).
 *
 * - Une demande de retour n'a aucun effet sur le stock : le produit ne rejoint jamais automatiquement
 *   le stock vendable (RG-RET-03). C'est la validation qui fixe la destination de chaque ligne ;
 *   un produit endommagé ne peut aller que dans un emplacement « défectueux » (RG-RET-04, TST-014).
 * - Quantité retournable = vendue − retours en cours ou validés (RG-RET-06, TST-013).
 * - Le remboursement est une opération distincte (RG-RET-05, TST-015), plafonnée au montant payé,
 *   et rattachée à la caisse ouverte de celui qui rembourse (obligatoire en espèces).
 */
class ReturnService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly CashSessionService $cash,
        private readonly DocumentNumber $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{sale_id: int, reason: string, notes?: string|null, lines: list<array{sale_line_id: int, quantity: int|string, condition: string}>, validate?: bool, destinations?: array<int, int>, refund?: array<string, mixed>|null}  $data
     */
    public function request(array $data, User $by): SaleReturn
    {
        return DB::transaction(function () use ($data, $by) {
            // Verrou sur la vente : deux demandes simultanées ne peuvent pas dépasser la quantité vendue.
            $sale = Sale::lockForUpdate()->find($data['sale_id']) ?? throw new BusinessException('Vente introuvable.');
            if ($sale->status !== Sale::VALIDATED) {
                throw new BusinessException('Cette vente est annulée : aucun retour n’est possible.');
            }

            $maxDays = (int) Setting::get(Setting::RETURN_DAYS);
            if ($sale->sold_at->lt(now()->subDays($maxDays)) && ! $by->hasPermission(Permission::ReturnsValidate)) {
                throw new BusinessException("Délai de retour dépassé ({$maxDays} jours après la vente) : seul un responsable peut accepter ce retour.", 'return_delay_exceeded');
            }

            $saleLines = $sale->lines()->with(['article', 'activeReturnLines'])->get()->keyBy('id');
            $lines = [];
            $total = '0.00';

            foreach ($data['lines'] as $row) {
                $saleLine = $saleLines->get((int) $row['sale_line_id']) ?? throw new BusinessException('Une ligne ne fait pas partie de cette vente.');
                $designation = $saleLine->article->designation;
                if (isset($lines[$saleLine->id])) {
                    throw new BusinessException("La ligne « {$designation} » apparaît deux fois.");
                }
                $quantity = $this->quantity($row['quantity'], $saleLine);
                if (Decimal::isZero($quantity)) {
                    continue;
                }
                $returnable = $this->returnable($saleLine);
                if (bccomp($quantity, $returnable, 3) > 0) {
                    throw new BusinessException(sprintf(
                        'Quantité retournée trop élevée pour « %s » : %s retournable(s) (vendu %s, déjà retourné ou en cours %s).',
                        $designation,
                        $this->fr($returnable),
                        $this->fr($saleLine->quantity),
                        $this->fr(bcsub($saleLine->quantity, $returnable, 3)),
                    ));
                }
                if (! in_array($row['condition'] ?? null, [SaleReturnLine::GOOD, SaleReturnLine::DAMAGED], true)) {
                    throw new BusinessException("Indiquez l’état du produit « {$designation} ».");
                }

                // Valeur = prix net réellement facturé, au prorata de la quantité retournée.
                $value = Decimal::round(bcdiv(bcmul($saleLine->line_total, $quantity, 10), $saleLine->quantity, 10), 2);
                $lines[$saleLine->id] = [
                    'sale_line_id' => $saleLine->id,
                    'article_id' => $saleLine->article_id,
                    'quantity' => $quantity,
                    'condition' => $row['condition'],
                    'value' => $value,
                ];
                $total = bcadd($total, $value, 2);
            }
            if ($lines === []) {
                throw new BusinessException('Indiquez au moins une quantité retournée.');
            }

            $return = SaleReturn::create([
                'reference' => $this->numbers->next('RET'),
                'sale_id' => $sale->id,
                'status' => SaleReturn::PENDING,
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'total_value' => $total,
                'requested_by' => $by->id,
                'requested_at' => now(),
            ]);
            $return->lines()->createMany(array_values($lines));
            $this->audit->log('return.requested', $return, after: ['sale' => $sale->reference, 'total_value' => $total], reason: $data['reason'], userId: $by->id);

            // Un responsable peut enregistrer et valider en une seule fois.
            if (! empty($data['validate'])) {
                if (! $by->hasPermission(Permission::ReturnsValidate)) {
                    throw new BusinessException('Vous n’êtes pas autorisé à valider un retour.');
                }
                $this->validate($return, $by, $data['destinations'] ?? [], $data['refund'] ?? null);
            }

            return $return->refresh();
        });
    }

    /**
     * Validation : entrée en stock à la destination choisie, puis remboursement éventuel.
     *
     * @param  array<int, int>  $destinations  id de ligne de retour → id d'emplacement (sinon : valeur par défaut)
     * @param  array<string, mixed>|null  $refund  amount, payment_method_id, reference
     */
    public function validate(SaleReturn $return, User $by, array $destinations = [], ?array $refund = null): SaleReturn
    {
        return DB::transaction(function () use ($return, $by, $destinations, $refund) {
            $return = SaleReturn::lockForUpdate()->findOrFail($return->id);
            if ($return->status !== SaleReturn::PENDING) {
                throw new BusinessException('Ce retour a déjà été traité.');
            }
            $sale = $return->sale;
            $defective = Location::where('type', LocationType::Defective)->where('active', true)->orderBy('id')->first();

            $changes = [];
            foreach ($return->lines()->with(['saleLine', 'article'])->get() as $line) {
                $locationId = $destinations[$line->id] ?? ($line->condition === SaleReturnLine::DAMAGED ? $defective?->id : $sale->location_id);
                $location = Location::where('active', true)->find($locationId)
                    ?? throw new BusinessException("Choisissez l’emplacement de destination de « {$line->article->designation} ».");
                // Endommagé : uniquement vers un emplacement « défectueux » (le magasin permettrait un transfert puis une revente).
                if ($line->condition === SaleReturnLine::DAMAGED && $location->type !== LocationType::Defective) {
                    throw new BusinessException("« {$line->article->designation} » est endommagé : il doit aller dans un emplacement « Retours / Défectueux ».");
                }

                $line->update(['destination_location_id' => $location->id]);
                $changes[] = StockChange::in($line->article_id, $location->id, MovementType::CustomerReturn, $line->quantity, $line->saleLine->unit_cost_snapshot);
            }

            $this->stock->apply($changes, $by, $return, "Retour {$return->reference} ({$sale->reference})");
            $return->update(['status' => SaleReturn::VALIDATED, 'decided_by' => $by->id, 'decided_at' => now()]);
            $this->audit->log('return.validated', $return, after: ['destinations' => $return->lines()->pluck('destination_location_id', 'id')->all()], userId: $by->id);

            if ($refund && ! empty($refund['amount']) && bccomp((string) $refund['amount'], '0', 2) > 0) {
                $this->refund($return, $refund, $by);
            }

            return $return;
        });
    }

    public function reject(SaleReturn $return, User $by, string $reason): SaleReturn
    {
        return DB::transaction(function () use ($return, $by, $reason) {
            $return = SaleReturn::lockForUpdate()->findOrFail($return->id);
            if ($return->status !== SaleReturn::PENDING) {
                throw new BusinessException('Ce retour a déjà été traité.');
            }
            $return->update(['status' => SaleReturn::REJECTED, 'decided_by' => $by->id, 'decided_at' => now(), 'rejection_reason' => $reason]);
            $this->audit->log('return.rejected', $return, reason: $reason, userId: $by->id);

            return $return;
        });
    }

    /**
     * Remboursement d'un retour validé (aussitôt ou plus tard), plafonné à sa valeur et au montant payé.
     *
     * @param  array<string, mixed>  $data  amount, payment_method_id, reference
     */
    public function refund(SaleReturn $return, array $data, User $by): Refund
    {
        return DB::transaction(function () use ($return, $data, $by) {
            $return = SaleReturn::lockForUpdate()->findOrFail($return->id);
            if ($return->status !== SaleReturn::VALIDATED) {
                throw new BusinessException('Seul un retour validé peut être remboursé.');
            }
            $alreadyRefunded = Decimal::round((string) ($return->refunds()->sum('amount') ?: '0'), 2);
            $left = bcsub($return->total_value, $alreadyRefunded, 2);

            return $this->pay($return->sale, $return, $data, $by, $left);
        });
    }

    /**
     * Enregistre un remboursement sur une vente (retour ou annulation).
     *
     * @param  array<string, mixed>  $data  amount, payment_method_id, reference
     */
    public function pay(Sale $sale, ?SaleReturn $return, array $data, User $by, ?string $cap = null): Refund
    {
        $sale = Sale::lockForUpdate()->findOrFail($sale->id);
        try {
            $amount = Decimal::normalize((string) ($data['amount'] ?? ''), 2);
        } catch (InvalidArgumentException) {
            throw new BusinessException('Montant de remboursement invalide.');
        }
        if (bccomp($amount, '0', 2) <= 0) {
            throw new BusinessException('Le montant du remboursement doit être positif.');
        }

        $refundable = bcsub($sale->amount_paid, $sale->refunded_amount, 2);
        if ($cap !== null && bccomp($cap, $refundable, 2) < 0) {
            $refundable = $cap;
        }
        if (bccomp($amount, $refundable, 2) > 0) {
            throw new BusinessException(sprintf('Remboursement trop élevé : %s au maximum.', $this->fr($refundable)));
        }

        $method = PaymentMethod::where('active', true)->find($data['payment_method_id'] ?? 0)
            ?? throw new BusinessException('Mode de remboursement invalide.');
        $session = $this->cash->current($by);
        if ($method->code === CashSessionService::CASH && ! $session) {
            throw new BusinessException('Ouvrez votre caisse pour rembourser en espèces : le montant sortira de votre tiroir.', 'cash_session_required');
        }

        $refund = Refund::create([
            'sale_id' => $sale->id,
            'sale_return_id' => $return?->id,
            'payment_method_id' => $method->id,
            'cash_session_id' => $session?->id,
            'amount' => $amount,
            'reference' => $data['reference'] ?? null,
            'refunded_by' => $by->id,
            'refunded_at' => isset($data['refunded_at']) ? Carbon::parse($data['refunded_at']) : now(),
        ]);
        $sale->update(['refunded_amount' => bcadd($sale->refunded_amount, $amount, 2)]);
        $this->audit->log('refund.created', $refund, after: ['sale' => $sale->reference, 'amount' => $amount, 'method' => $method->code], userId: $by->id);

        return $refund;
    }

    /** Quantité encore retournable sur une ligne de vente (retours refusés exclus). */
    public function returnable(SaleLine $line): string
    {
        $returned = $line->activeReturnLines->reduce(fn (string $s, SaleReturnLine $l) => bcadd($s, $l->quantity, 3), '0.000');

        return bcsub($line->quantity, $returned, 3);
    }

    private function quantity(int|string|float $value, SaleLine $line): string
    {
        try {
            $quantity = Decimal::normalize((string) $value, Decimal::QUANTITY_SCALE);
        } catch (InvalidArgumentException) {
            throw new BusinessException("Quantité invalide pour « {$line->article->designation} ».");
        }
        if (bccomp($quantity, '0', 3) < 0) {
            throw new BusinessException("Quantité invalide pour « {$line->article->designation} ».");
        }
        if (! $line->article->allow_decimal_quantity && ! Decimal::isInteger($quantity)) {
            throw new BusinessException("L’article « {$line->article->designation} » se compte en unités entières.");
        }

        return $quantity;
    }

    private function fr(string $value): string
    {
        return str_replace('.', ',', Decimal::trim($value));
    }
}
