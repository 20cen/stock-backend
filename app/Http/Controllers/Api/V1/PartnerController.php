<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PartnerResource;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Base des fiches fournisseurs et clients : liste filtrée, fiche, création, modification tracée.
 * Pas de suppression : une fiche utilisée dans l'historique est désactivée.
 */
abstract class PartnerController extends Controller
{
    public function __construct(protected readonly AuditLogger $audit) {}

    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** Préfixe des actions d'audit (« supplier », « customer »). */
    abstract protected function auditName(): string;

    /** @return list<string> colonnes de la recherche texte */
    abstract protected function searchable(): array;

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,all'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'active';

        $items = $this->model()::query()
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(function (Builder $w) use ($like) {
                    foreach ($this->searchable() as $column) {
                        $w->orWhereRaw("LOWER({$column}) LIKE ?", [$like]);
                    }
                });
            })
            ->when($status !== 'all', fn (Builder $q) => $q->where('active', $status === 'active'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        return PartnerResource::collection($items);
    }

    protected function showModel(Model $model): PartnerResource
    {
        return new PartnerResource($model);
    }

    protected function storeModel(FormRequest $request): JsonResponse
    {
        $model = DB::transaction(function () use ($request) {
            $model = $this->model()::create($request->validated())->refresh();
            $this->audit->log("{$this->auditName()}.created", $model, after: $request->validated());

            return $model;
        });

        return (new PartnerResource($model))->response()->setStatusCode(201);
    }

    protected function updateModel(FormRequest $request, Model $model): PartnerResource
    {
        DB::transaction(function () use ($request, $model) {
            $before = Arr::only($model->toArray(), $model->getFillable());
            $model->update($request->validated());

            $changed = array_intersect(array_keys($model->getChanges()), $model->getFillable());
            if ($changed) {
                $this->audit->log("{$this->auditName()}.updated", $model, before: Arr::only($before, $changed), after: Arr::only($model->toArray(), $changed));
            }
        });

        return new PartnerResource($model);
    }
}
