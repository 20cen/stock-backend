<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryResource;
use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Inventaires physiques (inventories.manage).
 */
class InventoryController extends Controller
{
    private const DETAIL = ['location', 'category', 'creator', 'closer', 'lines.article'];

    public function __construct(private readonly InventoryService $inventories) {}

    public function index(): AnonymousResourceCollection
    {
        $inventories = Inventory::query()
            ->with(['location', 'category', 'creator', 'closer'])
            ->withCount(['lines', 'lines as counted_count' => fn (Builder $q) => $q->whereNotNull('counted_quantity')])
            ->orderByRaw("CASE WHEN status = 'in_progress' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->paginate(25);

        return InventoryResource::collection($inventories);
    }

    public function show(Inventory $inventory): InventoryResource
    {
        return new InventoryResource($this->detailed($inventory));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['location_id.required' => 'Choisissez l’emplacement à inventorier.']);

        $inventory = $this->inventories->create((int) $data['location_id'], isset($data['category_id']) ? (int) $data['category_id'] : null, $data['notes'] ?? null, $request->user());

        return (new InventoryResource($this->detailed($inventory)))->response()->setStatusCode(201);
    }

    public function counts(Request $request, Inventory $inventory): InventoryResource
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:1000'],
            'lines.*.article_id' => ['required', 'integer'],
            'lines.*.counted_quantity' => ['nullable', 'decimal:0,3', 'min:0'],
            'lines.*.reason' => ['nullable', 'string', 'max:500'],
        ], ['lines.*.counted_quantity.decimal' => 'Quantité : 3 décimales au plus.', 'lines.*.counted_quantity.min' => 'Une quantité comptée ne peut pas être négative.']);

        $this->inventories->saveCounts($inventory, $data['lines'], $request->user());

        return new InventoryResource($this->detailed($inventory));
    }

    public function validateInventory(Request $request, Inventory $inventory): InventoryResource
    {
        $this->inventories->validate($inventory, $request->user());

        return new InventoryResource($this->detailed($inventory));
    }

    public function cancel(Request $request, Inventory $inventory): InventoryResource
    {
        $data = $request->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:500']],
            ['reason.required' => 'Le motif d’annulation est obligatoire.', 'reason.min' => 'Le motif doit contenir au moins 3 caractères.'],
        );
        $this->inventories->cancel($inventory, $request->user(), $data['reason']);

        return new InventoryResource($this->detailed($inventory));
    }

    private function detailed(Inventory $inventory): Inventory
    {
        return $inventory->refresh()->load(self::DETAIL)->setRelation(
            'lines',
            $inventory->lines->sortBy(fn ($l) => mb_strtolower($l->article->designation))->values(),
        );
    }
}
