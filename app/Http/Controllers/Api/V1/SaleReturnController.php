<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\SaleResource;
use App\Http\Resources\SaleReturnResource;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\ReturnService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Retours clients. Le caissier enregistre des demandes (returns.request) ; un responsable les valide
 * ou les refuse et rembourse (returns.validate).
 */
class SaleReturnController extends Controller
{
    private const DETAIL = ['sale', 'lines.article', 'lines.destination', 'refunds.method', 'refunds.refunder', 'requester', 'decider'];

    public function __construct(private readonly ReturnService $returns) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,validated,rejected'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $seesAll = $request->user()->hasPermission(Permission::ReturnsValidate);

        $returns = SaleReturn::query()
            ->with(['sale', 'requester', 'refunds'])
            ->when(! $seesAll, fn (Builder $q) => $q->where('requested_by', $request->user()->id))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw('LOWER(reference) LIKE ?', [$like])
                    ->orWhereHas('sale', fn (Builder $s) => $s->whereRaw('LOWER(reference) LIKE ?', [$like])));
            })
            // Demandes en attente d'abord.
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->paginate(25);

        return SaleReturnResource::collection($returns);
    }

    public function show(SaleReturn $saleReturn): SaleReturnResource
    {
        return new SaleReturnResource($saleReturn->load(self::DETAIL));
    }

    /**
     * Vente d'origine retrouvée par son numéro (reçu), avec les quantités encore retournables.
     */
    public function saleLookup(Request $request): SaleResource|JsonResponse
    {
        $reference = mb_strtoupper(trim((string) $request->query('reference', '')));
        $sale = Sale::where('reference', $reference)->first();

        return $sale
            ? new SaleResource($sale->load(SaleController::DETAIL))
            : response()->json(['message' => "Aucune vente ne porte le numéro « {$reference} »."], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sale_id' => ['required', 'integer', 'exists:sales,id'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'decimal:0,3', 'min:0'],
            'lines.*.condition' => ['required', 'in:good,damaged'],
            'validate' => ['sometimes', 'boolean'],
            'refund' => ['nullable', 'array'],
            'refund.amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'refund.payment_method_id' => ['required_with:refund.amount', 'nullable', 'integer'],
            'refund.reference' => ['nullable', 'string', 'max:100'],
        ], [
            'reason.required' => 'Le motif du retour est obligatoire.',
            'reason.min' => 'Le motif doit contenir au moins 3 caractères.',
            'lines.*.condition.required' => 'Indiquez l’état de chaque produit retourné.',
            'lines.*.condition.in' => 'État invalide.',
        ]);

        $return = $this->returns->request($data, $request->user());

        return (new SaleReturnResource($return->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    public function validateReturn(Request $request, SaleReturn $saleReturn): SaleReturnResource
    {
        $data = $request->validate([
            'destinations' => ['nullable', 'array'],
            'destinations.*' => ['integer'],
            'refund' => ['nullable', 'array'],
            'refund.amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'refund.payment_method_id' => ['required_with:refund.amount', 'nullable', 'integer'],
            'refund.reference' => ['nullable', 'string', 'max:100'],
        ]);

        $this->returns->validate($saleReturn, $request->user(), $data['destinations'] ?? [], $data['refund'] ?? null);

        return new SaleReturnResource($saleReturn->refresh()->load(self::DETAIL));
    }

    public function reject(Request $request, SaleReturn $saleReturn): SaleReturnResource
    {
        $data = $request->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:1000']],
            ['reason.required' => 'Le motif du refus est obligatoire.', 'reason.min' => 'Le motif doit contenir au moins 3 caractères.'],
        );

        $this->returns->reject($saleReturn, $request->user(), $data['reason']);

        return new SaleReturnResource($saleReturn->refresh()->load(self::DETAIL));
    }

    public function refund(Request $request, SaleReturn $saleReturn): SaleReturnResource
    {
        $data = $request->validate([
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'payment_method_id' => ['required', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
        ], ['amount.required' => 'Le montant est obligatoire.', 'payment_method_id.required' => 'Choisissez le mode de remboursement.']);

        $this->returns->refund($saleReturn, $data, $request->user());

        return new SaleReturnResource($saleReturn->refresh()->load(self::DETAIL));
    }
}
