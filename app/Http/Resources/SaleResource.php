<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SalePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sale
 */
class SaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $seesCosts = (bool) $request->user()?->hasPermission(Permission::PricesManage);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'sold_at' => $this->sold_at->toIso8601String(),
            'cashier' => $this->whenLoaded('cashier', fn () => ['id' => $this->cashier->id, 'name' => $this->cashier->name]),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'name' => $this->customer->name, 'phone' => $this->customer->phone] : null),
            'location' => $this->whenLoaded('location', fn () => ['id' => $this->location->id, 'code' => $this->location->code, 'name' => $this->location->name]),
            'cash_session' => $this->whenLoaded('cashSession', fn () => ['id' => $this->cashSession->id, 'reference' => $this->cashSession->reference]),
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'balance_due' => $this->balance_due,
            'change_given' => $this->change_given,
            'notes' => $this->notes,
            'lines_count' => $this->whenCounted('lines'),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (SaleLine $l) => [
                'id' => $l->id,
                'article' => ['id' => $l->article->id, 'reference' => $l->article->reference, 'designation' => $l->article->designation, 'unit' => $l->article->unit],
                'quantity' => $l->quantity,
                'unit_price' => $l->unit_price,
                'discount' => $l->discount,
                'line_total' => $l->line_total,
            ] + ($seesCosts ? ['unit_cost_snapshot' => $l->unit_cost_snapshot] : []))), // coût figé (marge) : propriétaire seulement
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn (SalePayment $p) => [
                'id' => $p->id,
                'method' => ['id' => $p->method->id, 'code' => $p->method->code, 'name' => $p->method->name],
                'amount' => $p->amount,
                'reference' => $p->reference,
            ])),
        ];
    }
}
