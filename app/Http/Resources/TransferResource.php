<?php

namespace App\Http\Resources;

use App\Models\Transfer;
use App\Models\TransferLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transfer
 */
class TransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'source' => ['id' => $this->source->id, 'code' => $this->source->code, 'name' => $this->source->name],
            'destination' => ['id' => $this->destination->id, 'code' => $this->destination->code, 'name' => $this->destination->name],
            'notes' => $this->notes,
            'lines_count' => $this->whenCounted('lines'),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (TransferLine $l) => [
                'id' => $l->id,
                'article' => ['id' => $l->article->id, 'reference' => $l->article->reference, 'designation' => $l->article->designation, 'unit' => $l->article->unit],
                'quantity' => $l->quantity,
            ])),
            'created_by' => ['id' => $this->creator->id, 'name' => $this->creator->name],
            'validated_at' => $this->validated_at->toIso8601String(),
        ];
    }
}
