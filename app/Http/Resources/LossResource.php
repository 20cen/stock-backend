<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\Loss;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Loss
 */
class LossResource extends JsonResource
{
    private const STATUS_LABELS = [
        Loss::PENDING => 'En attente de validation',
        Loss::VALIDATED => 'Validée',
        Loss::REJECTED => 'Refusée',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'status_label' => self::STATUS_LABELS[$this->status] ?? $this->status,
            'article' => ['id' => $this->article->id, 'reference' => $this->article->reference, 'designation' => $this->article->designation, 'unit' => $this->article->unit],
            'location' => ['id' => $this->location->id, 'code' => $this->location->code, 'name' => $this->location->name],
            'quantity' => $this->quantity,
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'comment' => $this->comment,
            // Valeur au CMP : réservée au propriétaire.
            'estimated_value' => $this->when((bool) $user?->hasPermission(Permission::PricesManage), $this->estimated_value),
            'declared_by' => ['id' => $this->declarer->id, 'name' => $this->declarer->name],
            'declared_at' => $this->declared_at->toIso8601String(),
            'decided_by' => $this->decider ? ['id' => $this->decider->id, 'name' => $this->decider->name] : null,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'can' => ['decide' => $this->status === Loss::PENDING && (bool) $user?->hasPermission(Permission::LossesValidate)],
        ];
    }
}
