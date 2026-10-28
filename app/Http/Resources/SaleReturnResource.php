<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\Refund;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SaleReturn
 */
class SaleReturnResource extends JsonResource
{
    private const STATUS_LABELS = [
        SaleReturn::PENDING => 'En attente de validation',
        SaleReturn::VALIDATED => 'Validé',
        SaleReturn::REJECTED => 'Refusé',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $refunded = $this->relationLoaded('refunds') ? $this->refunds->reduce(fn (string $s, Refund $r) => bcadd($s, $r->amount, 2), '0.00') : null;
        $canDecide = (bool) $request->user()?->hasPermission(Permission::ReturnsValidate);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'status_label' => self::STATUS_LABELS[$this->status] ?? $this->status,
            'sale' => $this->whenLoaded('sale', fn () => [
                'id' => $this->sale->id,
                'reference' => $this->sale->reference,
                'sold_at' => $this->sale->sold_at->toIso8601String(),
                'amount_paid' => $this->sale->amount_paid,
                'refunded_amount' => $this->sale->refunded_amount,
                'location_id' => $this->sale->location_id,
            ]),
            'reason' => $this->reason,
            'notes' => $this->notes,
            'total_value' => $this->total_value,
            'refunded' => $refunded,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (SaleReturnLine $l) => [
                'id' => $l->id,
                'article' => ['id' => $l->article->id, 'reference' => $l->article->reference, 'designation' => $l->article->designation, 'unit' => $l->article->unit],
                'quantity' => $l->quantity,
                'condition' => $l->condition,
                'value' => $l->value,
                'destination' => $l->destination ? ['id' => $l->destination->id, 'code' => $l->destination->code, 'name' => $l->destination->name] : null,
            ])),
            'refunds' => $this->whenLoaded('refunds', fn () => $this->refunds->map(fn (Refund $r) => [
                'id' => $r->id,
                'method' => ['id' => $r->method->id, 'name' => $r->method->name],
                'amount' => $r->amount,
                'refunded_at' => $r->refunded_at->toIso8601String(),
                'refunded_by' => $r->refunder->name,
            ])),
            'requested_by' => $this->whenLoaded('requester', fn () => ['id' => $this->requester->id, 'name' => $this->requester->name]),
            'requested_at' => $this->requested_at->toIso8601String(),
            'decided_by' => $this->whenLoaded('decider', fn () => $this->decider ? ['id' => $this->decider->id, 'name' => $this->decider->name] : null),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'can' => [
                'decide' => $canDecide && $this->status === SaleReturn::PENDING,
                'refund' => $canDecide && $this->status === SaleReturn::VALIDATED && $refunded !== null && bccomp($refunded, $this->total_value, 2) < 0,
            ],
        ];
    }
}
