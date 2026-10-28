<?php

namespace Tests\Feature\Catalog;

use App\Enums\MovementType;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Location;
use App\Models\User;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArticleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LocationSeeder::class);
        $this->owner = User::factory()->owner()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function create(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/articles', $overrides + [
            'reference' => 'sour-usb-01',
            'barcode' => '3760123456789',
            'designation' => 'Souris USB optique',
            'brand' => 'Logitech',
            'sale_price' => '7500',
            'purchase_price_reference' => '5000.50',
            'alert_threshold' => 3,
        ]);
    }

    private function stockIn(Article $article, string $code, string $quantity): void
    {
        app(StockService::class)->apply(
            [StockChange::in($article->id, Location::where('code', $code)->value('id'), MovementType::Adjustment, $quantity, '5000')],
            $this->owner,
        );
    }

    public function test_owner_creates_an_article_with_a_normalised_reference_and_it_is_audited(): void
    {
        Sanctum::actingAs($this->owner);
        $category = Category::create(['name' => 'Périphériques']);

        $this->create(['category_id' => $category->id])
            ->assertCreated()
            ->assertJsonPath('data.reference', 'SOUR-USB-01')
            ->assertJsonPath('data.unit', 'pièce')
            ->assertJsonPath('data.category.name', 'Périphériques')
            ->assertJsonPath('data.sale_price', '7500.00')
            ->assertJsonPath('data.purchase_price_reference', '5000.50')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.stock.total', '0.000')
            ->assertJsonCount(3, 'data.stock.by_location');

        $this->assertSame(1, AuditLog::where('action', 'article.created')->count());
    }

    /** TST-004 : deux articles avec la même référence (casse comprise). */
    public function test_a_duplicate_reference_is_refused_with_a_clear_message(): void
    {
        Sanctum::actingAs($this->owner);
        $this->create();

        $this->create(['reference' => 'SOUR-USB-01', 'barcode' => null])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'Cette référence est déjà utilisée par un autre article.');
    }

    public function test_a_barcode_cannot_identify_two_articles(): void
    {
        Sanctum::actingAs($this->owner);
        $this->create();

        $this->create(['reference' => 'AUTRE'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.barcode.0', 'Ce code-barres est déjà attribué à un autre article.');
    }

    public function test_designation_reference_and_sale_price_are_required(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/articles', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reference', 'designation', 'sale_price']);
    }

    /** TST-005 : scanner un code connu affiche la bonne fiche ; un code inconnu est signalé. */
    public function test_an_article_is_found_by_its_barcode(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create());
        $article = Article::factory()->create(['barcode' => '4006381333931']);

        $this->getJson('/api/v1/articles/barcode/4006381333931')
            ->assertOk()
            ->assertJsonPath('data.id', $article->id);

        $this->getJson('/api/v1/articles/barcode/0000000000000')
            ->assertNotFound()
            ->assertJsonPath('message', 'Code-barres inconnu : 0000000000000.');
    }

    public function test_the_list_shows_stock_per_location_and_can_be_filtered(): void
    {
        Sanctum::actingAs($this->owner);
        $mouse = Article::factory()->create(['designation' => 'Souris sans fil', 'alert_threshold' => 2]);
        $paper = Article::factory()->create(['designation' => 'Ramette papier A4', 'alert_threshold' => 5]);
        Article::factory()->inactive()->create(['designation' => 'Ancien modèle']);
        $this->stockIn($mouse, 'MAG', '10');
        $this->stockIn($mouse, 'BTQ', '4');
        $this->stockIn($paper, 'BTQ', '3');

        $this->getJson('/api/v1/articles')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.designation', 'Ramette papier A4')
            ->assertJsonPath('data.1.stock.total', '14.000')
            ->assertJsonPath('data.1.stock.by_location.0.code', 'MAG')
            ->assertJsonPath('data.1.stock.by_location.0.quantity', '10.000')
            ->assertJsonPath('locations.1.code', 'BTQ');

        $this->getJson('/api/v1/articles?search=souris')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/articles?low=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.designation', 'Ramette papier A4');
        $this->getJson('/api/v1/articles?status=inactive')->assertJsonCount(1, 'data')->assertJsonPath('data.0.designation', 'Ancien modèle');
        $this->getJson('/api/v1/articles?status=all')->assertJsonCount(3, 'data');
    }

    public function test_cashier_sees_the_sale_price_but_not_costs_and_cannot_edit(): void
    {
        $article = Article::factory()->create();
        Sanctum::actingAs(User::factory()->cashier()->create());

        $this->getJson("/api/v1/articles/{$article->id}")
            ->assertOk()
            ->assertJsonPath('data.sale_price', '1500.00')
            ->assertJsonMissingPath('data.purchase_price_reference')
            ->assertJsonMissingPath('data.average_cost');

        $this->putJson("/api/v1/articles/{$article->id}", ['designation' => 'X'])->assertForbidden();
        $this->create()->assertForbidden();
    }

    /** Un article n'est jamais supprimé : il est désactivé, et la modification est tracée. */
    public function test_an_article_is_deactivated_and_the_change_is_audited(): void
    {
        Sanctum::actingAs($this->owner);
        $article = Article::factory()->create(['sale_price' => '1500']);

        $this->putJson("/api/v1/articles/{$article->id}", ['active' => false, 'sale_price' => '1750'])
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.sale_price', '1750.00');

        $log = AuditLog::where('action', 'article.updated')->sole();
        $this->assertSame('1500.00', $log->before_data['sale_price']);
        $this->assertSame('1750', (string) $log->after_data['sale_price']);
        $this->assertSame(405, $this->deleteJson("/api/v1/articles/{$article->id}")->status());
    }

    public function test_an_article_keeping_fractional_stock_cannot_return_to_whole_units(): void
    {
        Sanctum::actingAs($this->owner);
        $cable = Article::factory()->create(['allow_decimal_quantity' => true, 'unit' => 'mètre']);
        $this->stockIn($cable, 'BTQ', '12.5');

        $this->putJson("/api/v1/articles/{$cable->id}", ['allow_decimal_quantity' => false])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Impossible de passer cet article à l’unité : une partie du stock est en quantité décimale.');

        $this->assertTrue($cable->fresh()->allow_decimal_quantity);
    }
}
