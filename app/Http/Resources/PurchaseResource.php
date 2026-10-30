<?php

namespace App\Http\Resources;

use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchasePayment;
use App\Models\Receipt;
use App\Models\ReceiptLine;
use App\Services\PurchaseService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Purchase
 */
class PurchaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $status = $this->status;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $status->value,
            'status_label' => $status->label(),
            'supplier' => $this->whenLoaded('supplier', fn () => ['id' => $this->supplier->id, 'name' => $this->supplier->name]),
            'purchase_date' => $this->purchase_date->toDateString(),
            'supplier_reference' => $this->supplier_reference,
            'payment_terms' => $this->payment_terms,
            'notes' => $this->notes,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'extra_costs' => $this->extra_costs,
            'total' => $this->total,
            'undelivered_amount' => $this->undelivered_amount,
            'net_total' => $this->net_total,
            'paid_amount' => $this->paid_amount,
            'balance_due' => $this->balance_due,
            // Avancement en lignes entièrement reçues : additionner des pièces et des mètres n'aurait pas de sens.
            'lines_count' => $this->when(isset($this->lines_count) || $this->relationLoaded('lines'), fn () => $this->relationLoaded('lines')
                ? $this->lines->count()
                : (int) $this->lines_count),
            'lines_completed' => $this->when(isset($this->lines_completed_count) || $this->relationLoaded('lines'), fn () => $this->relationLoaded('lines')
                ? $this->lines->filter(fn (PurchaseLine $l) => bccomp($l->quantity_received, $l->quantity_ordered, 3) >= 0)->count()
                : (int) $this->lines_completed_count),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (PurchaseLine $line) => [
                'id' => $line->id,
                'article' => [
                    'id' => $line->article->id,
                    'reference' => $line->article->reference,
                    'designation' => $line->article->designation,
                    'unit' => $line->article->unit,
                    'allow_decimal_quantity' => $line->article->allow_decimal_quantity,
                ],
                'quantity_ordered' => $line->quantity_ordered,
                'quantity_received' => $line->quantity_received,
                'quantity_remaining' => $line->quantity_remaining,
                'unit_price' => $line->unit_price,
                'discount' => $line->discount,
                'line_total' => $line->line_total,
                'landed_unit_cost' => app(PurchaseService::class)->landedUnitCost($this->resource, $line),
            ])),
            'receipts' => $this->whenLoaded('receipts', fn () => $this->receipts->map(fn (Receipt $receipt) => [
                'id' => $receipt->id,
                'reference' => $receipt->reference,
                'received_at' => $receipt->received_at->toIso8601String(),
                'location' => ['id' => $receipt->location->id, 'code' => $receipt->location->code, 'name' => $receipt->location->name],
                'received_by' => ['id' => $receipt->receiver->id, 'name' => $receipt->receiver->name],
                'notes' => $receipt->notes,
                'lines' => $receipt->lines->map(fn (ReceiptLine $l) => [
                    'purchase_line_id' => $l->purchase_line_id,
                    'designation' => $l->article->designation,
                    'quantity' => $l->quantity,
                    'unit_cost' => $l->unit_cost,
                ]),
            ])),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn (PurchasePayment $p) => [
                'id' => $p->id,
                'amount' => $p->amount,
                'method' => ['id' => $p->method->id, 'name' => $p->method->name],
                'paid_at' => $p->paid_at->toIso8601String(),
                'reference' => $p->reference,
                'notes' => $p->notes,
                'created_by' => ['id' => $p->creator->id, 'name' => $p->creator->name],
                'cancelled_at' => $p->cancelled_at?->toIso8601String(),
                'cancel_reason' => $p->cancel_reason,
                'cancelled_by' => $p->canceller ? ['id' => $p->canceller->id, 'name' => $p->canceller->name] : null,
            ])),
            'created_by' => $this->whenLoaded('creator', fn () => ['id' => $this->creator->id, 'name' => $this->creator->name]),
            'ordered_at' => $this->ordered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'close_reason' => $this->close_reason,
            'closed_by' => $this->whenLoaded('closer', fn () => $this->closer ? ['id' => $this->closer->id, 'name' => $this->closer->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            // Actions possibles selon le statut et les droits ; le serveur revérifie à chaque appel.
            'can' => [
                'edit' => $status === PurchaseStatus::Draft,
                'confirm' => $status === PurchaseStatus::Draft,
                'cancel' => in_array($status, [PurchaseStatus::Draft, PurchaseStatus::Ordered], true) && bccomp($this->paid_amount, '0', 2) === 0,
                'receive' => $status->canReceive() && (bool) $user?->hasPermission('receipts.validate'),
                'pay' => $status->canPay() && bccomp($this->balance_due, '0', 2) > 0,
                'close' => $status === PurchaseStatus::PartiallyReceived,
                'cancel_payment' => $status->canPay(),
            ],
        ];
    }
}
