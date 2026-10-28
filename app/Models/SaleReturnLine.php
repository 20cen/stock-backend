<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sale_return_id', 'sale_line_id', 'article_id', 'quantity', 'condition', 'value', 'destination_location_id'])]
class SaleReturnLine extends Model
{
    public const GOOD = 'good';

    public const DAMAGED = 'damaged';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'value' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<SaleReturn, $this>
     */
    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    /**
     * @return BelongsTo<SaleLine, $this>
     */
    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(SaleLine::class);
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
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }
}
