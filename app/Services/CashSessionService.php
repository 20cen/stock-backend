<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\CashSession;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\SalePayment;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ouverture et clôture de caisse (SFD-VTE). À la clôture : solde théorique par mode de paiement,
 * montant compté déclaré pour les espèces, écart obligatoirement justifié s'il n'est pas nul (TST-026).
 */
class CashSessionService
{
    public const CASH = 'cash';

    public function __construct(
        private readonly DocumentNumber $numbers,
        private readonly AuditLogger $audit,
    ) {}

    public function current(User $user): ?CashSession
    {
        return CashSession::where('cashier_id', $user->id)->where('status', CashSession::OPEN)->first();
    }

    public function open(User $user, int|string|float $openingAmount, ?string $notes = null): CashSession
    {
        $amount = $this->money($openingAmount, 'Le fonds de caisse');
        if (bccomp($amount, '0', 2) < 0) {
            throw new BusinessException('Le fonds de caisse ne peut pas être négatif.');
        }

        try {
            return DB::transaction(function () use ($user, $amount, $notes) {
                if ($this->current($user)) {
                    throw new BusinessException('Votre caisse est déjà ouverte.');
                }

                $session = CashSession::create([
                    'reference' => $this->numbers->next('CAI'),
                    'cashier_id' => $user->id,
                    'status' => CashSession::OPEN,
                    'opened_at' => now(),
                    'opening_amount' => $amount,
                    'notes' => $notes,
                ]);
                $this->audit->log('cash.opened', $session, after: ['opening_amount' => $amount], userId: $user->id);

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            // Deux ouvertures simultanées : l'index unique partiel n'en laisse passer qu'une.
            throw new BusinessException('Votre caisse est déjà ouverte.');
        }
    }

    /**
     * Encaissements de la session par mode de paiement et espèces attendues dans le tiroir.
     *
     * @return array{methods: list<array{payment_method_id: int, code: string, name: string, amount: string}>, expected_cash: string, sales_count: int, sales_total: string}
     */
    public function totals(CashSession $session): array
    {
        $sums = SalePayment::where('cash_session_id', $session->id)
            ->groupBy('payment_method_id')
            ->selectRaw('payment_method_id, SUM(amount) AS total')
            ->pluck('total', 'payment_method_id');
        // Les remboursements faits depuis cette caisse viennent en déduction.
        $refunds = Refund::where('cash_session_id', $session->id)
            ->groupBy('payment_method_id')
            ->selectRaw('payment_method_id, SUM(amount) AS total')
            ->pluck('total', 'payment_method_id');

        $methods = PaymentMethod::orderBy('id')->get()
            ->filter(fn (PaymentMethod $m) => $m->active || isset($sums[$m->id]) || isset($refunds[$m->id]))
            ->map(fn (PaymentMethod $m) => [
                'payment_method_id' => $m->id,
                'code' => $m->code,
                'name' => $m->name,
                'amount' => bcsub(
                    Decimal::round((string) ($sums[$m->id] ?? '0'), 2),
                    Decimal::round((string) ($refunds[$m->id] ?? '0'), 2),
                    2,
                ),
            ])->values()->all();

        $cash = collect($methods)->firstWhere('code', self::CASH)['amount'] ?? '0.00';
        $sales = $session->sales()->where('status', 'validated');

        return [
            'methods' => $methods,
            'expected_cash' => bcadd($session->opening_amount, $cash, 2),
            'sales_count' => $sales->count(),
            'sales_total' => Decimal::round((string) ($sales->sum('total') ?: '0'), 2),
        ];
    }

    public function close(CashSession $session, User $by, int|string|float $declaredCash, ?string $reason, ?string $notes = null): CashSession
    {
        $declared = $this->money($declaredCash, 'Le montant compté');
        if (bccomp($declared, '0', 2) < 0) {
            throw new BusinessException('Le montant compté ne peut pas être négatif.');
        }

        return DB::transaction(function () use ($session, $by, $declared, $reason, $notes) {
            // Verrou : aucune vente ne peut s'ajouter pendant la clôture.
            $session = CashSession::lockForUpdate()->findOrFail($session->id);
            if (! $session->isOpen()) {
                throw new BusinessException('Cette caisse est déjà clôturée.');
            }

            $totals = $this->totals($session);
            $variance = bcsub($declared, $totals['expected_cash'], 2);
            $reason = trim((string) $reason);
            if (! Decimal::isZero($variance) && mb_strlen($reason) < 3) {
                throw new BusinessException(sprintf(
                    'Écart de %s entre le montant compté et le solde théorique : indiquez le motif.',
                    str_replace('.', ',', Decimal::trim($variance)),
                ), 'variance_reason_required');
            }

            $session->update([
                'status' => CashSession::CLOSED,
                'closed_at' => now(),
                'expected_cash' => $totals['expected_cash'],
                'declared_cash' => $declared,
                'variance' => $variance,
                'variance_reason' => $reason !== '' ? $reason : null,
                'totals_by_method' => $totals['methods'],
                'notes' => $notes ?? $session->notes,
            ]);

            $this->audit->log('cash.closed', $session, after: [
                'expected_cash' => $totals['expected_cash'],
                'declared_cash' => $declared,
                'variance' => $variance,
            ], reason: $reason !== '' ? $reason : null, userId: $by->id);

            return $session;
        });
    }

    private function money(int|string|float $value, string $label): string
    {
        try {
            return Decimal::normalize((string) $value, 2);
        } catch (InvalidArgumentException) {
            throw new BusinessException("{$label} doit être un montant avec au plus 2 décimales.");
        }
    }
}
