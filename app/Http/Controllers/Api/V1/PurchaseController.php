<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PurchaseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\PurchasePaymentRequest;
use App\Http\Requests\PurchaseRequest;
use App\Http\Requests\ReceiptRequest;
use App\Http\Resources\PurchaseResource;
use App\Models\Purchase;
use App\Services\PurchaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Acquisitions, réceptions et paiements fournisseurs. Les règles sont dans PurchaseService.
 */
class PurchaseController extends Controller
{
    private const DETAIL = ['supplier', 'creator', 'lines.article', 'receipts.location', 'receipts.receiver', 'receipts.lines.article', 'payments.method', 'payments.creator'];

    public function __construct(private readonly PurchaseService $purchases) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(PurchaseStatus::class)],
            'supplier_id' => ['nullable', 'integer'],
            'unpaid' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $purchases = Purchase::query()
            ->with('supplier')
            ->withCount(['lines', 'lines as lines_completed_count' => fn (Builder $q) => $q->whereColumn('quantity_received', '>=', 'quantity_ordered')])
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw('LOWER(reference) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(supplier_reference) LIKE ?', [$like])
                    ->orWhereHas('supplier', fn (Builder $s) => $s->whereRaw('LOWER(name) LIKE ?', [$like])));
            })
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['supplier_id'] ?? null, fn (Builder $q, $id) => $q->where('supplier_id', $id))
            ->when($request->boolean('unpaid'), fn (Builder $q) => $q
                ->whereColumn('paid_amount', '<', 'total')
                ->whereNotIn('status', [PurchaseStatus::Draft->value, PurchaseStatus::Cancelled->value]))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('purchase_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('purchase_date', '<=', $to))
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->paginate(25);

        return PurchaseResource::collection($purchases);
    }

    public function show(Purchase $purchase): PurchaseResource
    {
        return new PurchaseResource($purchase->load(self::DETAIL));
    }

    public function store(PurchaseRequest $request): JsonResponse
    {
        $purchase = $this->purchases->create($request->validated(), $request->user(), $request->boolean('confirm'));

        return (new PurchaseResource($purchase->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    public function update(PurchaseRequest $request, Purchase $purchase): PurchaseResource
    {
        $purchase = $this->purchases->update($purchase, $request->validated(), $request->user(), $request->boolean('confirm'));

        return new PurchaseResource($purchase->load(self::DETAIL));
    }

    public function confirm(Request $request, Purchase $purchase): PurchaseResource
    {
        $this->purchases->confirm($purchase, $request->user());

        return new PurchaseResource($purchase->refresh()->load(self::DETAIL));
    }

    public function cancel(Request $request, Purchase $purchase): PurchaseResource
    {
        $data = $request->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:500']],
            ['reason.required' => 'Le motif d’annulation est obligatoire.', 'reason.min' => 'Le motif doit contenir au moins 3 caractères.'],
        );
        $this->purchases->cancel($purchase, $request->user(), $data['reason']);

        return new PurchaseResource($purchase->refresh()->load(self::DETAIL));
    }

    public function receive(ReceiptRequest $request, Purchase $purchase): JsonResponse
    {
        $this->purchases->receive($purchase, $request->validated(), $request->user());

        return (new PurchaseResource($purchase->refresh()->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    public function pay(PurchasePaymentRequest $request, Purchase $purchase): JsonResponse
    {
        $this->purchases->addPayment($purchase, $request->validated(), $request->user());

        return (new PurchaseResource($purchase->refresh()->load(self::DETAIL)))->response()->setStatusCode(201);
    }
}
