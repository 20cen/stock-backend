<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string|null $variance compté − théorique (null tant que l'article n'est pas compté)
 */
#[Fillable(['inventory_id', 'article_id', 'theoretical_quantity', 'counted_quantity', 'reason', 'counted_by', 'counted_at'])]
class InventoryLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'theoretical_quantity' => 'decimal:3',
            'counted_quantity' => 'decimal:3',
            'counted_at' => 'datetime',
        ];
    }

    public function getVarianceAttribute(): ?string
    {
        return $this->counted_quantity === null ? null : bcsub($this->counted_quantity, $this->theoretical_quantity, 3);
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
