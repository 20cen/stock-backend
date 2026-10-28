<?php

namespace Tests\Feature\Stock;

use App\Enums\MovementType;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Article $mouse;

    private Article $paper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LocationSeeder::class);
        $this->owner = User::factory()->owner()->create();
        Sanctum::actingAs($this->owner);
        $this->mouse = Article::factory()->create(['designation' => 'Souris USB']);
        $this->paper = Article::factory()->create(['designation' => 'Ramette A4']);

        app(StockService::class)->apply([
            StockChange::in($this->mouse->id, $this->loc('MAG'), MovementType::Adjustment, 8, '5000'),
            StockChange::in($this->paper->id, $this->loc('MAG'), MovementType::Adjustment, 20, '2500'),
        ], $this->owner);
    }

    private function loc(string $code): int
    {
        return Location::where('code', $code)->value('id');
    }

    private function qty(Article $article, string $code): string
    {
        return StockBalance::where('article_id', $article->id)->where('location_id', $this->loc($code))->value('quantity_on_hand') ?? '0.000';
    }

    /**
     * @param  list<array{article_id: int, quantity: int|string}>  $lines
     */
    private function transfer(array $lines, string $from = 'MAG', string $to = 'BTQ'): TestResponse
    {
        return $this->postJson('/api/v1/transfers', [
            'source_location_id' => $this->loc($from),
            'destination_location_id' => $this->loc($to),
            'notes' => 'Réassort',
            'lines' => $lines,
        ]);
    }

    /** TST-008 : magasin −5, boutique +5 ; aucun chiffre d'affaires créé. */
    public function test_a_transfer_moves_stock_from_the_warehouse_to_the_shop(): void
    {
        $year = now()->year;

        $this->transfer([['article_id' => $this->mouse->id, 'quantity' => 5], ['article_id' => $this->paper->id, 'quantity' => 12]])
            ->assertCreated()
            ->assertJsonPath('data.reference', "TRF-{$year}-00001")
            ->assertJsonPath('data.source.code', 'MAG')
            ->assertJsonPath('data.destination.code', 'BTQ')
            ->assertJsonCount(2, 'data.lines');

        $this->assertSame('3.000', $this->qty($this->mouse, 'MAG'));
        $this->assertSame('5.000', $this->qty($this->mouse, 'BTQ'));
        $this->assertSame('8.000', $this->qty($this->paper, 'MAG'));
        $this->assertSame('12.000', $this->qty($this->paper, 'BTQ'));

        // Ce n'est pas une vente : uniquement des mouvements de transfert, et le CMP ne bouge pas.
        $this->assertSame(0, StockMovement::where('type', MovementType::Sale)->count());
        $this->assertSame(4, StockMovement::whereIn('type', [MovementType::TransferOut, MovementType::TransferIn])->count());
        $this->assertSame('5000.0000', $this->mouse->fresh()->average_cost);
        $this->assertSame(1, AuditLog::where('action', 'transfer.validated')->count());
    }

    public function test_a_transfer_beyond_available_stock_is_refused_and_nothing_moves(): void
    {
        $this->transfer([['article_id' => $this->paper->id, 'quantity' => 5], ['article_id' => $this->mouse->id, 'quantity' => 9]])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Stock insuffisant pour « Souris USB » (Magasin) : 8 disponible(s), 9 demandé(s).');

        $this->assertSame('20.000', $this->qty($this->paper, 'MAG'));
        $this->assertSame(0, Transfer::count());
    }

    public function test_source_and_destination_must_differ(): void
    {
        $this->transfer([['article_id' => $this->mouse->id, 'quantity' => 1]], 'MAG', 'MAG')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'L’emplacement de destination doit être différent de l’origine.');
    }

    public function test_an_article_counted_in_units_cannot_be_split(): void
    {
        $this->transfer([['article_id' => $this->mouse->id, 'quantity' => '0.5']])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'L’article « Souris USB » se compte en unités entières.');
    }

    public function test_movements_show_the_transfer_reference_and_can_be_searched(): void
    {
        $reference = $this->transfer([['article_id' => $this->mouse->id, 'quantity' => 2]])->json('data.reference');

        $this->getJson('/api/v1/stock/movements?search=souris&type=transfer_in')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source.type', 'transfer')
            ->assertJsonPath('data.0.source.reference', $reference);
    }

    public function test_the_transfer_list_can_be_filtered_by_article(): void
    {
        $this->transfer([['article_id' => $this->mouse->id, 'quantity' => 1]]);
        $this->transfer([['article_id' => $this->paper->id, 'quantity' => 1]]);

        $this->getJson('/api/v1/transfers')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.lines_count', 1);
        $this->getJson('/api/v1/transfers?search=ramette')->assertJsonCount(1, 'data');
    }

    public function test_the_stock_summary_counts_alerts_and_values_stock_for_the_owner_only(): void
    {
        Article::factory()->create(['designation' => 'Clé USB', 'alert_threshold' => 3]); // rupture
        $this->mouse->update(['alert_threshold' => 10]);                                   // 8 ≤ 10 : stock faible

        $this->getJson('/api/v1/stock/summary')
            ->assertOk()
            ->assertJsonPath('data.articles', 3)
            ->assertJsonPath('data.out_of_stock', 1)
            ->assertJsonPath('data.low_stock', 1)
            ->assertJsonPath('data.stock_value', '90000.00')        // 8 × 5000 + 20 × 2500
            ->assertJsonPath('data.value_by_location.0.code', 'MAG');

        Sanctum::actingAs(User::factory()->cashier()->create());
        $this->getJson('/api/v1/stock/summary')->assertOk()->assertJsonMissingPath('data.stock_value');
        $this->transfer([['article_id' => $this->mouse->id, 'quantity' => 1]])->assertForbidden();
    }

    public function test_the_article_list_can_show_out_of_stock_articles(): void
    {
        Article::factory()->create(['designation' => 'Clé USB']);

        $this->getJson('/api/v1/articles?out=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.designation', 'Clé USB');
    }
}
