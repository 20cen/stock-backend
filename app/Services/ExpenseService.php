<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Dépenses d'exploitation (SFD-DEP, RG-DEP-01 à 04).
 *
 * - Catégorie obligatoire (TST-019) ; distinctes des achats de marchandises.
 * - Correction possible des champs descriptifs (tracée) ; le montant et le mode de paiement ne se corrigent
 *   pas : on annule (motif) et on ressaisit, pour ne jamais fausser une caisse.
 * - Payée « depuis le tiroir » : rattachée à la caisse ouverte de l'utilisateur, déduite des espèces attendues.
 * - Justificatif stocké hors du dossier public, téléchargeable seulement via l'API.
 */
class ExpenseService
{
    private const EDITABLE = ['expense_date', 'label', 'expense_category_id', 'beneficiary', 'notes'];

    public function __construct(
        private readonly CashSessionService $cash,
        private readonly DocumentNumber $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?UploadedFile $attachment, User $by): Expense
    {
        $this->assertCategory((int) $data['expense_category_id']);
        try {
            $amount = Decimal::normalize((string) $data['amount'], 2);
        } catch (InvalidArgumentException) {
            throw new BusinessException('Montant invalide.');
        }
        if (bccomp($amount, '0', 2) <= 0) {
            throw new BusinessException('Le montant doit être positif.');
        }
        $method = PaymentMethod::where('active', true)->find($data['payment_method_id']) ?? throw new BusinessException('Mode de paiement invalide.');

        $sessionId = null;
        if (! empty($data['from_cash_drawer'])) {
            if ($method->code !== CashSessionService::CASH) {
                throw new BusinessException('Seule une dépense en espèces peut sortir du tiroir-caisse.');
            }
            $sessionId = $this->cash->current($by)?->id
                ?? throw new BusinessException('Ouvrez votre caisse pour payer cette dépense depuis le tiroir.', 'cash_session_required');
        }

        $path = $attachment?->store('expenses/'.now()->format('Y'), 'local');

        try {
            return DB::transaction(function () use ($data, $amount, $method, $sessionId, $attachment, $path, $by) {
                $expense = Expense::create([
                    'reference' => $this->numbers->next('DEP'),
                    'expense_date' => $data['expense_date'],
                    'label' => $data['label'],
                    'expense_category_id' => $data['expense_category_id'],
                    'amount' => $amount,
                    'payment_method_id' => $method->id,
                    'beneficiary' => $data['beneficiary'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'attachment_path' => $path,
                    'attachment_name' => $attachment?->getClientOriginalName(),
                    'cash_session_id' => $sessionId,
                    'status' => Expense::RECORDED,
                    'created_by' => $by->id,
                ]);
                $this->audit->log('expense.created', $expense, after: Arr::except($expense->toArray(), ['id', 'created_at', 'updated_at']), userId: $by->id);

                return $expense;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path); // pas de fichier orphelin si l'enregistrement échoue
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Expense $expense, array $data, User $by): Expense
    {
        if ($expense->status !== Expense::RECORDED) {
            throw new BusinessException('Une dépense annulée ne peut plus être modifiée.');
        }
        if (isset($data['expense_category_id'])) {
            $this->assertCategory((int) $data['expense_category_id'], $expense->expense_category_id);
        }

        return DB::transaction(function () use ($expense, $data, $by) {
            $before = Arr::only($expense->toArray(), self::EDITABLE);
            $expense->update(Arr::only($data, self::EDITABLE));
            $changed = array_intersect(array_keys($expense->getChanges()), self::EDITABLE);
            if ($changed) {
                $this->audit->log('expense.updated', $expense, before: Arr::only($before, $changed), after: Arr::only($expense->toArray(), $changed), userId: $by->id);
            }

            return $expense;
        });
    }

    public function attach(Expense $expense, UploadedFile $file, User $by): Expense
    {
        if ($expense->status !== Expense::RECORDED) {
            throw new BusinessException('Une dépense annulée ne peut plus être modifiée.');
        }
        $old = $expense->attachment_path;
        $path = $file->store('expenses/'.now()->format('Y'), 'local');
        $expense->update(['attachment_path' => $path, 'attachment_name' => $file->getClientOriginalName()]);
        if ($old) {
            Storage::disk('local')->delete($old);
        }
        $this->audit->log('expense.attachment', $expense, after: ['attachment_name' => $expense->attachment_name], userId: $by->id);

        return $expense;
    }

    public function cancel(Expense $expense, User $by, string $reason): Expense
    {
        return DB::transaction(function () use ($expense, $by, $reason) {
            $expense = Expense::lockForUpdate()->findOrFail($expense->id);
            if ($expense->status !== Expense::RECORDED) {
                throw new BusinessException('Cette dépense est déjà annulée.');
            }
            $expense->update(['status' => Expense::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => $reason]);
            $this->audit->log('expense.cancelled', $expense, before: ['status' => Expense::RECORDED, 'amount' => $expense->amount], after: ['status' => Expense::CANCELLED], reason: $reason, userId: $by->id);

            return $expense;
        });
    }

    private function assertCategory(int $id, ?int $current = null): void
    {
        $category = ExpenseCategory::find($id) ?? throw new BusinessException('Catégorie introuvable.');
        if (! $category->active && $category->id !== $current) {
            throw new BusinessException("La catégorie « {$category->name} » est désactivée.");
        }
    }
}
