<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\Article;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\LocationSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([LocationSeeder::class, PaymentMethodSeeder::class, ExpenseCategorySeeder::class]);
        $this->owner = User::factory()->owner()->create();
        $this->cashier = User::factory()->cashier()->create();
    }

    /**
     * Scénario : 2 souris (CMP 5 000, vendues 7 500), 1 annulée, 1 retour validé et remboursé,
     * 1 dépense de 10 000, 1 perte d'une souris.
     */
    private function scenario(): void
    {
        $mouse = Article::factory()->create(['designation' => 'Souris USB', 'sale_price' => '7500']);
        $shop = Location::where('code', 'BTQ')->value('id');
        $cash = PaymentMethod::where('code', 'cash')->value('id');
        app(StockService::class)->apply([StockChange::in($mouse->id, $shop, MovementType::Adjustment, 10, '5000')], $this->owner);

        // Caissier : 2 ventes de 2 souris (15 000 chacune).
        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/v1/cash-sessions', ['opening_amount' => '0']);
        $sell = fn () => $this->postJson('/api/v1/sales', [
            'lines' => [['article_id' => $mouse->id, 'quantity' => 2]],
            'payments' => [['payment_method_id' => $cash, 'amount' => '15000']],
        ])->assertCreated()->json('data');
        $first = $sell();
        $second = $sell();

        // Propriétaire : annule la 2e vente, valide un retour d'1 souris sur la 1re avec remboursement.
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/cash-sessions', ['opening_amount' => '50000']);
        $this->postJson("/api/v1/sales/{$second['id']}/cancel", ['reason' => 'Erreur'])->assertOk();
        $this->postJson('/api/v1/returns', [
            'sale_id' => $first['id'], 'reason' => 'Défaut', 'validate' => true,
            'lines' => [['sale_line_id' => $first['lines'][0]['id'], 'quantity' => 1, 'condition' => 'damaged']],
            'refund' => ['amount' => '7500', 'payment_method_id' => $cash],
        ])->assertCreated();

        $this->post('/api/v1/expenses', [
            'expense_date' => now()->toDateString(), 'label' => 'Électricité', 'amount' => '10000',
            'expense_category_id' => ExpenseCategory::where('name', 'Électricité')->value('id'), 'payment_method_id' => $cash,
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson('/api/v1/losses', ['article_id' => $mouse->id, 'location_id' => $shop, 'quantity' => 1, 'reason' => 'breakage'])->assertCreated();
    }

    public function test_owner_sees_net_sales_receipts_expenses_losses_and_margin(): void
    {
        $this->scenario();

        $data = $this->getJson('/api/v1/dashboard?period=today')->assertOk()->json('data');
        $f = $data['financial'];

        $this->assertSame('15000.00', $f['gross_sales']);       // vente annulée exclue
        $this->assertSame('7500.00', $f['returns']);
        $this->assertSame('7500.00', $f['net_sales']);
        $this->assertSame(1, $f['sales_count']);
        $this->assertSame('30000.00', $f['payments']);          // 2 encaissements de 15 000
        $this->assertSame('22500.00', $f['refunds']);           // annulation 15 000 + retour 7 500
        $this->assertSame('7500.00', $f['net_receipts']);
        $this->assertSame('10000.00', $f['expenses']);
        $this->assertSame('5000.00', $f['losses']);
        // Marge : vente 15 000 − 2 × 5 000 = 5 000 ; retour 7 500 − 5 000 = 2 500 → 2 500.
        $this->assertSame('2500.00', $f['estimated_margin']);
        $this->assertSame('Souris USB', $f['top_articles'][0]['designation']);
        $this->assertCount(1, $f['daily_sales']);
        $this->assertSame('15000.00', $f['daily_sales'][0]['total']);
        $this->assertCount(2, $data['activity']['sales']);
    }

    public function test_the_period_filter_excludes_other_days(): void
    {
        $this->scenario();
        $yesterday = now()->subDay()->toDateString();

        $f = $this->getJson("/api/v1/dashboard?period=custom&from={$yesterday}&to={$yesterday}")->json('data.financial');
        $this->assertSame('0.00', $f['gross_sales']);
        $this->assertSame('0.00', $f['expenses']);

        $this->assertCount(7, $this->getJson('/api/v1/dashboard?period=7d')->json('data.financial.daily_sales'));
        $this->getJson('/api/v1/dashboard?period=custom&from=2026-10-10&to=2026-10-01')->assertUnprocessable();
    }

    public function test_cashier_sees_their_day_and_pending_requests_but_no_financial_data(): void
    {
        $this->scenario();
        Sanctum::actingAs($this->cashier);

        $data = $this->getJson('/api/v1/dashboard')->assertOk()->json('data');

        $this->assertArrayNotHasKey('financial', $data);
        $this->assertSame(1, $data['my_day']['sales_count']);   // l'autre vente a été annulée
        $this->assertSame('15000.00', $data['my_day']['sales_total']);
        $this->assertSame([], $data['activity']['receipts']);
        $this->assertSame(0, $data['pending']['inventories']);
    }
}
