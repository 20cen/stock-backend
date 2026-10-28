<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche fournisseur ou client : identifiant et champs modifiables du modèle.
 *
 * @mixin Model
 */
class PartnerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->getKey()]
            + $this->resource->only($this->resource->getFillable())
            + ['created_at' => $this->created_at?->toIso8601String()];
    }
}
