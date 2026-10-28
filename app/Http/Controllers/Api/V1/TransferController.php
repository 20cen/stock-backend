<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransferResource;
use App\Models\Transfer;
use App\Services\TransferService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Transferts entre emplacements, validés en une seule opération. Pas de modification ni de suppression.
 */
class TransferController extends Controller
{
    public function __construct(private readonly TransferService $transfers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'location_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $transfers = Transfer::query()
            ->with(['source', 'destination', 'creator'])
            ->withCount('lines')
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw('LOWER(reference) LIKE ?', [$like])
                    ->orWhereHas('lines.article', fn (Builder $a) => $a->whereRaw('LOWER(designation) LIKE ?', [$like])->orWhereRaw('LOWER(reference) LIKE ?', [$like])));
            })
            ->when($filters['location_id'] ?? null, fn (Builder $q, $id) => $q->where(fn (Builder $w) => $w->where('source_location_id', $id)->orWhere('destination_location_id', $id)))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('validated_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('validated_at', '<=', $to))
            ->orderByDesc('id')
            ->paginate(25);

        return TransferResource::collection($transfers);
    }

    public function show(Transfer $transfer): TransferResource
    {
        return new TransferResource($transfer->load(['source', 'destination', 'creator', 'lines.article']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_location_id' => ['required', 'integer', 'exists:locations,id'],
            'destination_location_id' => ['required', 'integer', 'exists:locations,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.article_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'decimal:0,3', 'gt:0'],
        ], [
            'source_location_id.required' => 'Choisissez l’emplacement d’origine.',
            'destination_location_id.required' => 'Choisissez l’emplacement de destination.',
            'lines.required' => 'Ajoutez au moins un article.',
            'lines.min' => 'Ajoutez au moins un article.',
            'lines.*.quantity.required' => 'Quantité obligatoire.',
            'lines.*.quantity.decimal' => 'Quantité : 3 décimales au plus.',
            'lines.*.quantity.gt' => 'La quantité doit être positive.',
        ]);

        $transfer = $this->transfers->create($data, $request->user());

        return (new TransferResource($transfer->load(['source', 'destination', 'creator', 'lines.article'])))->response()->setStatusCode(201);
    }
}
