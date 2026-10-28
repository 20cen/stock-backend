<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\SaleResource;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Ventes. Le caissier ne voit que les siennes (sales.view_own) ; le propriétaire voit tout (sales.view_all).
 */
class SaleController extends Controller
{
    private const DETAIL = ['cashier', 'customer', 'location', 'cashSession', 'lines.article', 'payments.method'];

    public function __construct(private readonly SaleService $sales) {}

    public function config(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->sales->config($request->user())]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'cash_session_id' => ['nullable', 'integer'],
            'cashier_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $viewAll = $request->user()->hasPermission(Permission::SalesViewAll);

        $sales = Sale::query()
            ->with(['cashier', 'customer'])
            ->withCount('lines')
            ->when(! $viewAll, fn (Builder $q) => $q->where('cashier_id', $request->user()->id))
            ->when($viewAll && ($filters['cashier_id'] ?? null), fn (Builder $q) => $q->where('cashier_id', $filters['cashier_id']))
            ->when($filters['cash_session_id'] ?? null, fn (Builder $q, $id) => $q->where('cash_session_id', $id))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw('LOWER(reference) LIKE ?', [$like])
                    ->orWhereHas('customer', fn (Builder $c) => $c->whereRaw('LOWER(name) LIKE ?', [$like])));
            })
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('sold_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('sold_at', '<=', $to))
            ->orderByDesc('id')
            ->paginate(25);

        return SaleResource::collection($sales);
    }

    public function show(Request $request, Sale $sale): SaleResource|JsonResponse
    {
        if ($sale->cashier_id !== $request->user()->id && ! $request->user()->hasPermission(Permission::SalesViewAll)) {
            return response()->json(['message' => 'Cette vente a été faite par un autre utilisateur.'], 403);
        }

        return new SaleResource($sale->load(self::DETAIL));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.article_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'decimal:0,3', 'gt:0'],
            'lines.*.discount' => ['nullable', 'decimal:0,2', 'min:0'],
            'payments' => ['present', 'array', 'max:10'],
            'payments.*.payment_method_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'decimal:0,2', 'min:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
        ], [
            'lines.required' => 'Le panier est vide.',
            'lines.min' => 'Le panier est vide.',
            'lines.*.quantity.gt' => 'La quantité doit être positive.',
            'lines.*.quantity.decimal' => 'Quantité : 3 décimales au plus.',
            'payments.present' => 'Indiquez le paiement.',
        ]);

        $sale = $this->sales->create($data, $request->user());

        return (new SaleResource($sale->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    /**
     * Recherche de client pendant une vente (le caissier n'a pas accès à la gestion des clients).
     */
    public function customers(Request $request): JsonResponse
    {
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $customers = Customer::where('active', true)
            ->where(fn (Builder $q) => $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])->orWhereRaw('LOWER(phone) LIKE ?', ["%{$search}%"]))
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'phone']);

        return response()->json(['data' => $customers]);
    }
}
