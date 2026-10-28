<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\StockBalance;
use App\Support\Decimal;
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
        $article = $this->article;
        $seesCosts = (bool) $request->user()?->hasPermission(Permission::PricesManage);

        return [
            'id' => $this->id,
            'quantity_on_hand' => $this->quantity_on_hand,
            'is_low' => bccomp($this->quantity_on_hand, $article->alert_threshold, Decimal::QUANTITY_SCALE) <= 0,
            // Valorisation au coût moyen pondéré, réservée au propriétaire.
            'average_cost' => $this->when($seesCosts, $article->average_cost),
            'stock_value' => $this->when($seesCosts, fn () => $article->average_cost === null
                ? null
                : Decimal::round(bcmul($this->quantity_on_hand, $article->average_cost, 8), 2)),
            'article' => [
                'id' => $article->id,
                'reference' => $article->reference,
                'designation' => $article->designation,
                'unit' => $article->unit,
                'allow_decimal_quantity' => $article->allow_decimal_quantity,
                'alert_threshold' => $article->alert_threshold,
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
