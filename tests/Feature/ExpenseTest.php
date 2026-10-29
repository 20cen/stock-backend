<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private int $rent;

    private int $cash;

    private int $momo;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed([PaymentMethodSeeder::class, ExpenseCategorySeeder::class]);
        Sanctum::actingAs(User::factory()->owner()->create());
        $this->rent = ExpenseCategory::where('name', 'Loyer')->value('id');
        $this->cash = PaymentMethod::where('code', 'cash')->value('id');
        $this->momo = PaymentMethod::where('code', 'mobile_money')->value('id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function record(array $overrides = []): TestResponse
    {
        return $this->post('/api/v1/expenses', $overrides + [
            'expense_date' => now()->toDateString(),
            'label' => 'Loyer d’octobre',
            'expense_category_id' => $this->rent,
            'amount' => '150000',
            'payment_method_id' => $this->momo,
            'beneficiary' => 'M. Yao (bailleur)',
        ], ['Accept' => 'application/json']);
    }

    public function test_the_initial_categories_of_the_specification_are_seeded(): void
    {
        $this->getJson('/api/v1/expense-categories')->assertOk()->assertJsonCount(10, 'data');
    }

    public function test_an_expense_is_recorded_with_a_reference_and_a_receipt_file(): void
    {
        $year = now()->year;

        $id = $this->record(['attachment' => UploadedFile::fake()->create('quittance.pdf', 120, 'application/pdf')])
            ->assertCreated()
            ->assertJsonPath('data.reference', "DEP-{$year}-00001")
            ->assertJsonPath('data.category.name', 'Loyer')
            ->assertJsonPath('data.amount', '150000.00')
            ->assertJsonPath('data.attachment_name', 'quittance.pdf')
            ->assertJsonMissingPath('data.attachment_path')
            ->json('data.id');

        Storage::disk('local')->assertExists(Expense::find($id)->attachment_path);
        $this->get("/api/v1/expenses/{$id}/attachment")->assertOk()->assertDownload('quittance.pdf');
        $this->assertSame(1, AuditLog::where('action', 'expense.created')->count());
    }

    /** TST-019 : une dépense sans catégorie est refusée. */
    public function test_an_expense_without_category_is_refused(): void
    {
        $this->record(['expense_category_id' => ''])->assertUnprocessable()->assertJsonPath('errors.expense_category_id.0', 'La catégorie est obligatoire.');
        $this->record(['attachment' => UploadedFile::fake()->create('virus.exe', 10)])->assertUnprocessable()->assertJsonValidationErrors('attachment');
        $this->assertSame(0, Expense::count());
    }

    public function test_an_expense_paid_from_the_cash_drawer_reduces_the_expected_cash(): void
    {
        $this->record(['payment_method_id' => $this->cash, 'from_cash_drawer' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cash_session_required');

        $this->postJson('/api/v1/cash-sessions', ['opening_amount' => '50000']);
        $this->record(['payment_method_id' => $this->momo, 'from_cash_drawer' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Seule une dépense en espèces peut sortir du tiroir-caisse.');

        $id = $this->record(['amount' => '7500', 'label' => 'Taxi livraison', 'payment_method_id' => $this->cash, 'from_cash_drawer' => 1])
            ->assertCreated()
            ->assertJsonPath('data.from_cash_drawer', true)
            ->json('data.id');
        $this->getJson('/api/v1/cash-sessions/current')->assertJsonPath('data.expected_cash', '42500.00');

        // Annulée, elle ne compte plus dans la caisse.
        $this->postJson("/api/v1/expenses/{$id}/cancel", ['reason' => 'Saisie en double'])->assertOk();
        $this->getJson('/api/v1/cash-sessions/current')->assertJsonPath('data.expected_cash', '50000.00');
    }

    /** RG-DEP-04 : corrections tracées ; montant et mode : annuler puis ressaisir. */
    public function test_corrections_are_audited_and_the_amount_cannot_be_edited(): void
    {
        $id = $this->record()->json('data.id');

        $this->putJson("/api/v1/expenses/{$id}", ['label' => 'Loyer novembre'])->assertOk()->assertJsonPath('data.label', 'Loyer novembre');
        $log = AuditLog::where('action', 'expense.updated')->sole();
        $this->assertSame('Loyer d’octobre', $log->before_data['label']);

        $this->putJson("/api/v1/expenses/{$id}", ['amount' => '1'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'Le montant ne se modifie pas : annulez la dépense et ressaisissez-la.');

        $this->postJson("/api/v1/expenses/{$id}/cancel")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/expenses/{$id}/cancel", ['reason' => 'Mauvais montant'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Mauvais montant');
        $this->putJson("/api/v1/expenses/{$id}", ['label' => 'X'])->assertUnprocessable();
    }

    public function test_the_list_is_filtered_by_period_and_category_with_the_total_of_recorded_expenses(): void
    {
        $transport = ExpenseCategory::where('name', 'Transport')->value('id');
        $this->record();
        $this->record(['label' => 'Carburant', 'expense_category_id' => $transport, 'amount' => '20000']);
        $this->record(['label' => 'Ancien loyer', 'expense_date' => now()->subMonth()->toDateString()]);
        $cancelled = $this->record(['label' => 'Erreur', 'amount' => '999'])->json('data.id');
        $this->postJson("/api/v1/expenses/{$cancelled}/cancel", ['reason' => 'Erreur']);

        $from = now()->startOfMonth()->toDateString();
        $this->getJson("/api/v1/expenses?from={$from}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total_amount', '170000.00');
        $this->getJson("/api/v1/expenses?category_id={$transport}")->assertJsonCount(1, 'data')->assertJsonPath('total_amount', '20000.00');
        $this->getJson('/api/v1/expenses?status=cancelled')->assertJsonCount(1, 'data')->assertJsonPath('total_amount', '0.00');
    }

    public function test_categories_are_managed_without_duplicates_and_cashiers_have_no_access(): void
    {
        $this->postJson('/api/v1/expense-categories', ['name' => 'loyer'])->assertUnprocessable()->assertJsonPath('errors.name.0', 'Cette catégorie existe déjà.');
        $id = $this->postJson('/api/v1/expense-categories', ['name' => 'Publicité'])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/expense-categories/{$id}", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->record(['expense_category_id' => $id])->assertUnprocessable()->assertJsonPath('message', 'La catégorie « Publicité » est désactivée.');

        Sanctum::actingAs(User::factory()->cashier()->create());
        $this->getJson('/api/v1/expenses')->assertForbidden();
    }
}
