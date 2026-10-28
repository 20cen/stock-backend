<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CashSessionResource;
use App\Models\CashSession;
use App\Services\CashSessionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Sessions de caisse. Le caissier voit les siennes ; le propriétaire (sales.view_all) voit toutes les caisses.
 */
class CashSessionController extends Controller
{
    public function __construct(private readonly CashSessionService $cash) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $sessions = CashSession::query()
            ->with('cashier')
            ->when(! $request->user()->hasPermission(Permission::SalesViewAll), fn (Builder $q) => $q->where('cashier_id', $request->user()->id))
            ->orderByDesc('id')
            ->paginate(25);

        return CashSessionResource::collection($sessions);
    }

    /** Session ouverte de l'utilisateur, avec le solde théorique en direct (ou null). */
    public function current(Request $request): JsonResponse
    {
        $session = $this->cash->current($request->user());

        return response()->json(['data' => $session ? $this->detailed($session) : null]);
    }

    public function show(Request $request, CashSession $cashSession): JsonResponse
    {
        $this->authorizeAccess($request, $cashSession);

        return response()->json(['data' => $this->detailed($cashSession)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(
            ['opening_amount' => ['required', 'decimal:0,2', 'min:0'], 'notes' => ['nullable', 'string', 'max:1000']],
            ['opening_amount.required' => 'Indiquez le fonds de caisse (0 s’il est vide).', 'opening_amount.decimal' => 'Le fonds de caisse doit avoir au plus 2 décimales.'],
        );

        $session = $this->cash->open($request->user(), $data['opening_amount'], $data['notes'] ?? null);

        return response()->json(['data' => $this->detailed($session)], 201);
    }

    public function close(Request $request, CashSession $cashSession): JsonResponse
    {
        $this->authorizeAccess($request, $cashSession);
        $data = $request->validate(
            [
                'declared_cash' => ['required', 'decimal:0,2', 'min:0'],
                'variance_reason' => ['nullable', 'string', 'max:1000'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ],
            ['declared_cash.required' => 'Indiquez le montant compté en espèces.', 'declared_cash.decimal' => 'Le montant compté doit avoir au plus 2 décimales.'],
        );

        $session = $this->cash->close($cashSession, $request->user(), $data['declared_cash'], $data['variance_reason'] ?? null, $data['notes'] ?? null);

        return response()->json(['data' => $this->detailed($session)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detailed(CashSession $session): array
    {
        $resource = new CashSessionResource($session->load('cashier'));
        $resource->withTotals = true;

        return $resource->resolve(request());
    }

    private function authorizeAccess(Request $request, CashSession $session): void
    {
        if ($session->cashier_id !== $request->user()->id && ! $request->user()->hasPermission(Permission::SalesViewAll)) {
            throw new BusinessException('Cette caisse appartient à un autre utilisateur.');
        }
    }
}
