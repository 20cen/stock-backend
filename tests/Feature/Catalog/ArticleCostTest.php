<?php

namespace Tests\Feature\Catalog;

use App\Enums\MovementType;
use App\Models\Article;
use App\Models\Location;
use App\Models\Loss;
use App\Models\PaymentMethod;
use App\Models\SaleLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use Database\Seeders\LocationSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Article entré en stock sans coût (ex. R12 en recette) : le propriétaire définit le coût moyen,
 * et les opérations restées sans coût le reçoivent.
 */
class ArticleCostTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Article $ram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([LocationSeeder::class, PaymentMethodSeeder::class]);
        $this->owner = User::factory()->owner()->create();
        $this->ram = Article::factory()->create(['designation' => 'RAM 8 Go', 'sale_price' => '20000']);
        $shop = Location::where('code', 'BTQ')->value('id');

        // Stock initial sans coût, puis une vente et une perte valorisées « sans coût ».
        app(StockService::class)->apply([StockChange::in($this->ram->id, $shop, MovementType::Adjustment, 10)], $this->owner);
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/cash-sessions', ['opening_amount' => '0'])->assertCreated();
        $this->postJson('/api/v1/sales', [
            'lines' => [['article_id' => $this->ram->id, 'quantity' => 2]],
            'payments' => [['payment_method_id' => PaymentMethod::where('code', 'cash')->value('id'), 'amount' => '40000']],
        ])->assertCreated();
        $this->postJson('/api/v1/losses', ['article_id' => $this->ram->id, 'location_id' => $shop, 'quantity' => 1, 'reason' => 'breakage'])->assertCreated();
    }

    public function test_setting_the_cost_fills_the_operations_left_without_cost(): void
    {
        $this->assertNull($this->ram->refresh()->average_cost);

        $this->postJson("/api/v1/articles/{$this->ram->id}/cost", ['average_cost' => '12500', 'reason' => 'Coût oublié au stock initial'])
            ->assertOk()
            ->assertJsonPath('data.average_cost', '12500.0000');

        $this->assertSame(0, StockMovement::where('article_id', $this->ram->id)->whereNull('unit_cost')->count());
        $this->assertSame('12500.0000', SaleLine::where('article_id', $this->ram->id)->value('unit_cost_snapshot'));
        $this->assertSame('12500.00', Loss::where('article_id', $this->ram->id)->value('estimated_value'));

        $log = $this->getJson('/api/v1/audit-logs?action=article.cost_set')->assertOk()->json('data.0');
        $this->assertSame('Coût moyen défini', $log['action_label']);
        $this->assertSame('Coût oublié au stock initial', $log['reason']);
        $this->assertNull($log['before']['average_cost']);
        $this->assertSame(1, $log['after']['sale_lines']);
        $this->assertSame(1, $log['after']['losses']);
    }

    public function test_known_costs_of_past_operations_are_kept(): void
    {
        $this->postJson("/api/v1/articles/{$this->ram->id}/cost", ['average_cost' => '12500', 'reason' => 'Coût oublié'])->assertOk();
        $this->postJson("/api/v1/articles/{$this->ram->id}/cost", ['average_cost' => '13000', 'reason' => 'Correction'])->assertOk()
            ->assertJsonPath('data.average_cost', '13000.0000');

        $this->assertSame('12500.0000', SaleLine::where('article_id', $this->ram->id)->value('unit_cost_snapshot'));
    }

    public function test_the_cost_is_validated_and_reserved_to_the_owner(): void
    {
        $this->postJson("/api/v1/articles/{$this->ram->id}/cost", ['average_cost' => '0', 'reason' => 'x'])
            ->assertJsonValidationErrors(['average_cost', 'reason']);

        Sanctum::actingAs(User::factory()->cashier()->create());
        $this->postJson("/api/v1/articles/{$this->ram->id}/cost", ['average_cost' => '12500', 'reason' => 'Coût oublié'])->assertForbidden();
    }
}
