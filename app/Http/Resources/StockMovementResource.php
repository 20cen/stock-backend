<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'quantity' => $this->quantity,
            'balance_after' => $this->balance_after,
            // Les coûts d'achat sont réservés au propriétaire.
            'unit_cost' => $this->when($request->user()?->hasPermission(Permission::PricesManage), $this->unit_cost),
            'article' => $this->whenLoaded('article', fn () => [
                'id' => $this->article->id,
                'reference' => $this->article->reference,
                'designation' => $this->article->designation,
            ]),
            'location' => $this->whenLoaded('location', fn () => [
                'id' => $this->location->id,
                'code' => $this->location->code,
                'name' => $this->location->name,
            ]),
            'source' => $this->source_type ? ['type' => $this->source_type, 'id' => $this->source_id] : null,
            'reason' => $this->reason,
            'created_by' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
