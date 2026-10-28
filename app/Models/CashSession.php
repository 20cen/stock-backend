<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Session de caisse : ouverte avec un fonds de caisse, clôturée avec le montant compté.
 * Une fois clôturée, elle n'est plus modifiable.
 */
#[Fillable([
    'reference', 'cashier_id', 'status', 'opened_at', 'opening_amount', 'closed_at', 'expected_cash',
    'declared_cash', 'variance', 'variance_reason', 'totals_by_method', 'notes',
])]
class CashSession extends Model
{
    public const OPEN = 'open';

    public const CLOSED = 'closed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_amount' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'declared_cash' => 'decimal:2',
            'variance' => 'decimal:2',
            'totals_by_method' => 'array',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * @return HasMany<SalePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }
}
