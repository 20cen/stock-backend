<?php

namespace App\Http\Resources;

use App\Models\StockBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockBalance
 */
class StockBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity_on_hand' => $this->quantity_on_hand,
            'is_low' => $this->quantity_on_hand <= $this->article->alert_threshold,
            'article' => [
                'id' => $this->article->id,
                'reference' => $this->article->reference,
                'designation' => $this->article->designation,
                'unit' => $this->article->unit,
                'alert_threshold' => $this->article->alert_threshold,
            ],
            'location' => [
                'id' => $this->location->id,
                'code' => $this->location->code,
                'name' => $this->location->name,
            ],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
