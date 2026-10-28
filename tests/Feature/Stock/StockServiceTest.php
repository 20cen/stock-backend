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

    private function quantity(string $code): int
    {
        return (int) StockBalance::where('article_id', $this->article->id)
            ->where('location_id', $this->location($code)->id)
            ->value('quantity_on_hand');
    }

    private function receive(string $code, int $quantity): void
    {
        $this->stock->apply([StockChange::in($this->article->id, $this->location($code)->id, MovementType::PurchaseReceipt, $quantity, '1200.00')], $this->user);
    }

    public function test_an_entry_increases_the_balance_and_records_a_signed_movement(): void
    {
        $this->receive('MAG', 10);
        $this->receive('MAG', 3);

        $this->assertSame(13, $this->quantity('MAG'));

        $last = StockMovement::latest('id')->first();
        $this->assertSame(MovementType::PurchaseReceipt, $last->type);
        $this->assertSame(3, $last->quantity);
        $this->assertSame(13, $last->balance_after);
        $this->assertSame('1200.00', $last->unit_cost);
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

        $this->assertSame(3, $this->quantity('MAG'));
        $this->assertSame(5, $this->quantity('BTQ'));
    }

    /** TST-009 : une sortie supérieure au stock est refusée et rien n'est écrit. */
    public function test_an_exit_beyond_available_stock_is_refused(): void
    {
        $this->receive('BTQ', 2);

        try {
            $this->stock->apply([StockChange::out($this->article->id, $this->location('BTQ')->id, MovementType::Sale, 3)], $this->user);
            $this->fail('La sortie aurait dû être refusée.');
        } catch (InsufficientStockException $e) {
            $this->assertSame(2, $e->available);
            $this->assertSame(3, $e->requested);
        }

        $this->assertSame(2, $this->quantity('BTQ'));
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
            $this->assertSame(4, $this->quantity('MAG'));
            $this->assertSame(0, $this->quantity('BTQ'));
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

        $this->stock->apply([new StockChange($this->article->id, $this->location('MAG')->id, MovementType::Adjustment, 0)], $this->user);
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
        $this->receive('MAG', 1);
        $movement = StockMovement::sole();

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
