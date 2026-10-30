<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ArticleRequest;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Models\Location;
use App\Models\Loss;
use App\Models\SaleLine;
use App\Models\StockMovement;
use App\Services\AuditLogger;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue des articles (SFD-ART). Pas de suppression : un article est désactivé (active = false).
 */
class ArticleController extends Controller
{
    private const AUDITED = [
        'reference', 'barcode', 'designation', 'category_id', 'brand', 'model', 'unit', 'allow_decimal_quantity',
        'purchase_price_reference', 'sale_price', 'alert_threshold', 'serial_tracking', 'active',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive,all'],
            'low' => ['nullable', 'boolean'],
            'out' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $articles = Article::query()
            ->with(['category', 'stockBalances'])
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw('LOWER(designation) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(reference) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(brand) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(model) LIKE ?', [$like])
                    ->orWhere('barcode', $search));
            })
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('category_id', $id))
            ->when(($filters['status'] ?? 'active') !== 'all', fn (Builder $q) => $q->where('active', ($filters['status'] ?? 'active') === 'active'))
            // Stock total (tous emplacements) inférieur ou égal au seuil d'alerte.
            ->when($request->boolean('low'), fn (Builder $q) => $q->whereRaw(
                '(SELECT COALESCE(SUM(quantity_on_hand), 0) FROM stock_balances WHERE stock_balances.article_id = articles.id) <= articles.alert_threshold'
            ))
            // Rupture : plus aucun stock, tous emplacements confondus.
            ->when($request->boolean('out'), fn (Builder $q) => $q->whereRaw(
                '(SELECT COALESCE(SUM(quantity_on_hand), 0) FROM stock_balances WHERE stock_balances.article_id = articles.id) <= 0'
            ))
            ->orderBy('designation')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        return ArticleResource::collection($articles)->additional([
            'locations' => Location::activeInOrder()->map(fn (Location $l) => [
                'id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'is_sellable' => $l->is_sellable,
            ])->values(),
        ]);
    }

    public function show(Article $article): ArticleResource
    {
        return new ArticleResource($article->load(['category', 'stockBalances']));
    }

    /**
     * Recherche exacte par code-barres (scan) : l'article connu, ou 404 « code inconnu ».
     */
    public function byBarcode(string $barcode): ArticleResource|JsonResponse
    {
        $article = Article::with(['category', 'stockBalances'])->where('barcode', trim($barcode))->first();

        return $article
            ? new ArticleResource($article)
            : response()->json(['message' => "Code-barres inconnu : {$barcode}."], 404);
    }

    public function store(ArticleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['unit'] ??= 'pièce';

        $article = DB::transaction(function () use ($data) {
            $article = Article::create($data)->refresh();
            $this->audit->log('article.created', $article, after: Arr::only($data, self::AUDITED));

            return $article;
        });

        return (new ArticleResource($article->load(['category', 'stockBalances'])))->response()->setStatusCode(201);
    }

    public function update(ArticleRequest $request, Article $article): ArticleResource|JsonResponse
    {
        $data = $request->validated();
        if (array_key_exists('unit', $data)) {
            $data['unit'] ??= 'pièce';
        }

        $refusal = DB::transaction(function () use ($article, $data) {
            // Un article ne peut repasser « à l'unité » s'il reste du stock fractionnaire.
            if (($data['allow_decimal_quantity'] ?? true) === false && $article->allow_decimal_quantity) {
                $fractional = $article->stockBalances()->lockForUpdate()->get()
                    ->contains(fn ($b) => ! Decimal::isInteger($b->quantity_on_hand));
                if ($fractional) {
                    return 'Impossible de passer cet article à l’unité : une partie du stock est en quantité décimale.';
                }
            }

            $before = Arr::only($article->toArray(), self::AUDITED);
            $article->update($data);

            $changed = array_keys($article->getChanges());
            if (array_intersect($changed, self::AUDITED)) {
                $this->audit->log('article.updated', $article, before: Arr::only($before, $changed), after: Arr::only($data, $changed));
            }

            return null;
        });

        if ($refusal) {
            return response()->json(['message' => $refusal], 422);
        }

        return new ArticleResource($article->load(['category', 'stockBalances']));
    }

    /**
     * Définit le coût moyen (CMP) d'un article, typiquement entré en stock sans coût.
     * Les opérations passées restées sans coût (mouvements, lignes de vente, pertes) reçoivent ce coût :
     * valeur du stock, marges et coût des pertes deviennent calculables. Les coûts déjà connus ne changent pas.
     */
    public function setCost(Request $request, Article $article): ArticleResource
    {
        $data = $request->validate([
            'average_cost' => ['required', 'decimal:0,4', 'gt:0', 'max:999999999'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'average_cost.required' => 'Indiquez le coût unitaire.',
            'average_cost.gt' => 'Le coût doit être positif.',
            'average_cost.decimal' => 'Le coût a au plus 4 décimales.',
            'reason.required' => 'Le motif est obligatoire.',
            'reason.min' => 'Le motif doit contenir au moins 3 caractères.',
        ]);
        $cost = Decimal::normalize((string) $data['average_cost'], Decimal::COST_SCALE);

        DB::transaction(function () use ($article, $cost, $data) {
            // Verrou de l'article, comme StockService : aucun mouvement ne recalcule le CMP en même temps.
            $locked = Article::lockForUpdate()->findOrFail($article->id);
            $before = $locked->average_cost;
            $locked->forceFill(['average_cost' => $cost])->save();

            $filled = [
                'movements' => StockMovement::where('article_id', $locked->id)->whereNull('unit_cost')->update(['unit_cost' => $cost]),
                'sale_lines' => SaleLine::where('article_id', $locked->id)->whereNull('unit_cost_snapshot')->update(['unit_cost_snapshot' => $cost]),
                'losses' => 0,
            ];
            foreach (Loss::where('article_id', $locked->id)->whereNull('estimated_value')->get() as $loss) {
                $loss->forceFill(['estimated_value' => Decimal::round(bcmul($loss->quantity, $cost, 8), 2)])->save();
                $filled['losses']++;
            }

            $this->audit->log('article.cost_set', $locked, before: ['average_cost' => $before], after: ['average_cost' => $cost] + $filled, reason: $data['reason']);
        });

        return new ArticleResource($article->refresh()->load(['category', 'stockBalances']));
    }
}
