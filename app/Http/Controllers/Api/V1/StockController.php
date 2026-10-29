<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MovementType;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStockAdjustmentRequest;
use App\Http\Resources\StockBalanceResource;
use App\Http\Resources\StockMovementResource;
use App\Models\Article;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\AuditLogger;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockController extends Controller
{
    public function __construct(
        private readonly StockService $stock,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Stock par article et emplacement (SFD-STK : consulter le stock).
     */
    public function balances(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'article_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'low' => ['nullable', 'boolean'],
        ]);

        $balances = StockBalance::query()
            ->with(['article', 'location'])
            ->join('articles', 'articles.id', '=', 'stock_balances.article_id')
            ->select('stock_balances.*')
            ->when($filters['article_id'] ?? null, fn (Builder $q, $id) => $q->where('stock_balances.article_id', $id))
            ->when($filters['location_id'] ?? null, fn (Builder $q, $id) => $q->where('stock_balances.location_id', $id))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw('LOWER(articles.designation) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(articles.reference) LIKE ?', [$like])
                    ->orWhere('articles.barcode', $search));
            })
            ->when($request->boolean('low'), fn (Builder $q) => $q->whereColumn('stock_balances.quantity_on_hand', '<=', 'articles.alert_threshold'))
            ->orderBy('articles.designation')
            ->orderBy('stock_balances.location_id')
            ->paginate(50);

        return StockBalanceResource::collection($balances);
    }

    /**
     * Historique des mouvements, du plus récent au plus ancien.
     */
    public function movements(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'article_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::enum(MovementType::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $movements = StockMovement::query()
            ->with(['article', 'location', 'creator', 'source'])
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $q->whereHas('article', fn (Builder $a) => $a->whereRaw('LOWER(designation) LIKE ?', [$like])->orWhereRaw('LOWER(reference) LIKE ?', [$like]));
            })
            ->when($filters['article_id'] ?? null, fn (Builder $q, $id) => $q->where('article_id', $id))
            ->when($filters['location_id'] ?? null, fn (Builder $q, $id) => $q->where('location_id', $id))
            ->when($filters['type'] ?? null, fn (Builder $q, $type) => $q->where('type', $type))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->where('created_at', '<', Carbon::parse($to)->addDay()->startOfDay()))
            ->orderByDesc('id')
            ->paginate(50);

        return StockMovementResource::collection($movements);
    }

    /**
     * Synthèse : articles suivis, ruptures, stock faible ; valeur du stock au CMP pour le propriétaire.
     */
    public function summary(Request $request): JsonResponse
    {
        $totals = DB::table('articles')
            ->leftJoinSub(
                DB::table('stock_balances')->selectRaw('article_id, SUM(quantity_on_hand) AS total')->groupBy('article_id'),
                'stock',
                'stock.article_id',
                '=',
                'articles.id',
            )
            ->where('articles.active', true)
            ->selectRaw('COUNT(*) AS articles')
            ->selectRaw('SUM(CASE WHEN COALESCE(stock.total, 0) <= 0 THEN 1 ELSE 0 END) AS out_of_stock')
            ->selectRaw('SUM(CASE WHEN COALESCE(stock.total, 0) > 0 AND COALESCE(stock.total, 0) <= articles.alert_threshold THEN 1 ELSE 0 END) AS low_stock')
            ->first();

        $data = [
            'articles' => (int) $totals->articles,
            'out_of_stock' => (int) $totals->out_of_stock,
            'low_stock' => (int) $totals->low_stock,
        ];

        // Valeur au coût moyen pondéré, par emplacement : réservée au propriétaire.
        if ($request->user()->hasPermission(Permission::PricesManage)) {
            $byLocation = DB::table('stock_balances')
                ->join('articles', 'articles.id', '=', 'stock_balances.article_id')
                ->where('stock_balances.quantity_on_hand', '>', 0)
                ->groupBy('stock_balances.location_id')
                ->selectRaw('stock_balances.location_id, SUM(stock_balances.quantity_on_hand * COALESCE(articles.average_cost, 0)) AS value')
                ->selectRaw('SUM(CASE WHEN articles.average_cost IS NULL THEN 1 ELSE 0 END) AS without_cost')
                ->get()
                ->keyBy('location_id');

            $total = '0.00';
            $data['value_by_location'] = Location::activeInOrder()->map(function (Location $l) use ($byLocation, &$total) {
                $value = Decimal::round((string) ($byLocation[$l->id]->value ?? '0'), 2);
                $total = bcadd($total, $value, 2);

                return ['location_id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'value' => $value];
            })->values();
            $data['stock_value'] = $total;
            // Lignes de stock sans coût connu (stock initial saisi sans coût) : valeur sous-estimée.
            $data['balances_without_cost'] = (int) $byLocation->sum('without_cost');
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Correction manuelle (stock initial, erreur de saisie…) : motif obligatoire, tracée dans l'audit.
     */
    public function adjust(StoreStockAdjustmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Une entrée sans coût sur un article sans coût connu fausserait la valeur du stock et la marge.
        if (bccomp((string) $data['quantity'], '0', 3) > 0 && ! isset($data['unit_cost'])
            && Article::whereKey($data['article_id'])->value('average_cost') === null) {
            throw ValidationException::withMessages([
                'unit_cost' => 'Indiquez le coût unitaire (prix d’achat) : cet article n’a pas encore de coût connu.',
            ]);
        }

        $movement = DB::transaction(function () use ($request, $data) {
            $movement = $this->stock->apply(
                [new StockChange(
                    (int) $data['article_id'],
                    (int) $data['location_id'],
                    MovementType::Adjustment,
                    (string) $data['quantity'],
                    isset($data['unit_cost']) ? (string) $data['unit_cost'] : null,
                )],
                $request->user(),
                reason: $data['reason'],
            )->sole();

            $this->audit->log('stock.adjusted', $movement, after: [
                'article_id' => $movement->article_id,
                'location_id' => $movement->location_id,
                'quantity' => $movement->quantity,
                'balance_after' => $movement->balance_after,
            ], reason: $data['reason']);

            return $movement;
        });

        return (new StockMovementResource($movement->load(['article', 'location', 'creator'])))
            ->response()
            ->setStatusCode(201);
    }
}
