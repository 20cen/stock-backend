<?php

namespace Tests\Feature\Sales;

use App\Enums\MovementType;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use Database\Seeders\LocationSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([LocationSeeder::class, PaymentMethodSeeder::class]);
        $this->cashier = User::factory()->cashier()->create();
        Sanctum::actingAs($this->cashier);
    }

    /** Vend 3 souris : 15 000 en espèces et 7 500 en Mobile Money. */
    private function openAndSell(): int
    {
        $article = Article::factory()->create(['sale_price' => '7500']);
        app(StockService::class)->apply(
            [StockChange::in($article->id, Location::where('code', 'BTQ')->value('id'), MovementType::Adjustment, 10)],
            User::factory()->owner()->create(),
        );

        $id = $this->postJson('/api/v1/cash-sessions', ['opening_amount' => '20000'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/sales', [
            'lines' => [['article_id' => $article->id, 'quantity' => 3]],
            'payments' => [
                ['payment_method_id' => PaymentMethod::where('code', 'cash')->value('id'), 'amount' => '15000'],
                ['payment_method_id' => PaymentMethod::where('code', 'mobile_money')->value('id'), 'amount' => '7500'],
            ],
        ])->assertCreated();

        return $id;
    }

    public function test_a_cashier_opens_one_session_at_a_time(): void
    {
        $year = now()->year;

        $this->getJson('/api/v1/cash-sessions/current')->assertOk()->assertJsonPath('data', null);
        $this->postJson('/api/v1/cash-sessions', ['opening_amount' => '20000'])
            ->assertCreated()
            ->assertJsonPath('data.reference', "CAI-{$year}-00001")
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.expected_cash', '20000.00');

        $this->postJson('/api/v1/cash-sessions', ['opening_amount' => '0'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Votre caisse est déjà ouverte.');
    }

    public function test_the_live_balance_is_computed_per_payment_method(): void
    {
        $this->openAndSell();

        $response = $this->getJson('/api/v1/cash-sessions/current')
            ->assertOk()
            ->assertJsonPath('data.expected_cash', '35000.00')   // 20 000 de fonds + 15 000 en espèces
            ->assertJsonPath('data.sales_count', 1)
            ->assertJsonPath('data.sales_total', '22500.00');

        $methods = collect($response->json('data.totals_by_method'))->pluck('amount', 'code');
        $this->assertSame('15000.00', $methods['cash']);
        $this->assertSame('7500.00', $methods['mobile_money']);
    }

    /** TST-026 : écart calculé ; clôture impossible sans motif ; écart historisé. */
    public function test_closing_with_a_variance_requires_a_reason_and_is_recorded(): void
    {
        $id = $this->openAndSell();

        $this->postJson("/api/v1/cash-sessions/{$id}/close", ['declared_cash' => '34500'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'variance_reason_required')
            ->assertJsonPath('message', 'Écart de -500 entre le montant compté et le solde théorique : indiquez le motif.');

        $this->postJson("/api/v1/cash-sessions/{$id}/close", ['declared_cash' => '34500', 'variance_reason' => 'Erreur de rendu de monnaie'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.expected_cash', '35000.00')
            ->assertJsonPath('data.declared_cash', '34500.00')
            ->assertJsonPath('data.variance', '-500.00')
            ->assertJsonPath('data.variance_reason', 'Erreur de rendu de monnaie');

        $log = AuditLog::where('action', 'cash.closed')->sole();
        $this->assertSame('-500.00', $log->after_data['variance']);
        $this->assertSame('Erreur de rendu de monnaie', $log->reason);
    }

    public function test_closing_without_variance_needs_no_reason_and_a_closed_session_stays_closed(): void
    {
        $id = $this->openAndSell();

        $this->postJson("/api/v1/cash-sessions/{$id}/close", ['declared_cash' => '35000'])->assertOk()->assertJsonPath('data.variance', '0.00');
        $this->postJson("/api/v1/cash-sessions/{$id}/close", ['declared_cash' => '35000'])->assertUnprocessable();

        // Après clôture, une nouvelle vente exige de rouvrir la caisse.
        $this->postJson('/api/v1/sales', ['lines' => [['article_id' => 1, 'quantity' => 1]], 'payments' => []])
            ->assertUnprocessable()->assertJsonPath('code', 'cash_session_required');
    }

    public function test_a_cashier_cannot_close_someone_elses_session_but_the_owner_sees_all_sessions(): void
    {
        $id = $this->openAndSell();

        Sanctum::actingAs(User::factory()->cashier()->create());
        $this->postJson("/api/v1/cash-sessions/{$id}/close", ['declared_cash' => '0'])->assertUnprocessable();
        $this->getJson('/api/v1/cash-sessions')->assertJsonCount(0, 'data');

        Sanctum::actingAs(User::factory()->owner()->create());
        $this->getJson('/api/v1/cash-sessions')->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'open');
    }
}
