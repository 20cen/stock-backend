<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vente validée. Écrite uniquement par App\Services\SaleService ; jamais supprimée.
 *
 * @property-read string $balance_due
 */
#[Fillable([
    'reference', 'sold_at', 'cash_session_id', 'cashier_id', 'customer_id', 'location_id', 'status',
    'subtotal', 'discount_total', 'total', 'amount_paid', 'change_given', 'refunded_amount', 'notes',
    'cancelled_at', 'cancelled_by', 'cancel_reason',
])]
class Sale extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'change_given' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public const VALIDATED = 'validated';

    public const CANCELLED = 'cancelled';

    /**
     * @return HasMany<SaleReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class)->orderBy('id');
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('id');
    }

    public function getBalanceDueAttribute(): string
    {
        return bcsub($this->total, $this->amount_paid, 2);
    }

    /**
     * @return HasMany<SaleLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<SalePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<CashSession, $this>
     */
    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }
}
