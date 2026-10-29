<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PurchaseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\PurchasePaymentRequest;
use App\Http\Requests\PurchaseRequest;
use App\Http\Requests\ReceiptRequest;
use App\Http\Resources\PurchaseResource;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Receipt;
use App\Models\ReceiptLine;
use App\Services\PurchaseService;
use App\Support\Decimal;
use App\Support\PdfRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

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

    /**
     * Bon de réception PDF : quantités commandées, reçues ce jour, cumulées à cette réception et restantes.
     */
    public function receiptPdf(Purchase $purchase, Receipt $receipt, PdfRenderer $pdf): Response
    {
        abort_unless($receipt->purchase_id === $purchase->id, 404);
        $purchase->load(['supplier', 'lines.article']);
        $receipt->load(['location', 'receiver', 'lines']);

        // Cumul reçu jusqu'à cette réception incluse (les réceptions suivantes ne comptent pas).
        $cumulative = ReceiptLine::whereIn('receipt_id', Receipt::where('purchase_id', $purchase->id)->where('id', '<=', $receipt->id)->select('id'))
            ->groupBy('purchase_line_id')->selectRaw('purchase_line_id, SUM(quantity) AS q')->pluck('q', 'purchase_line_id');

        $lines = $receipt->lines->map(function (ReceiptLine $l) use ($purchase, $cumulative) {
            $line = $purchase->lines->firstWhere('id', $l->purchase_line_id);
            $received = (string) ($cumulative[$l->purchase_line_id] ?? '0');

            return [
                'reference' => $line->article->reference,
                'designation' => $line->article->designation,
                'unit' => $line->article->unit,
                'ordered' => $line->quantity_ordered,
                'received' => $l->quantity,
                'cumulative' => $received,
                'remaining' => bccomp($line->quantity_ordered, $received, 3) > 0 ? bcsub($line->quantity_ordered, $received, 3) : '0',
            ];
        })->all();

        $content = $pdf->render('documents.receipt', [
            'purchase' => $purchase,
            'receipt' => $receipt,
            'lines' => $lines,
            'complete' => $purchase->lines->every(fn ($pl) => bccomp((string) ($cumulative[$pl->id] ?? '0'), $pl->quantity_ordered, 3) >= 0),
        ]);

        return $this->pdfResponse($content, "bon-reception-{$receipt->reference}.pdf");
    }

    /**
     * Reçu de paiement fournisseur PDF, avec le solde restant juste après ce paiement.
     */
    public function paymentPdf(Purchase $purchase, PurchasePayment $payment, PdfRenderer $pdf): Response
    {
        abort_unless($payment->purchase_id === $purchase->id, 404);
        $purchase->load('supplier');
        $payment->load(['method', 'creator']);

        $previous = PurchasePayment::where('purchase_id', $purchase->id)->where('id', '<', $payment->id);
        $paidBefore = Decimal::round((string) ((clone $previous)->sum('amount') ?: '0'), 2);
        // Numéro lisible : ACH-2026-00001 / P2 pour le 2e paiement de l'acquisition.
        $number = "{$purchase->reference} / P".($previous->count() + 1);

        $content = $pdf->render('documents.payment', [
            'purchase' => $purchase,
            'payment' => $payment,
            'number' => $number,
            'paidBefore' => $paidBefore,
            'balanceAfter' => bcsub(bcsub($purchase->total, $paidBefore, 2), $payment->amount, 2),
        ]);

        return $this->pdfResponse($content, 'recu-paiement-'.str_replace(' / ', '-', $number).'.pdf');
    }

    private function pdfResponse(string $content, string $filename): Response
    {
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($content),
        ]);
    }
}
