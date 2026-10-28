<?php

namespace Tests\Feature\Stock;

use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\User;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockApiTest extends TestCase
{
    use RefreshDatabase;

    private Article $article;

    private Location $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LocationSeeder::class);
        $this->article = Article::factory()->create(['designation' => 'Souris USB', 'alert_threshold' => 3]);
        $this->shop = Location::where('code', 'BTQ')->sole();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function adjust(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/stock/adjustments', $overrides + [
            'article_id' => $this->article->id,
            'location_id' => $this->shop->id,
            'quantity' => 10,
            'unit_cost' => '2500.50',
            'reason' => 'Stock initial',
        ]);
    }

    public function test_owner_adjusts_stock_with_a_reason_and_it_is_audited(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());

        $this->adjust()
            ->assertCreated()
            ->assertJsonPath('data.type', 'adjustment')
            ->assertJsonPath('data.quantity', '10.000')
            ->assertJsonPath('data.balance_after', '10.000')
            ->assertJsonPath('data.unit_cost', '2500.5000')
            ->assertJsonPath('data.reason', 'Stock initial');

        $this->assertSame('10.000', StockBalance::sole()->quantity_on_hand);
        $this->assertSame('Stock initial', AuditLog::where('action', 'stock.adjusted')->sole()->reason);

        $this->getJson('/api/v1/stock/balances')
            ->assertJsonPath('data.0.average_cost', '2500.5000')
            ->assertJsonPath('data.0.stock_value', '25005.00');
    }

    public function test_a_decimal_quantity_is_refused_for_an_article_counted_in_units(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());

        $this->adjust(['quantity' => 1.5])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'L’article « Souris USB » se compte en unités entières.');
    }

    public function test_an_adjustment_requires_a_reason_and_a_non_zero_quantity(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());

        $this->adjust(['reason' => '', 'quantity' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason', 'quantity']);
    }

    /** TST-009 via l'API : message clair et stock inchangé. */
    public function test_a_negative_adjustment_beyond_stock_is_refused_with_a_clear_message(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());
        $this->adjust(['quantity' => 2]);

        $this->adjust(['quantity' => -5, 'reason' => 'Casse'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Stock insuffisant pour « Souris USB » (Boutique) : 2 disponible(s), 5 demandé(s).')
            ->assertJsonPath('stock.available', '2.000');

        $this->assertSame('2.000', StockBalance::sole()->quantity_on_hand);
    }

    public function test_cashier_can_view_stock_but_not_adjust_it_nor_see_costs(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());
        $this->adjust(['quantity' => 2]);

        Sanctum::actingAs(User::factory()->cashier()->create());

        $this->adjust()->assertForbidden();

        $this->getJson('/api/v1/stock/balances')
            ->assertOk()
            ->assertJsonPath('data.0.quantity_on_hand', '2.000')
            ->assertJsonPath('data.0.is_low', true)
            ->assertJsonPath('data.0.location.code', 'BTQ')
            ->assertJsonMissingPath('data.0.average_cost')
            ->assertJsonMissingPath('data.0.stock_value');

        $this->getJson('/api/v1/stock/movements')
            ->assertOk()
            ->assertJsonPath('data.0.type_label', 'Correction manuelle')
            ->assertJsonMissingPath('data.0.unit_cost');
    }

    public function test_balances_can_be_filtered_by_search_and_low_stock(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());
        $other = Article::factory()->create(['designation' => 'Clavier AZERTY', 'alert_threshold' => 1]);
        $this->adjust(['quantity' => 2]);
        $this->adjust(['article_id' => $other->id, 'quantity' => 8]);

        $this->getJson('/api/v1/stock/balances?search=clavier')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.article.designation', 'Clavier AZERTY');

        $this->getJson('/api/v1/stock/balances?low=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.article.designation', 'Souris USB');
    }

    public function test_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/v1/stock/balances')->assertUnauthorized();
    }
}
