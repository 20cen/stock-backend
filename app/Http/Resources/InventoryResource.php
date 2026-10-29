<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Inventory
 */
class InventoryResource extends JsonResource
{
    private const STATUS_LABELS = [
        Inventory::IN_PROGRESS => 'En cours',
        Inventory::VALIDATED => 'Validé',
        Inventory::CANCELLED => 'Annulé',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $seesCosts = (bool) $request->user()?->hasPermission(Permission::PricesManage);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'status_label' => self::STATUS_LABELS[$this->status] ?? $this->status,
            'location' => ['id' => $this->location->id, 'code' => $this->location->code, 'name' => $this->location->name],
            'category' => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name] : null,
            'notes' => $this->notes,
            'started_at' => $this->started_at->toIso8601String(),
            'created_by' => ['id' => $this->creator->id, 'name' => $this->creator->name],
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closed_by' => $this->closer ? ['id' => $this->closer->id, 'name' => $this->closer->name] : null,
            'cancel_reason' => $this->cancel_reason,
            'summary' => $this->when($this->relationLoaded('lines') || isset($this->lines_count), fn () => $this->summary($seesCosts)),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (InventoryLine $l) => [
                'id' => $l->id,
                'article' => [
                    'id' => $l->article->id,
                    'reference' => $l->article->reference,
                    'barcode' => $l->article->barcode,
                    'designation' => $l->article->designation,
                    'unit' => $l->article->unit,
                    'allow_decimal_quantity' => $l->article->allow_decimal_quantity,
                ],
                'theoretical_quantity' => $l->theoretical_quantity,
                'counted_quantity' => $l->counted_quantity,
                'variance' => $l->variance,
                'reason' => $l->reason,
            ] + ($seesCosts ? ['average_cost' => $l->article->average_cost] : []))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(bool $seesCosts): array
    {
        if (! $this->relationLoaded('lines')) {
            return ['lines' => (int) $this->lines_count, 'counted' => (int) $this->counted_count, 'with_variance' => null];
        }

        $counted = $this->lines->filter(fn (InventoryLine $l) => $l->counted_quantity !== null);
        $withVariance = $counted->filter(fn (InventoryLine $l) => ! Decimal::isZero($l->variance));
        $summary = ['lines' => $this->lines->count(), 'counted' => $counted->count(), 'with_variance' => $withVariance->count()];

        if ($seesCosts) {
            // Valeur des écarts au CMP : négative = manquant, positive = surplus.
            $summary['variance_value'] = Decimal::round($withVariance->reduce(
                fn (string $s, InventoryLine $l) => bcadd($s, bcmul($l->variance, $l->article->average_cost ?? '0', 8), 8),
                '0',
            ), 2);
        }

        return $summary;
    }
}
