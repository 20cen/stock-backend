<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LossReason;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\LossResource;
use App\Models\Loss;
use App\Services\LossService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Pertes : déclaration (losses.declare), validation ou refus (losses.validate).
 */
class LossController extends Controller
{
    private const RELATIONS = ['article', 'location', 'declarer', 'decider'];

    public function __construct(private readonly LossService $losses) {}

    public function reasons(): JsonResponse
    {
        return response()->json(['data' => array_map(fn (LossReason $r) => ['value' => $r->value, 'label' => $r->label()], LossReason::cases())]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,validated,rejected'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $seesAll = $request->user()->hasPermission(Permission::LossesValidate);

        $losses = Loss::query()
            ->with(self::RELATIONS)
            ->when(! $seesAll, fn (Builder $q) => $q->where('declared_by', $request->user()->id))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw('LOWER(reference) LIKE ?', [$like])
                    ->orWhereHas('article', fn (Builder $a) => $a->whereRaw('LOWER(designation) LIKE ?', [$like])->orWhereRaw('LOWER(reference) LIKE ?', [$like])));
            })
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->paginate(25);

        return LossResource::collection($losses);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'article_id' => ['required', 'integer'],
            'location_id' => ['required', 'integer'],
            'quantity' => ['required', 'decimal:0,3', 'gt:0'],
            'reason' => ['required', Rule::enum(LossReason::class)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'article_id.required' => 'Choisissez l’article.',
            'location_id.required' => 'Choisissez l’emplacement.',
            'quantity.required' => 'La quantité est obligatoire.',
            'quantity.gt' => 'La quantité doit être positive.',
            'reason.required' => 'Le motif est obligatoire.',
        ]);

        $loss = $this->losses->declare($data, $request->user());

        return (new LossResource($loss->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function validateLoss(Request $request, Loss $loss): LossResource
    {
        $this->losses->validate($loss, $request->user());

        return new LossResource($loss->refresh()->load(self::RELATIONS));
    }

    public function reject(Request $request, Loss $loss): LossResource
    {
        $data = $request->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:1000']],
            ['reason.required' => 'Le motif du refus est obligatoire.', 'reason.min' => 'Le motif doit contenir au moins 3 caractères.'],
        );
        $this->losses->reject($loss, $request->user(), $data['reason']);

        return new LossResource($loss->refresh()->load(self::RELATIONS));
    }
}
