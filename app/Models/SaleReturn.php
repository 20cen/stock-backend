<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Retour client sur une vente : demande (pending), puis validée ou refusée par un responsable.
 */
#[Fillable([
    'reference', 'sale_id', 'status', 'reason', 'notes', 'total_value', 'requested_by', 'requested_at',
    'decided_by', 'decided_at', 'rejection_reason',
])]
class SaleReturn extends Model
{
    public const PENDING = 'pending';

    public const VALIDATED = 'validated';

    public const REJECTED = 'rejected';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_value' => 'decimal:2',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return HasMany<SaleReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SaleReturnLine::class)->orderBy('id');
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
