<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\AuditLogger;
use App\Services\ExpenseService;
use App\Support\Decimal;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dépenses et catégories de dépenses (expenses.manage).
 */
class ExpenseController extends Controller
{
    private const RELATIONS = ['category', 'method', 'creator', 'canceller'];

    private const ATTACHMENT_RULE = ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'];

    private const MESSAGES = [
        'expense_date.required' => 'La date est obligatoire.',
        'expense_date.before_or_equal' => 'La date ne peut pas être dans le futur.',
        'label.required' => 'Le libellé est obligatoire.',
        'expense_category_id.required' => 'La catégorie est obligatoire.',
        'amount.required' => 'Le montant est obligatoire.',
        'amount.decimal' => 'Le montant doit avoir au plus 2 décimales.',
        'amount.gt' => 'Le montant doit être positif.',
        'payment_method_id.required' => 'Le mode de paiement est obligatoire.',
        'attachment.mimes' => 'Justificatif : PDF ou image (JPG, PNG, WebP).',
        'attachment.max' => 'Justificatif : 5 Mo au maximum.',
    ];

    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:recorded,cancelled,all'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $status = $filters['status'] ?? Expense::RECORDED;

        $query = Expense::query()
            ->when($status !== 'all', fn (Builder $q) => $q->where('status', $status))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('expense_category_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('expense_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('expense_date', '<=', $to))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw('LOWER(label) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(beneficiary) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(reference) LIKE ?', [$like]));
            });

        // Total des dépenses enregistrées correspondant aux filtres (les annulées ne comptent jamais).
        $total = Decimal::round((string) ((clone $query)->where('status', Expense::RECORDED)->sum('amount') ?: '0'), 2);

        $expenses = $query->with(self::RELATIONS)->orderByDesc('expense_date')->orderByDesc('id')->paginate(25);

        return ExpenseResource::collection($expenses)->additional(['total_amount' => $total]);
    }

    public function show(Expense $expense): ExpenseResource
    {
        return new ExpenseResource($expense->load(self::RELATIONS));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'label' => ['required', 'string', 'max:255'],
            'expense_category_id' => ['required', 'integer'],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'payment_method_id' => ['required', 'integer'],
            'beneficiary' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'from_cash_drawer' => ['sometimes', 'boolean'],
            'attachment' => ['nullable', ...self::ATTACHMENT_RULE],
        ], self::MESSAGES);

        $expense = $this->expenses->create($data, $request->file('attachment'), $request->user());

        return (new ExpenseResource($expense->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(Request $request, Expense $expense): ExpenseResource
    {
        $data = $request->validate([
            'expense_date' => ['sometimes', 'date', 'before_or_equal:today'],
            'label' => ['sometimes', 'string', 'max:255'],
            'expense_category_id' => ['sometimes', 'integer'],
            'beneficiary' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Montant et mode de paiement : annuler puis ressaisir (traçabilité des caisses).
            'amount' => ['prohibited'],
            'payment_method_id' => ['prohibited'],
        ], self::MESSAGES + [
            'amount.prohibited' => 'Le montant ne se modifie pas : annulez la dépense et ressaisissez-la.',
            'payment_method_id.prohibited' => 'Le mode de paiement ne se modifie pas : annulez la dépense et ressaisissez-la.',
        ]);

        return new ExpenseResource($this->expenses->update($expense, $data, $request->user())->load(self::RELATIONS));
    }

    public function attach(Request $request, Expense $expense): ExpenseResource
    {
        $request->validate(['attachment' => ['required', ...self::ATTACHMENT_RULE]], self::MESSAGES);

        return new ExpenseResource($this->expenses->attach($expense, $request->file('attachment'), $request->user())->load(self::RELATIONS));
    }

    public function attachment(Expense $expense): StreamedResponse|JsonResponse
    {
        if (! $expense->attachment_path || ! Storage::disk('local')->exists($expense->attachment_path)) {
            return response()->json(['message' => 'Aucun justificatif pour cette dépense.'], 404);
        }

        return Storage::disk('local')->download($expense->attachment_path, $expense->attachment_name);
    }

    public function cancel(Request $request, Expense $expense): ExpenseResource
    {
        $data = $request->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:500']],
            ['reason.required' => 'Le motif d’annulation est obligatoire.', 'reason.min' => 'Le motif doit contenir au moins 3 caractères.'],
        );

        return new ExpenseResource($this->expenses->cancel($expense, $request->user(), $data['reason'])->load(self::RELATIONS));
    }

    // --- Catégories ---

    public function categories(): JsonResponse
    {
        return response()->json(['data' => ExpenseCategory::withCount('expenses')->orderBy('name')->get()->map(fn (ExpenseCategory $c) => [
            'id' => $c->id, 'name' => $c->name, 'active' => $c->active, 'expenses_count' => $c->expenses_count,
        ])]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', $this->uniqueName()]], ['name.required' => 'Le nom est obligatoire.']);
        $category = ExpenseCategory::create(['name' => trim($data['name'])])->refresh();
        $this->audit->log('expense_category.created', $category, after: ['name' => $category->name]);

        return response()->json(['data' => ['id' => $category->id, 'name' => $category->name, 'active' => $category->active, 'expenses_count' => 0]], 201);
    }

    public function updateCategory(Request $request, ExpenseCategory $category): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', $this->uniqueName($category->id)],
            'active' => ['sometimes', 'boolean'],
        ]);
        $before = $category->only(['name', 'active']);
        $category->update(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data));
        $this->audit->log('expense_category.updated', $category, before: $before, after: $category->only(['name', 'active']));

        return response()->json(['data' => ['id' => $category->id, 'name' => $category->name, 'active' => $category->active, 'expenses_count' => $category->expenses()->count()]]);
    }

    private function uniqueName(?int $ignore = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore) {
            $exists = ExpenseCategory::whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])
                ->when($ignore, fn ($q) => $q->whereKeyNot($ignore))
                ->exists();
            if ($exists) {
                $fail('Cette catégorie existe déjà.');
            }
        };
    }
}
