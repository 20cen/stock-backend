<?php

namespace Tests\Feature\Stock;

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StockException;
use App\Models\Article;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use Database\Seeders\LocationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private User $user;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LocationSeeder::class);
        $this->stock = app(StockService::class);
        $this->user = User::factory()->owner()->create();
        $this->article = Article::factory()->create();
    }

    private function location(string $code): Location
    {
        return Location::where('code', $code)->sole();
    }

    private function quantity(string $code, ?Article $article = null): string
    {
        return StockBalance::where('article_id', ($article ?? $this->article)->id)
            ->where('location_id', $this->location($code)->id)
            ->first()?->quantity_on_hand ?? '0.000';
    }

    private function receive(string $code, int|string $quantity, ?string $unitCost = '1200', ?Article $article = null): StockMovement
    {
        return $this->stock->apply(
            [StockChange::in(($article ?? $this->article)->id, $this->location($code)->id, MovementType::PurchaseReceipt, $quantity, $unitCost)],
            $this->user,
        )->sole();
    }

    private function averageCost(): ?string
    {
        return $this->article->fresh()->average_cost;
    }

    public function test_an_entry_increases_the_balance_and_records_a_signed_movement(): void
    {
        $this->receive('MAG', 10);
        $last = $this->receive('MAG', 3);

        $this->assertSame('13.000', $this->quantity('MAG'));
        $this->assertSame(MovementType::PurchaseReceipt, $last->type);
        $this->assertSame('3.000', $last->fresh()->quantity);
        $this->assertSame('13.000', $last->fresh()->balance_after);
        $this->assertSame('1200.0000', $last->fresh()->unit_cost);
        $this->assertSame($this->user->id, $last->created_by);
        $this->assertSame(sprintf('MVT-%06d', $last->id), $last->reference);
    }

    /** TST-008 : magasin -5, boutique +5, dans une seule opération. */
    public function test_a_transfer_moves_stock_between_locations_atomically(): void
    {
        $this->receive('MAG', 8);

        $this->stock->apply([
            StockChange::out($this->article->id, $this->location('MAG')->id, MovementType::TransferOut, 5),
            StockChange::in($this->article->id, $this->location('BTQ')->id, MovementType::TransferIn, 5),
        ], $this->user);

        $this->assertSame('3.000', $this->quantity('MAG'));
        $this->assertSame('5.000', $this->quantity('BTQ'));
    }

    /** TST-009 : une sortie supérieure au stock est refusée et rien n'est écrit. */
    public function test_an_exit_beyond_available_stock_is_refused(): void
    {
        $this->receive('BTQ', 2);

        try {
            $this->stock->apply([StockChange::out($this->article->id, $this->location('BTQ')->id, MovementType::Sale, 3)], $this->user);
            $this->fail('La sortie aurait dû être refusée.');
        } catch (InsufficientStockException $e) {
            $this->assertSame('2.000', $e->available);
            $this->assertSame('3.000', $e->requested);
        }

        $this->assertSame('2.000', $this->quantity('BTQ'));
        $this->assertSame(1, StockMovement::count());
    }

    public function test_a_failing_line_cancels_the_whole_operation(): void
    {
        $this->receive('MAG', 4);

        $this->expectException(InsufficientStockException::class);

        try {
            $this->stock->apply([
                StockChange::in($this->article->id, $this->location('BTQ')->id, MovementType::TransferIn, 5),
                StockChange::out($this->article->id, $this->location('MAG')->id, MovementType::TransferOut, 5),
            ], $this->user);
        } finally {
            $this->assertSame('4.000', $this->quantity('MAG'));
            $this->assertSame('0.000', $this->quantity('BTQ'));
            $this->assertSame(1, StockMovement::count());
        }
    }

    public function test_the_sign_must_match_the_movement_type(): void
    {
        $this->expectException(StockException::class);

        $this->stock->apply([new StockChange($this->article->id, $this->location('MAG')->id, MovementType::PurchaseReceipt, -2)], $this->user);
    }

    public function test_a_zero_quantity_is_refused(): void
    {
        $this->expectException(StockException::class);

        $this->stock->apply([new StockChange($this->article->id, $this->location('MAG')->id, MovementType::Adjustment, '0.000')], $this->user);
    }

    public function test_decimal_quantities_are_accepted_for_articles_sold_by_measure(): void
    {
        $cable = Article::factory()->create(['designation' => 'Câble RJ45', 'unit' => 'mètre', 'allow_decimal_quantity' => true]);

        $this->receive('BTQ', '100', '350', $cable);
        $this->stock->apply([StockChange::out($cable->id, $this->location('BTQ')->id, MovementType::Sale, '2.5')], $this->user);

        $this->assertSame('97.500', $this->quantity('BTQ', $cable));
    }

    public function test_a_decimal_quantity_is_refused_for_an_article_counted_in_units(): void
    {
        $this->expectExceptionMessage('se compte en unités entières');

        $this->receive('MAG', '1.5');
    }

    public function test_more_than_three_decimals_is_refused(): void
    {
        $this->expectException(StockException::class);

        new StockChange($this->article->id, $this->location('MAG')->id, MovementType::Adjustment, '1.2345');
    }

    public function test_the_weighted_average_cost_is_updated_by_valued_entries_only(): void
    {
        $this->receive('MAG', 10, '1000');
        $this->assertSame('1000.0000', $this->averageCost());

        // (10 × 1000 + 30 × 1400) / 40 = 1300
        $this->receive('BTQ', 30, '1400');
        $this->assertSame('1300.0000', $this->averageCost());

        // Sortie valorisée au CMP, qui ne change pas.
        $sale = $this->stock->apply([StockChange::out($this->article->id, $this->location('BTQ')->id, MovementType::Sale, 4)], $this->user)->sole();
        $this->assertSame('1300.0000', $sale->fresh()->unit_cost);

        // Transfert interne : valorisé au CMP, sans effet sur lui.
        $moves = $this->stock->apply([
            StockChange::out($this->article->id, $this->location('MAG')->id, MovementType::TransferOut, 6),
            StockChange::in($this->article->id, $this->location('BTQ')->id, MovementType::TransferIn, 6),
        ], $this->user);
        $this->assertSame(['1300.0000', '1300.0000'], $moves->map(fn ($m) => $m->fresh()->unit_cost)->all());
        $this->assertSame('1300.0000', $this->averageCost());
    }

    public function test_the_average_cost_is_rounded_to_four_decimals(): void
    {
        $this->receive('MAG', 3, '1000');
        // (3 × 1000 + 1 × 1001) / 4 = 1000.25 ; (4 × 1000.25 + 2 × 1000) / 6 = 1000.16666… → 1000.1667
        $this->receive('MAG', 1, '1001');
        $this->receive('MAG', 2, '1000');

        $this->assertSame('1000.1667', $this->averageCost());
    }

    public function test_an_entry_on_an_empty_stock_restarts_the_average_cost(): void
    {
        $this->receive('BTQ', 2, '1000');
        $this->stock->apply([StockChange::out($this->article->id, $this->location('BTQ')->id, MovementType::Sale, 2)], $this->user);

        $this->receive('BTQ', 5, '1500');

        $this->assertSame('1500.0000', $this->averageCost());
    }

    public function test_an_entry_without_known_cost_leaves_the_cost_unknown(): void
    {
        $movement = $this->receive('MAG', 2, null);

        $this->assertNull($movement->fresh()->unit_cost);
        $this->assertNull($this->averageCost());
    }

    public function test_a_sale_is_only_possible_from_a_sellable_location(): void
    {
        $this->receive('MAG', 5);

        $this->expectExceptionMessage('n’est pas vendable');

        $this->stock->apply([StockChange::out($this->article->id, $this->location('MAG')->id, MovementType::Sale, 1)], $this->user);
    }

    public function test_an_inactive_location_cannot_receive_movements(): void
    {
        $this->location('DEF')->update(['active' => false]);

        $this->expectExceptionMessage('désactivé');

        $this->receive('DEF', 1);
    }

    public function test_a_movement_can_be_neither_modified_nor_deleted(): void
    {
        $movement = $this->receive('MAG', 1);

        try {
            $movement->update(['quantity' => 50]);
            $this->fail('La modification aurait dû être refusée.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $movement->delete();
    }

    public function test_postgresql_refuses_a_negative_balance_even_outside_the_service(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Contrainte CHECK vérifiée sur PostgreSQL uniquement.');
        }

        $this->receive('MAG', 1);

        $this->expectException(QueryException::class);
        DB::table('stock_balances')->update(['quantity_on_hand' => -1]);
    }
}
