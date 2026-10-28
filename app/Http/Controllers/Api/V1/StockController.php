<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStockAdjustmentRequest;
use App\Http\Resources\StockBalanceResource;
use App\Http\Resources\StockMovementResource;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\AuditLogger;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $movements = StockMovement::query()
            ->with(['article', 'location', 'creator'])
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
     * Correction manuelle (stock initial, erreur de saisie…) : motif obligatoire, tracée dans l'audit.
     */
    public function adjust(StoreStockAdjustmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $movement = DB::transaction(function () use ($request, $data) {
            $movement = $this->stock->apply(
                [new StockChange(
                    (int) $data['article_id'],
                    (int) $data['location_id'],
                    MovementType::Adjustment,
                    (int) $data['quantity'],
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
