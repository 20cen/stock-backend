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

/**
 * TST-020 : les rapports ne comptent que les opérations validées et concordent avec le tableau de bord.
 */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([LocationSeeder::class, PaymentMethodSeeder::class, ExpenseCategorySeeder::class]);
        $this->owner = User::factory()->owner()->create();
        $this->cashier = User::factory()->cashier()->create(['name' => 'Awa Caisse']);
    }

    /**
     * Même scénario que le tableau de bord : 2 ventes de 2 souris (CMP 5 000, vendues 7 500), 1 annulée,
     * 1 retour validé et remboursé, 1 dépense de 10 000, 1 perte d'une souris.
     */
    private function scenario(): void
    {
        $mouse = Article::factory()->create(['designation' => 'Souris USB', 'sale_price' => '7500']);
        $shop = Location::where('code', 'BTQ')->value('id');
        $cash = PaymentMethod::where('code', 'cash')->value('id');
        app(StockService::class)->apply([StockChange::in($mouse->id, $shop, MovementType::Adjustment, 10, '5000')], $this->owner);

        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/v1/cash-sessions', ['opening_amount' => '0']);
        $sell = fn () => $this->postJson('/api/v1/sales', [
            'lines' => [['article_id' => $mouse->id, 'quantity' => 2]],
            'payments' => [['payment_method_id' => $cash, 'amount' => '15000']],
        ])->assertCreated()->json('data');
        $first = $sell();
        $second = $sell();

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

    private function report(string $key, string $query = ''): array
    {
        $today = now()->toDateString();

        return $this->getJson("/api/v1/reports/{$key}?from={$today}&to={$today}{$query}")->assertOk()->json('data');
    }

    public function test_summary_gives_the_estimated_result_consistent_with_the_dashboard(): void
    {
        $this->scenario();

        $amounts = collect($this->report('summary')['rows'])->pluck('amount', 'label');
        $dashboard = $this->getJson('/api/v1/dashboard?period=today')->json('data.financial');

        $this->assertSame($dashboard['net_sales'], $amounts['Chiffre d’affaires net']);
        $this->assertSame('7500.00', $amounts['Chiffre d’affaires net']);
        $this->assertSame('-5000.00', $amounts['Coût des marchandises vendues (CMP)']);
        $this->assertSame('2500.00', $amounts['Marge commerciale estimée']);
        $this->assertSame('-10000.00', $amounts['Dépenses d’exploitation']);
        $this->assertSame('-5000.00', $amounts['Pertes validées']);
        // 2 500 − 10 000 − 5 000 = −12 500
        $this->assertSame('-12500.00', $amounts['Résultat estimatif']);
    }

    public function test_sales_reports_exclude_cancelled_sales(): void
    {
        $this->scenario();

        $byDay = $this->report('sales_by_day');
        $this->assertCount(1, $byDay['rows']);
        $this->assertSame(1, $byDay['rows'][0]['count']);
        $this->assertSame('15000.00', $byDay['rows'][0]['total']);
        $this->assertSame('7500.00', $byDay['totals']['net']);

        $byArticle = $this->report('sales_by_article');
        $this->assertSame('2.000', $byArticle['rows'][0]['quantity']);
        $this->assertSame('15000.00', $byArticle['rows'][0]['revenue']);
        $this->assertSame('5000.00', $byArticle['rows'][0]['margin']);
        $this->assertSame('0.3333', $byArticle['totals']['margin_rate']);

        $byCashier = collect($this->report('sales_by_cashier')['rows'])->keyBy('cashier');
        $this->assertSame(1, $byCashier['Awa Caisse']['count']);
        $this->assertSame(1, $byCashier['Awa Caisse']['cancelled']);

        // Filtre par caissier : le propriétaire n'a rien vendu.
        $this->assertSame([], $this->report('sales_by_day', "&cashier_id={$this->owner->id}")['rows']);
    }

    public function test_financial_and_stock_reports(): void
    {
        $this->scenario();

        $payments = $this->report('payments_by_method');
        $this->assertSame('30000.00', $payments['totals']['received']);
        $this->assertSame('22500.00', $payments['totals']['refunded']);

        $this->assertSame('10000.00', $this->report('expenses')['totals']['amount']);
        $this->assertSame('5000.00', $this->report('losses')['totals']['value']);

        $stock = $this->report('stock_valuation');
        $quantity = array_sum(array_map(fn ($r) => (float) $r['quantity'], $stock['rows']));
        $this->assertGreaterThan(0, $quantity);
        $this->assertSame(number_format($quantity * 5000, 2, '.', ''), $stock['totals']['value']);
        $this->assertStringStartsWith('Situation au', $stock['scope']);
    }

    public function test_exports_to_excel_and_pdf(): void
    {
        $this->scenario();
        $today = now()->toDateString();

        $xlsx = $this->get("/api/v1/reports/sales_by_article?from={$today}&to={$today}&format=xlsx")->assertOk();
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="rapport-sales-by-article-', $xlsx->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('PK', $xlsx->getContent());

        $pdf = $this->get("/api/v1/reports/summary?from={$today}&to={$today}&format=pdf")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Rapport vide : l'export reste valide.
        $this->get('/api/v1/reports/receivables?format=pdf')->assertOk();
    }

    public function test_reports_are_reserved_to_the_financial_permission(): void
    {
        Sanctum::actingAs($this->cashier);
        $this->getJson('/api/v1/reports')->assertForbidden();
        $this->getJson('/api/v1/reports/summary')->assertForbidden();

        Sanctum::actingAs($this->owner);
        $catalog = $this->getJson('/api/v1/reports')->assertOk()->json();
        $this->assertCount(12, $catalog['data']);
        $this->assertArrayHasKey('category_id', $catalog['filters']);
        $this->getJson('/api/v1/reports/nope')->assertUnprocessable();
        $this->getJson('/api/v1/reports/summary?from=2026-10-10&to=2026-10-01')->assertUnprocessable();
    }
}
