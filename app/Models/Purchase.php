<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Acquisition auprès d'un fournisseur. Écrite uniquement par App\Services\PurchaseService.
 *
 * @property-read string $balance_due
 */
#[Fillable([
    'reference', 'supplier_id', 'purchase_date', 'supplier_reference', 'status', 'subtotal', 'discount_total',
    'extra_costs', 'total', 'paid_amount', 'payment_terms', 'notes', 'created_by', 'ordered_at', 'ordered_by',
    'cancelled_at', 'cancelled_by', 'cancel_reason',
])]
class Purchase extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'purchase_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'extra_costs' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'ordered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getBalanceDueAttribute(): string
    {
        return bcsub($this->total, $this->paid_amount, 2);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return HasMany<PurchaseLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<Receipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class)->orderBy('id');
    }

    /**
     * @return HasMany<PurchasePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class)->orderBy('paid_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
