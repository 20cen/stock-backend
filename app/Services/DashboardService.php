<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Inventory;
use App\Models\Loss;
use App\Models\Purchase;
use App\Models\Receipt;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Indicateurs du tableau de bord (SFD-DASH). Indicateurs de gestion, pas des états comptables.
 *
 * Conventions (à valider avec le client) :
 * - chiffre d'affaires net = ventes validées (annulées exclues) − retours validés de la période ;
 * - encaissements nets = paiements reçus − remboursements ;
 * - marge estimée = montant net des lignes vendues − quantité × CMP figé à la vente, retours déduits ;
 *   les lignes sans coût connu sont exclues et comptées à part.
 */
class DashboardService
{
    /**
     * Indicateurs financiers d'une période (propriétaire).
     *
     * @return array<string, mixed>
     */
    public function financial(Carbon $from, Carbon $to): array
    {
        $sales = Sale::where('status', Sale::VALIDATED)->whereBetween('sold_at', [$from, $to]);
        $grossSales = $this->sum((clone $sales), 'total');
        $salesCount = (clone $sales)->count();

        $returns = SaleReturn::where('status', SaleReturn::VALIDATED)->whereBetween('decided_at', [$from, $to]);
        $returnsValue = $this->sum((clone $returns), 'total_value');

        $payments = $this->sum(SalePayment::whereBetween('paid_at', [$from, $to]), 'amount');
        $refunds = $this->sum(Refund::whereBetween('refunded_at', [$from, $to]), 'amount');
        $expenses = $this->sum(Expense::where('status', Expense::RECORDED)
            ->whereDate('expense_date', '>=', $from->toDateString())
            ->whereDate('expense_date', '<=', $to->toDateString()), 'amount');
        $losses = $this->sum(Loss::where('status', Loss::VALIDATED)->whereBetween('decided_at', [$from, $to]), 'estimated_value');

        // Marge : (montant net − quantité × coût figé) des lignes vendues, moins celle des lignes retournées.
        $soldMargin = DB::table('sale_lines')
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->where('sales.status', Sale::VALIDATED)
            ->whereBetween('sales.sold_at', [$from, $to])
            ->selectRaw('SUM(CASE WHEN unit_cost_snapshot IS NOT NULL THEN line_total - quantity * unit_cost_snapshot ELSE 0 END) AS margin')
            ->selectRaw('SUM(CASE WHEN unit_cost_snapshot IS NULL THEN 1 ELSE 0 END) AS without_cost')
            ->first();
        $returnedMargin = DB::table('sale_return_lines')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_lines.sale_return_id')
            ->join('sale_lines', 'sale_lines.id', '=', 'sale_return_lines.sale_line_id')
            ->where('sale_returns.status', SaleReturn::VALIDATED)
            ->whereBetween('sale_returns.decided_at', [$from, $to])
            ->whereNotNull('sale_lines.unit_cost_snapshot')
            ->selectRaw('SUM(sale_return_lines.value - sale_return_lines.quantity * sale_lines.unit_cost_snapshot) AS margin')
            ->value('margin');

        $netSales = bcsub($grossSales, $returnsValue, 2);

        return [
            'gross_sales' => $grossSales,
            'returns' => $returnsValue,
            'net_sales' => $netSales,
            'sales_count' => $salesCount,
            'average_basket' => $salesCount ? Decimal::round(bcdiv($grossSales, (string) $salesCount, 6), 2) : '0.00',
            'payments' => $payments,
            'refunds' => $refunds,
            'net_receipts' => bcsub($payments, $refunds, 2),
            'expenses' => $expenses,
            'losses' => $losses,
            'estimated_margin' => bcsub($this->money($soldMargin->margin ?? null), $this->money($returnedMargin), 2),
            'lines_without_cost' => (int) ($soldMargin->without_cost ?? 0),
            'daily_sales' => $this->dailySales($from, $to),
            'top_articles' => $this->topArticles($from, $to),
            // Dettes fournisseurs à date (toutes périodes) : reste à payer sur les acquisitions validées.
            'purchases_due' => $this->money(Purchase::whereNotIn('status', ['draft', 'cancelled'])->sum(DB::raw('total - paid_amount'))),
        ];
    }

    /**
     * Ventes du jour d'un caissier (sans autre donnée financière).
     *
     * @return array<string, mixed>
     */
    public function cashierToday(User $user): array
    {
        $sales = Sale::where('status', Sale::VALIDATED)->where('cashier_id', $user->id)->whereBetween('sold_at', [now()->startOfDay(), now()->endOfDay()]);

        return ['sales_count' => (clone $sales)->count(), 'sales_total' => $this->sum($sales, 'total')];
    }

    /**
     * Opérations en attente de décision.
     *
     * @return array<string, int>
     */
    public function pending(User $user, bool $seesAll): array
    {
        return [
            'returns' => SaleReturn::where('status', SaleReturn::PENDING)->when(! $seesAll, fn ($q) => $q->where('requested_by', $user->id))->count(),
            'losses' => Loss::where('status', Loss::PENDING)->when(! $seesAll, fn ($q) => $q->where('declared_by', $user->id))->count(),
            'inventories' => $seesAll ? Inventory::where('status', Inventory::IN_PROGRESS)->count() : 0,
            'purchases_to_receive' => $seesAll ? Purchase::whereIn('status', ['ordered', 'partially_received'])->count() : 0,
        ];
    }

    /**
     * Dernières opérations (ventes, réceptions, mouvements de stock).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function activity(User $user, bool $seesAll): array
    {
        return [
            'sales' => Sale::with(['cashier', 'customer'])
                ->when(! $seesAll, fn ($q) => $q->where('cashier_id', $user->id))
                ->latest('id')->limit(5)->get()
                ->map(fn (Sale $s) => [
                    'id' => $s->id, 'reference' => $s->reference, 'sold_at' => $s->sold_at->toIso8601String(), 'total' => $s->total,
                    'status' => $s->status, 'cashier' => $s->cashier->name, 'customer' => $s->customer?->name,
                ])->all(),
            'receipts' => $seesAll
                ? Receipt::with(['purchase.supplier', 'location'])->latest('id')->limit(5)->get()->map(fn (Receipt $r) => [
                    'id' => $r->id, 'reference' => $r->reference, 'purchase_id' => $r->purchase_id, 'received_at' => $r->received_at->toIso8601String(),
                    'supplier' => $r->purchase->supplier->name, 'location' => $r->location->name,
                ])->all()
                : [],
            'movements' => StockMovement::with(['article', 'location'])->latest('id')->limit(6)->get()->map(fn (StockMovement $m) => [
                'id' => $m->id, 'type_label' => $m->type->label(), 'quantity' => $m->quantity, 'created_at' => $m->created_at->toIso8601String(),
                'article' => $m->article->designation, 'location' => $m->location->name,
            ])->all(),
        ];
    }

    /**
     * Chiffre d'affaires par jour (jours sans vente inclus, à zéro).
     *
     * @return list<array{date: string, total: string, count: int}>
     */
    private function dailySales(Carbon $from, Carbon $to): array
    {
        $rows = Sale::where('status', Sale::VALIDATED)
            ->whereBetween('sold_at', [$from, $to])
            ->selectRaw('DATE(sold_at) AS day, SUM(total) AS total, COUNT(*) AS n')
            ->groupByRaw('DATE(sold_at)')
            ->get()
            ->keyBy(fn ($r) => substr((string) $r->day, 0, 10));

        $days = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($to) && count($days) < 366; $day->addDay()) {
            $key = $day->toDateString();
            $days[] = ['date' => $key, 'total' => $this->money($rows[$key]->total ?? null), 'count' => (int) ($rows[$key]->n ?? 0)];
        }

        return $days;
    }

    /**
     * Articles les plus vendus en montant.
     *
     * @return list<array<string, mixed>>
     */
    private function topArticles(Carbon $from, Carbon $to): array
    {
        return DB::table('sale_lines')
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->join('articles', 'articles.id', '=', 'sale_lines.article_id')
            ->where('sales.status', Sale::VALIDATED)
            ->whereBetween('sales.sold_at', [$from, $to])
            ->groupBy('articles.id', 'articles.designation', 'articles.unit')
            ->selectRaw('articles.id, articles.designation, articles.unit, SUM(sale_lines.quantity) AS quantity, SUM(sale_lines.line_total) AS total')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'designation' => $r->designation, 'unit' => $r->unit,
                'quantity' => Decimal::round((string) $r->quantity, 3), 'total' => $this->money($r->total),
            ])->all();
    }

    private function sum($query, string $column): string
    {
        return $this->money($query->sum($column));
    }

    private function money(mixed $value): string
    {
        return Decimal::round((string) ($value ?: '0'), 2);
    }
}
