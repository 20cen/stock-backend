<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Tableau de bord : indicateurs financiers pour reports.financial, ventes du jour pour le caissier,
 * alertes et activité pour tous (filtrées selon les droits).
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['nullable', 'in:today,7d,month,custom'],
            'from' => ['required_if:period,custom', 'nullable', 'date'],
            'to' => ['required_if:period,custom', 'nullable', 'date', 'after_or_equal:from'],
        ], ['from.required_if' => 'Indiquez la date de début.', 'to.after_or_equal' => 'La date de fin doit suivre la date de début.']);

        [$from, $to] = $this->range($data['period'] ?? 'today', $data['from'] ?? null, $data['to'] ?? null);
        $user = $request->user();
        $financial = $user->hasPermission(Permission::ReportsFinancial);
        $seesAll = $user->hasPermission(Permission::SalesViewAll);

        return response()->json(['data' => array_filter([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'financial' => $financial ? $this->dashboard->financial($from, $to) : null,
            'my_day' => $financial ? null : $this->dashboard->cashierToday($user),
            'pending' => $this->dashboard->pending($user, $seesAll),
            'activity' => $this->dashboard->activity($user, $seesAll),
        ], fn ($v) => $v !== null)]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(string $period, ?string $from, ?string $to): array
    {
        return match ($period) {
            '7d' => [now()->subDays(6)->startOfDay(), now()->endOfDay()],
            'month' => [now()->startOfMonth(), now()->endOfDay()],
            'custom' => [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()],
            default => [now()->startOfDay(), now()->endOfDay()],
        };
    }
}
