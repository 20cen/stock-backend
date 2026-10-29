<?php

namespace App\Models;

use App\Enums\LossReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perte déclarée (casse, vol…). Validée, elle sort du stock ; refusée, elle reste tracée.
 */
#[Fillable([
    'reference', 'article_id', 'location_id', 'quantity', 'reason', 'comment', 'estimated_value', 'status',
    'declared_by', 'declared_at', 'decided_by', 'decided_at', 'rejection_reason',
])]
class Loss extends Model
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
            'quantity' => 'decimal:3',
            'reason' => LossReason::class,
            'estimated_value' => 'decimal:2',
            'declared_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function declarer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
