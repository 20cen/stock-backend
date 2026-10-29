<?php

namespace App\Http\Resources;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'expense_date' => $this->expense_date->toDateString(),
            'label' => $this->label,
            'category' => ['id' => $this->category->id, 'name' => $this->category->name],
            'amount' => $this->amount,
            'method' => ['id' => $this->method->id, 'code' => $this->method->code, 'name' => $this->method->name],
            'beneficiary' => $this->beneficiary,
            'notes' => $this->notes,
            'attachment_name' => $this->attachment_name,
            'from_cash_drawer' => $this->cash_session_id !== null,
            'created_by' => ['id' => $this->creator->id, 'name' => $this->creator->name],
            'created_at' => $this->created_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $this->canceller ? ['id' => $this->canceller->id, 'name' => $this->canceller->name] : null,
            'cancel_reason' => $this->cancel_reason,
        ];
    }
}
