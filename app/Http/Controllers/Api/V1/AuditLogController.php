<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Journal d'audit en lecture seule (B18) : qui a fait quoi, quand, avec l'avant/après et le motif.
 */
class AuditLogController extends Controller
{
    public function filters(): JsonResponse
    {
        return response()->json(['data' => [
            'users' => User::orderBy('name')->get(['id', 'name', 'username', 'active']),
            'modules' => collect(AuditCatalog::MODULES)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values(),
            'actions' => collect(AuditCatalog::ACTIONS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values(),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'module' => ['nullable', Rule::in(array_keys(AuditCatalog::MODULES))],
            'action' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $logs = AuditLog::query()
            ->with('user:id,name,username')
            ->when($filters['user_id'] ?? null, fn (Builder $q, $id) => $q->where('user_id', $id))
            // « expense » ne doit pas inclure « expense_category » : préfixe suivi d'un point.
            ->when($filters['module'] ?? null, fn (Builder $q, $module) => $q->where('action', 'like', $module.'.%'))
            ->when($filters['action'] ?? null, fn (Builder $q, $action) => $q->where('action', $action))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(fn (Builder $w) => $w->whereRaw('LOWER(reason) LIKE ?', [$like])->orWhereRaw('LOWER(ip_address) LIKE ?', [$like]));
            })
            ->orderByDesc('id')
            ->paginate(50);

        $labels = $this->entityLabels(collect($logs->items()));

        return response()->json([
            'data' => collect($logs->items())->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at?->toIso8601String(),
                'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name, 'username' => $log->user->username] : null,
                'action' => $log->action,
                'action_label' => AuditCatalog::action($log->action),
                'entity' => $log->entity_type ? [
                    'type' => AuditCatalog::ENTITIES[$log->entity_type][0] ?? class_basename($log->entity_type),
                    'id' => $log->entity_id,
                    'label' => $labels[$log->entity_type][$log->entity_id] ?? null,
                ] : null,
                'before' => $log->before_data,
                'after' => $log->after_data,
                'reason' => $log->reason,
                'ip_address' => $log->ip_address,
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Référence ou nom de chaque entité de la page, en une requête par type.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return array<string, array<int, string>>
     */
    private function entityLabels(Collection $logs): array
    {
        $labels = [];
        foreach ($logs->whereNotNull('entity_type')->groupBy('entity_type') as $type => $group) {
            [, $column] = AuditCatalog::ENTITIES[$type] ?? [null, null];
            if (! $column || ! class_exists($type)) {
                continue;
            }
            $labels[$type] = $type::whereIn('id', $group->pluck('entity_id')->unique())
                ->pluck($column, 'id')
                ->map(fn ($value) => (string) $value)
                ->all();
        }

        return $labels;
    }
}
