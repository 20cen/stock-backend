<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Trace les opérations sensibles (03_Dossier_architecture_technique, §6).
 */
class AuditLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        string $action,
        ?Model $entity = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?int $userId = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $userId ?? $this->request->user()?->id,
            'action' => $action,
            'entity_type' => $entity ? $entity->getMorphClass() : null,
            'entity_id' => $entity?->getKey(),
            'before_data' => $before,
            'after_data' => $after,
            'reason' => $reason,
            'ip_address' => $this->request->ip(),
        ]);
    }
}
