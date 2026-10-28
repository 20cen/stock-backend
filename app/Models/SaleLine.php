<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['sale_id', 'article_id', 'quantity', 'unit_price', 'discount', 'line_total', 'unit_cost_snapshot', 'position'])]
class SaleLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'unit_cost_snapshot' => 'decimal:4',
        ];
    }

    /**
     * Lignes de retour en cours ou validées (les refus ne comptent pas) : base du « déjà retourné ».
     *
     * @return HasMany<SaleReturnLine, $this>
     */
    public function activeReturnLines(): HasMany
    {
        return $this->hasMany(SaleReturnLine::class)
            ->whereHas('saleReturn', fn (Builder $q) => $q->whereIn('status', [SaleReturn::PENDING, SaleReturn::VALIDATED]));
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
