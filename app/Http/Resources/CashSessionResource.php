<?php

namespace App\Http\Resources;

use App\Models\CashSession;
use App\Services\CashSessionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashSession
 */
class CashSessionResource extends JsonResource
{
    /** Ajoute le solde théorique en direct (session ouverte ou détail). */
    public bool $withTotals = false;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $totals = $this->withTotals || ! $this->isOpen() ? app(CashSessionService::class)->totals($this->resource) : null;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'cashier' => $this->whenLoaded('cashier', fn () => ['id' => $this->cashier->id, 'name' => $this->cashier->name]),
            'opened_at' => $this->opened_at->toIso8601String(),
            'opening_amount' => $this->opening_amount,
            'closed_at' => $this->closed_at?->toIso8601String(),
            // Session clôturée : valeurs figées à la clôture ; ouverte : calculées en direct.
            'totals_by_method' => $this->isOpen() ? $totals['methods'] ?? null : $this->totals_by_method,
            'expected_cash' => $this->isOpen() ? $totals['expected_cash'] ?? null : $this->expected_cash,
            'declared_cash' => $this->declared_cash,
            'variance' => $this->variance,
            'variance_reason' => $this->variance_reason,
            'sales_count' => $totals['sales_count'] ?? null,
            'sales_total' => $totals['sales_total'] ?? null,
            'notes' => $this->notes,
        ];
    }
}
