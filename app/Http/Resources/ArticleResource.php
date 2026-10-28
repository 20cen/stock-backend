<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\Article;
use App\Models\Location;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Article
 */
class ArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $seesCosts = (bool) $request->user()?->hasPermission(Permission::PricesManage);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'barcode' => $this->barcode,
            'designation' => $this->designation,
            'category' => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name] : null,
            'brand' => $this->brand,
            'model' => $this->model,
            'description' => $this->description,
            'unit' => $this->unit,
            'allow_decimal_quantity' => $this->allow_decimal_quantity,
            'sale_price' => $this->sale_price,
            // Coûts d'achat réservés au propriétaire.
            'purchase_price_reference' => $this->when($seesCosts, $this->purchase_price_reference),
            'average_cost' => $this->when($seesCosts, $this->average_cost),
            'alert_threshold' => $this->alert_threshold,
            'serial_tracking' => $this->serial_tracking,
            'active' => $this->active,
            'stock' => $this->whenLoaded('stockBalances', fn () => $this->stock()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stock(): array
    {
        $balances = $this->stockBalances->keyBy('location_id');
        $total = '0.000';
        $byLocation = Location::activeInOrder()->map(function (Location $location) use ($balances, &$total) {
            $quantity = $balances->get($location->id)?->quantity_on_hand ?? '0.000';
            $total = bcadd($total, $quantity, Decimal::QUANTITY_SCALE);

            return [
                'location_id' => $location->id,
                'code' => $location->code,
                'name' => $location->name,
                'is_sellable' => $location->is_sellable,
                'quantity' => $quantity,
            ];
        })->values()->all();

        return [
            'total' => $total,
            'is_low' => bccomp($total, $this->alert_threshold, Decimal::QUANTITY_SCALE) <= 0,
            'by_location' => $byLocation,
        ];
    }
}
