<?php

namespace App\Reports;

use App\Enums\LossReason;
use App\Enums\MovementType;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Loss;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DashboardService;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rapports de gestion (cahier des charges §4.13), filtrables par période et, selon le rapport, par catégorie,
 * emplacement, caissier ou fournisseur. Seules les opérations validées sont prises en compte (TST-020) ;
 * la synthèse réutilise les calculs du tableau de bord pour que les chiffres concordent toujours.
 */
class ReportService
{
    /**
     * Catalogue : clé → titre, groupe, description, filtres proposés, sensibilité à la période.
     */
    private const CATALOG = [
        'summary' => ['Synthèse et résultat estimatif', 'Synthèse', 'Chiffre d’affaires, marge, dépenses, pertes et résultat estimatif de la période.', [], true],
        'sales_by_day' => ['Ventes par jour', 'Ventes', 'Nombre de ventes, remises, retours et chiffre d’affaires net jour par jour.', ['cashier_id'], true],
        'sales_by_article' => ['Ventes et marge par article', 'Ventes', 'Quantités vendues, chiffre d’affaires, coût et marge par article (avant retours).', ['category_id', 'cashier_id'], true],
        'sales_by_cashier' => ['Ventes par caissier', 'Ventes', 'Activité de chaque utilisateur à la caisse.', [], true],
        'payments_by_method' => ['Encaissements par mode de paiement', 'Finances', 'Montants encaissés et remboursés par mode de paiement.', [], true],
        'expenses' => ['Dépenses', 'Finances', 'Détail des dépenses enregistrées.', ['expense_category_id'], true],
        'purchases' => ['Achats de marchandises', 'Finances', 'Acquisitions validées, montants payés et soldes.', ['supplier_id'], true],
        'receivables' => ['Créances clients', 'Finances', 'Ventes à crédit restant à encaisser (à date).', [], false],
        'payables' => ['Dettes fournisseurs', 'Finances', 'Acquisitions restant à payer (à date).', ['supplier_id'], false],
        'stock_valuation' => ['Stock valorisé', 'Stock', 'Quantités et valeur au coût moyen pondéré (à date).', ['location_id', 'category_id'], false],
        'losses' => ['Pertes', 'Stock', 'Pertes validées sur la période, valorisées au CMP.', ['location_id', 'category_id'], true],
        'inventory_variances' => ['Écarts d’inventaire', 'Stock', 'Ajustements issus des inventaires validés, valorisés au CMP.', ['location_id'], true],
    ];

    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        return collect(self::CATALOG)->map(fn (array $r, string $key) => [
            'key' => $key, 'title' => $r[0], 'group' => $r[1], 'description' => $r[2], 'filters' => $r[3], 'periodic' => $r[4],
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters  from, to, category_id, location_id, cashier_id, supplier_id, expense_category_id
     */
    public function run(string $key, array $filters): Report
    {
        $definition = self::CATALOG[$key] ?? throw new BusinessException('Rapport inconnu.');
        $from = Carbon::parse($filters['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now())->endOfDay();
        $method = 'report'.str_replace('_', '', ucwords($key, '_'));

        /** @var Report $report */
        $report = $this->{$method}($from, $to, $filters);

        return new Report($key, $definition[0], $report->columns, $report->rows, $report->totals, $report->notes, $this->scope($definition, $from, $to, $filters));
    }

    // --- Synthèse ---

    private function reportSummary(Carbon $from, Carbon $to): Report
    {
        $f = $this->dashboard->financial($from, $to);
        $variances = $this->money(DB::table('stock_movements')
            ->where('type', MovementType::InventoryAdjustment->value)
            ->whereBetween('created_at', [$from, $to])
            ->sum(DB::raw('quantity * COALESCE(unit_cost, 0)')));
        $purchases = $this->money(Purchase::whereNotIn('status', ['draft', 'cancelled'])
            ->whereDate('purchase_date', '>=', $from->toDateString())->whereDate('purchase_date', '<=', $to->toDateString())->sum('total'));
        $receivables = $this->money(Sale::where('status', Sale::VALIDATED)->sum(DB::raw('total - amount_paid')));
        $cogs = bcsub($f['net_sales'], $f['estimated_margin'], 2);
        // Résultat estimatif = marge − dépenses − pertes + écarts d'inventaire (négatifs en cas de manquant).
        $result = bcadd(bcsub(bcsub($f['estimated_margin'], $f['expenses'], 2), $f['losses'], 2), $variances, 2);

        $row = fn (string $label, string $amount, string $kind = 'line') => ['label' => $label, 'amount' => $amount, '_kind' => $kind];

        return new Report('summary', '', [
            ['key' => 'label', 'label' => 'Indicateur', 'type' => 'text'],
            ['key' => 'amount', 'label' => 'Montant', 'type' => 'money'],
        ], [
            $row('Ventes validées (brut)', $f['gross_sales']),
            $row('Retours validés', bcmul($f['returns'], '-1', 2)),
            $row('Chiffre d’affaires net', $f['net_sales'], 'subtotal'),
            $row('Coût des marchandises vendues (CMP)', bcmul($cogs, '-1', 2)),
            $row('Marge commerciale estimée', $f['estimated_margin'], 'subtotal'),
            $row('Dépenses d’exploitation', bcmul($f['expenses'], '-1', 2)),
            $row('Pertes validées', bcmul($f['losses'], '-1', 2)),
            $row('Écarts d’inventaire', $variances),
            $row('Résultat estimatif', $result, 'total'),
            $row('Encaissements nets (reçus − remboursés)', $f['net_receipts'], 'info'),
            $row('Achats de marchandises validés', $purchases, 'info'),
            $row('Créances clients (à date)', $receivables, 'info'),
            $row('Dettes fournisseurs (à date)', $f['purchases_due'], 'info'),
        ], [], array_values(array_filter([
            'Indicateurs de gestion, non comptables. Chiffre d’affaires net = ventes validées − retours validés ; marge = ventes − coût moyen pondéré figé à la vente, retours déduits.',
            'Résultat estimatif = marge − dépenses − pertes ± écarts d’inventaire. Les achats de marchandises ne sont pas des charges : ils entrent dans le stock.',
            $f['lines_without_cost'] ? "{$f['lines_without_cost']} ligne(s) de vente sans coût connu sont exclues de la marge." : null,
        ])));
    }

    // --- Ventes ---

    private function reportSalesByDay(Carbon $from, Carbon $to, array $filters): Report
    {
        $sales = $this->validatedSales($from, $to, $filters)
            ->selectRaw('DATE(sold_at) AS day, COUNT(*) AS n, SUM(subtotal) AS subtotal, SUM(discount_total) AS discounts, SUM(total) AS total')
            ->groupByRaw('DATE(sold_at)')
            ->get()->keyBy(fn ($r) => substr((string) $r->day, 0, 10));
        $returns = DB::table('sale_returns')
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->where('sale_returns.status', SaleReturn::VALIDATED)
            ->whereBetween('sale_returns.decided_at', [$from, $to])
            ->when($filters['cashier_id'] ?? null, fn (Builder $q, $id) => $q->where('sales.cashier_id', $id))
            ->selectRaw('DATE(sale_returns.decided_at) AS day, SUM(sale_returns.total_value) AS total')
            ->groupByRaw('DATE(sale_returns.decided_at)')
            ->pluck('total', 'day')
            ->mapWithKeys(fn ($v, $k) => [substr((string) $k, 0, 10) => $v]);

        $rows = [];
        foreach ($sales->keys()->merge($returns->keys())->unique()->sort() as $day) {
            $s = $sales[$day] ?? null;
            $ret = $this->money($returns[$day] ?? null);
            $total = $this->money($s->total ?? null);
            $rows[] = [
                'date' => $day,
                'count' => (int) ($s->n ?? 0),
                'subtotal' => $this->money($s->subtotal ?? null),
                'discounts' => $this->money($s->discounts ?? null),
                'total' => $total,
                'returns' => $ret,
                'net' => bcsub($total, $ret, 2),
            ];
        }

        return new Report('sales_by_day', '', [
            ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'count', 'label' => 'Ventes', 'type' => 'int'],
            ['key' => 'subtotal', 'label' => 'Avant remises', 'type' => 'money'],
            ['key' => 'discounts', 'label' => 'Remises', 'type' => 'money'],
            ['key' => 'total', 'label' => 'Ventes', 'type' => 'money'],
            ['key' => 'returns', 'label' => 'Retours', 'type' => 'money'],
            ['key' => 'net', 'label' => 'CA net', 'type' => 'money'],
        ], $rows, $this->totals($rows, ['count', 'subtotal', 'discounts', 'total', 'returns', 'net']), ['Ventes annulées exclues ; retours comptés le jour de leur validation.']);
    }

    private function reportSalesByArticle(Carbon $from, Carbon $to, array $filters): Report
    {
        $rows = DB::table('sale_lines')
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->join('articles', 'articles.id', '=', 'sale_lines.article_id')
            ->leftJoin('categories', 'categories.id', '=', 'articles.category_id')
            ->where('sales.status', Sale::VALIDATED)
            ->whereBetween('sales.sold_at', [$from, $to])
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('articles.category_id', $id))
            ->when($filters['cashier_id'] ?? null, fn (Builder $q, $id) => $q->where('sales.cashier_id', $id))
            ->groupBy('articles.id', 'articles.reference', 'articles.designation', 'articles.unit', 'categories.name')
            ->selectRaw('articles.reference, articles.designation, articles.unit, categories.name AS category')
            ->selectRaw('SUM(sale_lines.quantity) AS quantity, SUM(sale_lines.line_total) AS revenue')
            ->selectRaw('SUM(CASE WHEN sale_lines.unit_cost_snapshot IS NULL THEN 0 ELSE sale_lines.quantity * sale_lines.unit_cost_snapshot END) AS cost')
            ->orderByDesc('revenue')
            ->get()
            ->map(function ($r) {
                $revenue = $this->money($r->revenue);
                $margin = bcsub($revenue, $this->money($r->cost), 2);

                return [
                    'reference' => $r->reference,
                    'designation' => $r->designation,
                    'category' => $r->category ?? '—',
                    'quantity' => Decimal::round((string) $r->quantity, 3),
                    'unit' => $r->unit,
                    'revenue' => $revenue,
                    'cost' => $this->money($r->cost),
                    'margin' => $margin,
                    'margin_rate' => bccomp($revenue, '0', 2) > 0 ? Decimal::round(bcdiv($margin, $revenue, 8), 4) : null,
                ];
            })->all();

        $totals = $this->totals($rows, ['revenue', 'cost', 'margin']);
        $totals['margin_rate'] = bccomp($totals['revenue'] ?? '0', '0', 2) > 0 ? Decimal::round(bcdiv($totals['margin'], $totals['revenue'], 8), 4) : null;

        return new Report('sales_by_article', '', [
            ['key' => 'reference', 'label' => 'Référence', 'type' => 'text'],
            ['key' => 'designation', 'label' => 'Article', 'type' => 'text'],
            ['key' => 'category', 'label' => 'Catégorie', 'type' => 'text'],
            ['key' => 'quantity', 'label' => 'Quantité', 'type' => 'quantity'],
            ['key' => 'unit', 'label' => 'Unité', 'type' => 'text'],
            ['key' => 'revenue', 'label' => 'Chiffre d’affaires', 'type' => 'money'],
            ['key' => 'cost', 'label' => 'Coût (CMP)', 'type' => 'money'],
            ['key' => 'margin', 'label' => 'Marge', 'type' => 'money'],
            ['key' => 'margin_rate', 'label' => 'Taux de marge', 'type' => 'percent'],
        ], $rows, $totals, ['Avant retours. Coût = coût moyen pondéré figé au moment de chaque vente.']);
    }

    private function reportSalesByCashier(Carbon $from, Carbon $to): Report
    {
        $rows = DB::table('sales')
            ->join('users', 'users.id', '=', 'sales.cashier_id')
            ->whereBetween('sales.sold_at', [$from, $to])
            ->groupBy('users.id', 'users.name')
            ->selectRaw('users.name')
            ->selectRaw("SUM(CASE WHEN sales.status = 'validated' THEN 1 ELSE 0 END) AS n")
            ->selectRaw("SUM(CASE WHEN sales.status = 'validated' THEN sales.total ELSE 0 END) AS total")
            ->selectRaw("SUM(CASE WHEN sales.status = 'validated' THEN sales.discount_total ELSE 0 END) AS discounts")
            ->selectRaw("SUM(CASE WHEN sales.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled")
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'cashier' => $r->name,
                'count' => (int) $r->n,
                'total' => $this->money($r->total),
                'discounts' => $this->money($r->discounts),
                'average' => (int) $r->n ? Decimal::round(bcdiv($this->money($r->total), (string) $r->n, 6), 2) : '0.00',
                'cancelled' => (int) $r->cancelled,
            ])->all();

        return new Report('sales_by_cashier', '', [
            ['key' => 'cashier', 'label' => 'Utilisateur', 'type' => 'text'],
            ['key' => 'count', 'label' => 'Ventes', 'type' => 'int'],
            ['key' => 'total', 'label' => 'Montant', 'type' => 'money'],
            ['key' => 'discounts', 'label' => 'Remises accordées', 'type' => 'money'],
            ['key' => 'average', 'label' => 'Panier moyen', 'type' => 'money'],
            ['key' => 'cancelled', 'label' => 'Ventes annulées', 'type' => 'int'],
        ], $rows, $this->totals($rows, ['count', 'total', 'discounts', 'cancelled']));
    }

    // --- Finances ---

    private function reportPaymentsByMethod(Carbon $from, Carbon $to): Report
    {
        $in = DB::table('sale_payments')->whereBetween('paid_at', [$from, $to])->groupBy('payment_method_id')->selectRaw('payment_method_id, SUM(amount) AS t')->pluck('t', 'payment_method_id');
        $out = DB::table('refunds')->whereBetween('refunded_at', [$from, $to])->groupBy('payment_method_id')->selectRaw('payment_method_id, SUM(amount) AS t')->pluck('t', 'payment_method_id');

        $rows = DB::table('payment_methods')->orderBy('id')->get()
            ->filter(fn ($m) => isset($in[$m->id]) || isset($out[$m->id]))
            ->map(fn ($m) => [
                'method' => $m->name,
                'received' => $this->money($in[$m->id] ?? null),
                'refunded' => $this->money($out[$m->id] ?? null),
                'net' => bcsub($this->money($in[$m->id] ?? null), $this->money($out[$m->id] ?? null), 2),
            ])->values()->all();

        return new Report('payments_by_method', '', [
            ['key' => 'method', 'label' => 'Mode de paiement', 'type' => 'text'],
            ['key' => 'received', 'label' => 'Encaissé', 'type' => 'money'],
            ['key' => 'refunded', 'label' => 'Remboursé', 'type' => 'money'],
            ['key' => 'net', 'label' => 'Net', 'type' => 'money'],
        ], $rows, $this->totals($rows, ['received', 'refunded', 'net']), ['Paiements des ventes (monnaie rendue déduite) et remboursements des retours et annulations.']);
    }

    private function reportExpenses(Carbon $from, Carbon $to, array $filters): Report
    {
        $rows = Expense::with(['category', 'method'])
            ->where('status', Expense::RECORDED)
            ->whereDate('expense_date', '>=', $from->toDateString())
            ->whereDate('expense_date', '<=', $to->toDateString())
            ->when($filters['expense_category_id'] ?? null, fn ($q, $id) => $q->where('expense_category_id', $id))
            ->orderBy('expense_date')->orderBy('id')
            ->get()
            ->map(fn (Expense $e) => [
                'date' => $e->expense_date->toDateString(),
                'reference' => $e->reference,
                'label' => $e->label,
                'category' => $e->category->name,
                'beneficiary' => $e->beneficiary ?? '',
                'method' => $e->method->name,
                'amount' => $e->amount,
            ])->all();

        return new Report('expenses', '', [
            ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'reference', 'label' => 'Référence', 'type' => 'text'],
            ['key' => 'label', 'label' => 'Libellé', 'type' => 'text'],
            ['key' => 'category', 'label' => 'Catégorie', 'type' => 'text'],
            ['key' => 'beneficiary', 'label' => 'Bénéficiaire', 'type' => 'text'],
            ['key' => 'method', 'label' => 'Mode', 'type' => 'text'],
            ['key' => 'amount', 'label' => 'Montant', 'type' => 'money'],
        ], $rows, $this->totals($rows, ['amount']), ['Dépenses annulées exclues.']);
    }

    private function reportPurchases(Carbon $from, Carbon $to, array $filters): Report
    {
        $rows = $this->purchaseRows(Purchase::whereNotIn('status', ['draft', 'cancelled'])
            ->whereDate('purchase_date', '>=', $from->toDateString())
            ->whereDate('purchase_date', '<=', $to->toDateString())
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id)));

        return new Report('purchases', '', $this->purchaseColumns(true), $rows, $this->totals($rows, ['total', 'paid', 'balance']), ['Brouillons et acquisitions annulées exclus.']);
    }

    private function reportPayables(Carbon $from, Carbon $to, array $filters): Report
    {
        $rows = $this->purchaseRows(Purchase::whereNotIn('status', ['draft', 'cancelled'])
            ->whereColumn('paid_amount', '<', 'total')
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id)));

        return new Report('payables', '', $this->purchaseColumns(false), $rows, $this->totals($rows, ['total', 'paid', 'balance']));
    }

    private function reportReceivables(): Report
    {
        $rows = Sale::with('customer')
            ->where('status', Sale::VALIDATED)
            ->whereColumn('amount_paid', '<', 'total')
            ->orderBy('sold_at')
            ->get()
            ->map(fn (Sale $s) => [
                'date' => $s->sold_at->toDateString(),
                'reference' => $s->reference,
                'customer' => $s->customer?->name ?? '—',
                'phone' => $s->customer?->phone ?? '',
                'total' => $s->total,
                'paid' => $s->amount_paid,
                'balance' => $s->balance_due,
            ])->all();

        return new Report('receivables', '', [
            ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'reference', 'label' => 'Vente', 'type' => 'text'],
            ['key' => 'customer', 'label' => 'Client', 'type' => 'text'],
            ['key' => 'phone', 'label' => 'Téléphone', 'type' => 'text'],
            ['key' => 'total', 'label' => 'Total', 'type' => 'money'],
            ['key' => 'paid', 'label' => 'Payé', 'type' => 'money'],
            ['key' => 'balance', 'label' => 'Reste dû', 'type' => 'money'],
        ], $rows, $this->totals($rows, ['total', 'paid', 'balance']));
    }

    // --- Stock ---

    private function reportStockValuation(Carbon $from, Carbon $to, array $filters): Report
    {
        $rows = DB::table('stock_balances')
            ->join('articles', 'articles.id', '=', 'stock_balances.article_id')
            ->leftJoin('categories', 'categories.id', '=', 'articles.category_id')
            ->when($filters['location_id'] ?? null, fn (Builder $q, $id) => $q->where('stock_balances.location_id', $id))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('articles.category_id', $id))
            ->groupBy('articles.id', 'articles.reference', 'articles.designation', 'articles.unit', 'articles.average_cost', 'categories.name')
            ->havingRaw('SUM(stock_balances.quantity_on_hand) <> 0')
            ->selectRaw('articles.reference, articles.designation, articles.unit, articles.average_cost, categories.name AS category, SUM(stock_balances.quantity_on_hand) AS quantity')
            ->orderBy('articles.designation')
            ->get()
            ->map(function ($r) {
                $quantity = Decimal::round((string) $r->quantity, 3);

                return [
                    'reference' => $r->reference,
                    'designation' => $r->designation,
                    'category' => $r->category ?? '—',
                    'quantity' => $quantity,
                    'unit' => $r->unit,
                    'average_cost' => $r->average_cost === null ? null : Decimal::round((string) $r->average_cost, 2),
                    'value' => $r->average_cost === null ? null : Decimal::round(bcmul($quantity, (string) $r->average_cost, 8), 2),
                ];
            })->all();

        $withoutCost = count(array_filter($rows, fn ($r) => $r['value'] === null));

        return new Report('stock_valuation', '', [
            ['key' => 'reference', 'label' => 'Référence', 'type' => 'text'],
            ['key' => 'designation', 'label' => 'Article', 'type' => 'text'],
            ['key' => 'category', 'label' => 'Catégorie', 'type' => 'text'],
            ['key' => 'quantity', 'label' => 'Quantité', 'type' => 'quantity'],
            ['key' => 'unit', 'label' => 'Unité', 'type' => 'text'],
            ['key' => 'average_cost', 'label' => 'CMP', 'type' => 'money'],
            ['key' => 'value', 'label' => 'Valeur', 'type' => 'money'],
        ], $rows, $this->totals($rows, ['value']), array_values(array_filter([
            'Quantités à date (la période ne s’applique pas). Valeur = quantité × coût moyen pondéré actuel.',
            $withoutCost ? "{$withoutCost} article(s) sans coût connu : valeur non calculée." : null,
        ])));
    }

    private function reportLosses(Carbon $from, Carbon $to, array $filters): Report
    {
        $rows = Loss::with(['article', 'location'])
            ->where('status', Loss::VALIDATED)
            ->whereBetween('decided_at', [$from, $to])
            ->when($filters['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', $id))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->whereHas('article', fn ($a) => $a->where('category_id', $id)))
            ->orderBy('decided_at')
            ->get()
            ->map(fn (Loss $l) => [
                'date' => $l->decided_at->toDateString(),
                'reference' => $l->reference,
                'designation' => $l->article->designation,
                'location' => $l->location->name,
                'reason' => $l->reason instanceof LossReason ? $l->reason->label() : (string) $l->reason,
                'quantity' => $l->quantity,
                'value' => $l->estimated_value,
            ])->all();

        return new Report('losses', '', [
            ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'reference', 'label' => 'Référence', 'type' => 'text'],
            ['key' => 'designation', 'label' => 'Article', 'type' => 'text'],
            ['key' => 'location', 'label' => 'Emplacement', 'type' => 'text'],
            ['key' => 'reason', 'label' => 'Motif', 'type' => 'text'],
            ['key' => 'quantity', 'label' => 'Quantité', 'type' => 'quantity'],
            ['key' => 'value', 'label' => 'Valeur (CMP)', 'type' => 'money'],
        ], $rows, $this->totals($rows, ['value']));
    }

    private function reportInventoryVariances(Carbon $from, Carbon $to, array $filters): Report
    {
        $rows = DB::table('stock_movements')
            ->join('articles', 'articles.id', '=', 'stock_movements.article_id')
            ->join('locations', 'locations.id', '=', 'stock_movements.location_id')
            ->join('inventories', function ($j) {
                $j->on('inventories.id', '=', 'stock_movements.source_id')->where('stock_movements.source_type', (new Inventory)->getMorphClass());
            })
            ->where('stock_movements.type', MovementType::InventoryAdjustment->value)
            ->whereBetween('stock_movements.created_at', [$from, $to])
            ->when($filters['location_id'] ?? null, fn (Builder $q, $id) => $q->where('stock_movements.location_id', $id))
            ->orderBy('stock_movements.id')
            ->selectRaw('stock_movements.created_at, inventories.reference, locations.name AS location, articles.designation, stock_movements.quantity, stock_movements.unit_cost')
            ->get()
            ->map(fn ($r) => [
                'date' => substr((string) $r->created_at, 0, 10),
                'reference' => $r->reference,
                'location' => $r->location,
                'designation' => $r->designation,
                'variance' => Decimal::round((string) $r->quantity, 3),
                'unit_cost' => $r->unit_cost === null ? null : Decimal::round((string) $r->unit_cost, 2),
                'value' => $r->unit_cost === null ? null : Decimal::round(bcmul((string) $r->quantity, (string) $r->unit_cost, 8), 2),
            ])->all();

        return new Report('inventory_variances', '', [
            ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'reference', 'label' => 'Inventaire', 'type' => 'text'],
            ['key' => 'location', 'label' => 'Emplacement', 'type' => 'text'],
            ['key' => 'designation', 'label' => 'Article', 'type' => 'text'],
            ['key' => 'variance', 'label' => 'Écart', 'type' => 'quantity'],
            ['key' => 'unit_cost', 'label' => 'CMP', 'type' => 'money'],
            ['key' => 'value', 'label' => 'Valeur', 'type' => 'money'],
        ], $rows, $this->totals($rows, ['value']), ['Écart négatif = manquant, positif = surplus.']);
    }

    // --- Outils ---

    private function validatedSales(Carbon $from, Carbon $to, array $filters): Builder
    {
        return DB::table('sales')
            ->where('status', Sale::VALIDATED)
            ->whereBetween('sold_at', [$from, $to])
            ->when($filters['cashier_id'] ?? null, fn (Builder $q, $id) => $q->where('cashier_id', $id));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function purchaseRows($query): array
    {
        return $query->with('supplier')->orderBy('purchase_date')->orderBy('id')->get()->map(fn (Purchase $p) => [
            'date' => $p->purchase_date->toDateString(),
            'reference' => $p->reference,
            'supplier' => $p->supplier->name,
            'status' => $p->status->label(),
            'total' => $p->total,
            'paid' => $p->paid_amount,
            'balance' => $p->balance_due,
        ])->all();
    }

    /**
     * @return list<array{key: string, label: string, type: string}>
     */
    private function purchaseColumns(bool $withStatus): array
    {
        return array_values(array_filter([
            ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'reference', 'label' => 'Acquisition', 'type' => 'text'],
            ['key' => 'supplier', 'label' => 'Fournisseur', 'type' => 'text'],
            $withStatus ? ['key' => 'status', 'label' => 'Statut', 'type' => 'text'] : null,
            ['key' => 'total', 'label' => 'Total', 'type' => 'money'],
            ['key' => 'paid', 'label' => 'Payé', 'type' => 'money'],
            ['key' => 'balance', 'label' => 'Solde dû', 'type' => 'money'],
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function totals(array $rows, array $keys): array
    {
        $totals = [];
        foreach ($keys as $key) {
            $totals[$key] = array_reduce($rows, function ($sum, $row) use ($key) {
                $v = $row[$key] ?? null;
                if ($v === null) {
                    return $sum;
                }

                return is_int($v) ? $sum + $v : bcadd((string) $sum, (string) $v, 3);
            }, 0);
            if (is_string($totals[$key])) {
                $totals[$key] = Decimal::round($totals[$key], 2);
            }
        }

        return $totals;
    }

    private function money(mixed $value): string
    {
        return Decimal::round((string) ($value ?: '0'), 2);
    }

    /**
     * Description lisible de la période et des filtres (en-tête des exports).
     */
    private function scope(array $definition, Carbon $from, Carbon $to, array $filters): string
    {
        $parts = [$definition[4]
            ? 'Du '.$from->locale('fr')->isoFormat('D MMMM YYYY').' au '.$to->locale('fr')->isoFormat('D MMMM YYYY')
            : 'Situation au '.now()->locale('fr')->isoFormat('D MMMM YYYY')];

        $names = [
            'category_id' => fn ($id) => 'Catégorie : '.Category::find($id)?->name,
            'location_id' => fn ($id) => 'Emplacement : '.Location::find($id)?->name,
            'cashier_id' => fn ($id) => 'Utilisateur : '.User::find($id)?->name,
            'supplier_id' => fn ($id) => 'Fournisseur : '.Supplier::find($id)?->name,
            'expense_category_id' => fn ($id) => 'Catégorie : '.ExpenseCategory::find($id)?->name,
        ];
        foreach ($definition[3] as $filter) {
            if (! empty($filters[$filter])) {
                $parts[] = $names[$filter]($filters[$filter]);
            }
        }

        return implode(' · ', $parts);
    }
}
