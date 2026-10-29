<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Reports\ReportExporter;
use App\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rapports (reports.financial) : consultation à l'écran et export Excel ou PDF (TST-020).
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportExporter $exporter,
    ) {}

    /**
     * Catalogue des rapports et listes des filtres disponibles.
     */
    public function index(): JsonResponse
    {
        $options = fn ($query) => $query->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'data' => $this->reports->catalog(),
            'filters' => [
                'category_id' => $options(Category::query()),
                'location_id' => Location::activeInOrder()->map->only(['id', 'name'])->values(),
                // Utilisateurs ayant déjà vendu (y compris désactivés depuis), pour filtrer l'historique.
                'cashier_id' => $options(User::whereIn('id', Sale::select('cashier_id')->distinct())),
                'supplier_id' => $options(Supplier::query()),
                'expense_category_id' => $options(ExpenseCategory::query()),
            ],
        ]);
    }

    public function show(Request $request, string $key): JsonResponse|Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'category_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'cashier_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'expense_category_id' => ['nullable', 'integer'],
            'format' => ['nullable', 'in:json,xlsx,pdf'],
        ], ['to.after_or_equal' => 'La date de fin doit suivre la date de début.']);

        $report = $this->reports->run($key, $filters);

        return match ($filters['format'] ?? 'json') {
            'xlsx' => $this->download($this->exporter->xlsx($report), $this->exporter->filename($report, 'xlsx'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            'pdf' => $this->download($this->exporter->pdf($report), $this->exporter->filename($report, 'pdf'), 'application/pdf'),
            default => response()->json(['data' => $report->toArray()]),
        };
    }

    private function download(string $content, string $filename, string $type): Response
    {
        return response($content, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($content),
        ]);
    }
}
