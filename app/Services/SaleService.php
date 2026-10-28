<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\Permission;
use App\Exceptions\BusinessException;
use App\Models\Article;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Validation d'une vente (SFD-VTE) : tout ou rien.
 *
 * - Session de caisse ouverte obligatoire (TST-025) ; encaissements rattachés à cette session.
 * - Prix de vente du catalogue ; remise du caissier plafonnée (paramètre), sauf droit discounts.grant.
 * - Paiement mixte possible ; en espèces, le surplus est rendu (monnaie) ; paiement partiel seulement
 *   si le crédit est activé et le client identifié.
 * - Sortie de stock par StockService à l'emplacement vendable, verrouillée (TST-009, TST-011).
 */
class SaleService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly CashSessionService $cash,
        private readonly DocumentNumber $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{customer_id?: int|null, location_id?: int|null, notes?: string|null, lines: list<array{article_id: int, quantity: int|string, discount?: int|string|null}>, payments?: list<array{payment_method_id: int, amount: int|string, reference?: string|null}>}  $data
     */
    public function create(array $data, User $by): Sale
    {
        return DB::transaction(function () use ($data, $by) {
            $session = CashSession::where('cashier_id', $by->id)->where('status', CashSession::OPEN)->lockForUpdate()->first()
                ?? throw new BusinessException('Aucune caisse ouverte : ouvrez votre caisse avant d’enregistrer une vente.', 'cash_session_required');

            $location = $this->location($data['location_id'] ?? null);
            $customer = null;
            if (! empty($data['customer_id'])) {
                $customer = Customer::where('active', true)->find($data['customer_id'])
                    ?? throw new BusinessException('Client introuvable ou désactivé.');
            }

            [$lines, $subtotal, $discountTotal] = $this->lines($data['lines'] ?? []);
            $total = bcsub($subtotal, $discountTotal, 2);
            $this->checkDiscountLimit($by, $subtotal, $discountTotal);

            [$payments, $paid, $change] = $this->payments($data['payments'] ?? [], $total, $customer);

            $sale = Sale::create([
                'reference' => $this->numbers->next('VTE'),
                'sold_at' => now(),
                'cash_session_id' => $session->id,
                'cashier_id' => $by->id,
                'customer_id' => $customer?->id,
                'location_id' => $location->id,
                'status' => 'validated',
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'total' => $total,
                'amount_paid' => $paid,
                'change_given' => $change,
                'notes' => $data['notes'] ?? null,
            ]);

            // Sortie de stock (contrôle du disponible sous verrou) ; le CMP du moment est figé sur chaque ligne.
            $movements = $this->stock->apply(
                array_map(fn (array $l) => StockChange::out($l['article_id'], $location->id, MovementType::Sale, $l['quantity']), $lines),
                $by,
                $sale,
                "Vente {$sale->reference}",
            );
            foreach (array_values($lines) as $i => $line) {
                $sale->lines()->create($line + ['unit_cost_snapshot' => $movements[$i]->unit_cost]);
            }

            foreach ($payments as $payment) {
                $sale->payments()->create($payment + [
                    'cash_session_id' => $session->id,
                    'paid_at' => now(),
                    'received_by' => $by->id,
                ]);
            }

            $this->audit->log('sale.created', $sale, after: [
                'total' => $total, 'discount_total' => $discountTotal, 'amount_paid' => $paid, 'change_given' => $change,
                'customer_id' => $customer?->id, 'session' => $session->reference,
            ], userId: $by->id);

            return $sale;
        });
    }

    /**
     * Paramètres utiles à l'écran de caisse.
     *
     * @return array<string, mixed>
     */
    public function config(User $user): array
    {
        return [
            'discount_limit_percent' => (string) Setting::get(Setting::CASHIER_DISCOUNT_LIMIT),
            'can_grant_discounts' => $user->hasPermission(Permission::DiscountsGrant),
            'allow_credit' => (bool) Setting::get(Setting::ALLOW_CREDIT),
            'locations' => Location::where('active', true)->where('is_sellable', true)->orderBy('id')->get(['id', 'code', 'name']),
            'payment_methods' => PaymentMethod::where('active', true)->orderBy('id')->get(['id', 'code', 'name']),
        ];
    }

    private function location(?int $id): Location
    {
        $query = Location::where('active', true)->where('is_sellable', true);
        $location = $id ? $query->find($id) : $query->orderBy('id')->first();

        return $location ?? throw new BusinessException('Aucun emplacement vendable disponible.');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: string, 2: string} lignes, sous-total, remises
     */
    private function lines(array $rows): array
    {
        if ($rows === []) {
            throw new BusinessException('Le panier est vide.');
        }

        $articles = Article::whereIn('id', array_column($rows, 'article_id'))->get()->keyBy('id');
        $lines = [];
        $subtotal = '0.00';
        $discountTotal = '0.00';

        foreach (array_values($rows) as $position => $row) {
            $article = $articles->get((int) $row['article_id']) ?? throw new BusinessException('Article introuvable.');
            if (! $article->active) {
                throw new BusinessException("L’article « {$article->designation} » est désactivé.");
            }
            if ($article->sale_price === null) {
                throw new BusinessException("L’article « {$article->designation} » n’a pas de prix de vente.");
            }
            if (isset($lines[$article->id])) {
                throw new BusinessException("L’article « {$article->designation} » figure deux fois dans le panier.");
            }

            try {
                $quantity = Decimal::normalize((string) $row['quantity'], Decimal::QUANTITY_SCALE);
                $discount = Decimal::normalize((string) ($row['discount'] ?? '0'), 2);
            } catch (InvalidArgumentException) {
                throw new BusinessException("Quantité ou remise invalide pour « {$article->designation} ».");
            }
            if (bccomp($quantity, '0', 3) <= 0) {
                throw new BusinessException("La quantité de « {$article->designation} » doit être positive.");
            }
            if (! $article->allow_decimal_quantity && ! Decimal::isInteger($quantity)) {
                throw new BusinessException("L’article « {$article->designation} » se vend en unités entières.");
            }

            $gross = Decimal::round(bcmul($quantity, $article->sale_price, 5), 2);
            if (bccomp($discount, '0', 2) < 0 || bccomp($discount, $gross, 2) > 0) {
                throw new BusinessException("Remise invalide pour « {$article->designation} ».");
            }

            $lines[$article->id] = [
                'article_id' => $article->id,
                'quantity' => $quantity,
                'unit_price' => $article->sale_price,
                'discount' => $discount,
                'line_total' => bcsub($gross, $discount, 2),
                'position' => $position,
            ];
            $subtotal = bcadd($subtotal, $gross, 2);
            $discountTotal = bcadd($discountTotal, $discount, 2);
        }

        return [array_values($lines), $subtotal, $discountTotal];
    }

    private function checkDiscountLimit(User $user, string $subtotal, string $discountTotal): void
    {
        if (Decimal::isZero($discountTotal) || $user->hasPermission(Permission::DiscountsGrant)) {
            return;
        }

        $limit = (string) Setting::get(Setting::CASHIER_DISCOUNT_LIMIT);
        $max = Decimal::round(bcdiv(bcmul($subtotal, $limit, 6), '100', 6), 2);
        if (bccomp($discountTotal, $max, 2) > 0) {
            throw new BusinessException(sprintf(
                'Remise trop élevée : %s %% du montant au maximum (soit %s). Une remise supérieure doit être accordée par le propriétaire.',
                str_replace('.', ',', $limit),
                str_replace('.', ',', Decimal::trim($max)),
            ), 'discount_limit_exceeded');
        }
    }

    /**
     * Encaissements : surplus rendu en monnaie s'il y a des espèces ; manque accepté seulement à crédit.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: string, 2: string} paiements, montant payé, monnaie rendue
     */
    private function payments(array $rows, string $total, ?Customer $customer): array
    {
        $methods = PaymentMethod::where('active', true)->get()->keyBy('id');
        $payments = [];
        $received = '0.00';

        foreach ($rows as $row) {
            $method = $methods->get((int) ($row['payment_method_id'] ?? 0)) ?? throw new BusinessException('Mode de paiement invalide.');
            try {
                $amount = Decimal::normalize((string) $row['amount'], 2);
            } catch (InvalidArgumentException) {
                throw new BusinessException('Montant de paiement invalide.');
            }
            if (bccomp($amount, '0', 2) <= 0) {
                continue;
            }
            $payments[] = ['payment_method_id' => $method->id, 'amount' => $amount, 'reference' => $row['reference'] ?? null, 'code' => $method->code];
            $received = bcadd($received, $amount, 2);
        }

        $change = '0.00';
        $difference = bcsub($received, $total, 2);

        if (bccomp($difference, '0', 2) > 0) {
            // Surplus : rendu en monnaie, uniquement sur la part payée en espèces.
            $cash = array_reduce($payments, fn (string $s, array $p) => $p['code'] === CashSessionService::CASH ? bcadd($s, $p['amount'], 2) : $s, '0.00');
            if (bccomp($difference, $cash, 2) > 0) {
                throw new BusinessException('Le montant payé par carte, virement ou Mobile Money dépasse le total de la vente.');
            }
            $change = $difference;
            $toRemove = $difference;
            foreach (array_reverse(array_keys($payments)) as $i) {
                if ($payments[$i]['code'] !== CashSessionService::CASH || Decimal::isZero($toRemove)) {
                    continue;
                }
                $take = bccomp($payments[$i]['amount'], $toRemove, 2) >= 0 ? $toRemove : $payments[$i]['amount'];
                $payments[$i]['amount'] = bcsub($payments[$i]['amount'], $take, 2);
                $toRemove = bcsub($toRemove, $take, 2);
            }
            $payments = array_values(array_filter($payments, fn (array $p) => ! Decimal::isZero($p['amount'])));
        } elseif (bccomp($difference, '0', 2) < 0) {
            if (! Setting::get(Setting::ALLOW_CREDIT)) {
                throw new BusinessException(sprintf('Montant insuffisant : il manque %s.', str_replace('.', ',', Decimal::trim(bcmul($difference, '-1', 2)))), 'payment_insufficient');
            }
            if (! $customer) {
                throw new BusinessException('Une vente à crédit nécessite d’identifier le client.', 'customer_required');
            }
        }

        $paid = array_reduce($payments, fn (string $s, array $p) => bcadd($s, $p['amount'], 2), '0.00');

        return [array_map(fn (array $p) => array_diff_key($p, ['code' => true]), $payments), $paid, $change];
    }
}
