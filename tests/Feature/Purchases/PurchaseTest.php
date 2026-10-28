<?php

namespace Tests\Feature\Purchases;

use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\LocationSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Article $mouse;

    private Article $cable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([LocationSeeder::class, PaymentMethodSeeder::class]);
        Sanctum::actingAs(User::factory()->owner()->create());
        $this->supplier = Supplier::factory()->create(['name' => 'InfoDistrib']);
        $this->mouse = Article::factory()->create(['designation' => 'Souris USB']);
        $this->cable = Article::factory()->create(['designation' => 'Câble RJ45', 'unit' => 'mètre', 'allow_decimal_quantity' => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPurchase(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/purchases', $overrides + [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'extra_costs' => '1000',
            'confirm' => true,
            'lines' => [
                ['article_id' => $this->mouse->id, 'quantity' => 10, 'unit_price' => '5000', 'discount' => '2000'],
                ['article_id' => $this->cable->id, 'quantity' => '100', 'unit_price' => '300'],
            ],
        ]);
    }

    private function location(string $code): int
    {
        return Location::where('code', $code)->value('id');
    }

    private function stock(Article $article, string $code): string
    {
        return StockBalance::where('article_id', $article->id)->where('location_id', $this->location($code))->value('quantity_on_hand') ?? '0.000';
    }

    public function test_totals_are_computed_by_the_server_and_the_reference_is_numbered(): void
    {
        $year = now()->year;

        $this->createPurchase()
            ->assertCreated()
            ->assertJsonPath('data.reference', "ACH-{$year}-00001")
            ->assertJsonPath('data.status', 'ordered')
            ->assertJsonPath('data.subtotal', '80000.00')      // 10 × 5000 + 100 × 300
            ->assertJsonPath('data.discount_total', '2000.00')
            ->assertJsonPath('data.total', '79000.00')         // 80000 − 2000 + 1000 de frais
            ->assertJsonPath('data.balance_due', '79000.00')
            ->assertJsonPath('data.lines.0.line_total', '48000.00');

        $this->createPurchase()->assertJsonPath('data.reference', "ACH-{$year}-00002");
    }

    /** TST-006 : une acquisition sans réception ne change pas le stock. */
    public function test_a_purchase_without_receipt_does_not_change_stock(): void
    {
        $this->createPurchase()->assertCreated();

        $this->assertSame(0, StockBalance::count());
        $this->assertSame(0, StockMovement::count());
    }

    /** TST-007 : seule la quantité reçue et validée augmente le bon emplacement. */
    public function test_a_partial_receipt_only_adds_the_received_quantity_to_the_chosen_location(): void
    {
        $purchase = $this->createPurchase()->json('data');

        $this->postJson("/api/v1/purchases/{$purchase['id']}/receipts", [
            'location_id' => $this->location('MAG'),
            'lines' => [['purchase_line_id' => $purchase['lines'][0]['id'], 'quantity' => 4]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'partially_received')
            ->assertJsonPath('data.lines_completed', 0)
            ->assertJsonPath('data.lines.0.quantity_received', '4.000')
            ->assertJsonPath('data.lines.0.quantity_remaining', '6.000')
            ->assertJsonPath('data.receipts.0.location.code', 'MAG');

        $this->assertSame('4.000', $this->stock($this->mouse, 'MAG'));
        $this->assertSame('0.000', $this->stock($this->mouse, 'BTQ'));
        $this->assertSame('0.000', $this->stock($this->cable, 'MAG'));
    }

    public function test_receiving_everything_completes_the_purchase(): void
    {
        $purchase = $this->createPurchase()->json('data');
        [$mouseLine, $cableLine] = $purchase['lines'];

        $this->postJson("/api/v1/purchases/{$purchase['id']}/receipts", [
            'location_id' => $this->location('MAG'),
            'lines' => [['purchase_line_id' => $mouseLine['id'], 'quantity' => 6]],
        ])->assertCreated();
        $this->postJson("/api/v1/purchases/{$purchase['id']}/receipts", [
            'location_id' => $this->location('BTQ'),
            'lines' => [['purchase_line_id' => $mouseLine['id'], 'quantity' => 4], ['purchase_line_id' => $cableLine['id'], 'quantity' => '100']],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.can.receive', false)
            ->assertJsonCount(2, 'data.receipts');

        $this->assertSame('6.000', $this->stock($this->mouse, 'MAG'));
        $this->assertSame('4.000', $this->stock($this->mouse, 'BTQ'));
        $this->assertSame('100.000', $this->stock($this->cable, 'BTQ'));
    }

    public function test_receiving_more_than_remaining_is_refused(): void
    {
        $purchase = $this->createPurchase()->json('data');

        $this->postJson("/api/v1/purchases/{$purchase['id']}/receipts", [
            'location_id' => $this->location('MAG'),
            'lines' => [['purchase_line_id' => $purchase['lines'][0]['id'], 'quantity' => 11]],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Quantité reçue trop élevée pour « Souris USB » : 10 restant(s) à recevoir.');

        $this->assertSame(0, StockMovement::count());
    }

    /** RG-ACH-04 : une réception précise toujours son emplacement. */
    public function test_a_receipt_requires_a_location(): void
    {
        $purchase = $this->createPurchase()->json('data');

        $this->postJson("/api/v1/purchases/{$purchase['id']}/receipts", [
            'lines' => [['purchase_line_id' => $purchase['lines'][0]['id'], 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('location_id');
    }

    public function test_the_entry_cost_includes_the_discount_and_a_share_of_extra_costs(): void
    {
        $purchase = $this->createPurchase()->json('data');

        // Souris : ligne nette 48 000 ; frais 1 000 × 48 000 / 78 000 = 615,38… → (48 000 + 615,3846) / 10 = 4 861,5385
        $this->assertSame('4861.5385', $purchase['lines'][0]['landed_unit_cost']);
        // Câble : 30 000 + 384,6154 → 303,8462 le mètre
        $this->assertSame('303.8462', $purchase['lines'][1]['landed_unit_cost']);

        $this->postJson("/api/v1/purchases/{$purchase['id']}/receipts", [
            'location_id' => $this->location('MAG'),
            'lines' => [['purchase_line_id' => $purchase['lines'][0]['id'], 'quantity' => 10]],
        ])->assertCreated();

        $this->assertSame('4861.5385', $this->mouse->fresh()->average_cost);
        $this->assertSame('4861.5385', StockMovement::sole()->unit_cost);
    }

    public function test_a_draft_does_not_allow_receipts_and_can_be_edited_then_confirmed(): void
    {
        $draft = $this->createPurchase(['confirm' => false])->assertJsonPath('data.status', 'draft')->json('data');

        $this->postJson("/api/v1/purchases/{$draft['id']}/receipts", [
            'location_id' => $this->location('MAG'),
            'lines' => [['purchase_line_id' => $draft['lines'][0]['id'], 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonPath('message', 'Validez l’acquisition avant de réceptionner.');

        $this->putJson("/api/v1/purchases/{$draft['id']}", [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'lines' => [['article_id' => $this->mouse->id, 'quantity' => 3, 'unit_price' => '5000']],
        ])->assertOk()->assertJsonCount(1, 'data.lines')->assertJsonPath('data.total', '15000.00');

        $this->postJson("/api/v1/purchases/{$draft['id']}/confirm")->assertOk()->assertJsonPath('data.status', 'ordered');

        $this->putJson("/api/v1/purchases/{$draft['id']}", [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'lines' => [['article_id' => $this->mouse->id, 'quantity' => 1, 'unit_price' => '1']],
        ])->assertUnprocessable()->assertJsonPath('message', 'Seul un brouillon peut être modifié.');
    }

    public function test_line_rules_are_checked_by_the_server(): void
    {
        $this->createPurchase(['lines' => [['article_id' => $this->mouse->id, 'quantity' => '1.5', 'unit_price' => '5000']]])
            ->assertUnprocessable()->assertJsonPath('message', 'L’article « Souris USB » se compte en unités entières.');

        $this->createPurchase(['lines' => [['article_id' => $this->mouse->id, 'quantity' => 1, 'unit_price' => '100', 'discount' => '150']]])
            ->assertUnprocessable()->assertJsonPath('message', 'Prix ou remise invalide pour « Souris USB ».');

        $this->createPurchase(['lines' => [
            ['article_id' => $this->mouse->id, 'quantity' => 1, 'unit_price' => '100'],
            ['article_id' => $this->mouse->id, 'quantity' => 2, 'unit_price' => '100'],
        ]])->assertUnprocessable();

        $this->mouse->update(['active' => false]);
        $this->createPurchase()->assertUnprocessable()->assertJsonPath('message', 'L’article « Souris USB » est désactivé.');

        $this->assertSame(0, Purchase::count());
    }

    /** RG-ACH-06 : montants payés et solde fournisseur. */
    public function test_payments_reduce_the_balance_and_cannot_exceed_it(): void
    {
        $cash = PaymentMethod::where('code', 'cash')->value('id');
        $purchase = $this->createPurchase(['initial_payment' => ['amount' => '30000', 'payment_method_id' => $cash]])
            ->assertJsonPath('data.paid_amount', '30000.00')
            ->assertJsonPath('data.balance_due', '49000.00')
            ->json('data');

        $this->postJson("/api/v1/purchases/{$purchase['id']}/payments", ['amount' => '50000', 'payment_method_id' => $cash])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Le paiement dépasse le solde dû (49000).');

        $this->postJson("/api/v1/purchases/{$purchase['id']}/payments", ['amount' => '49000', 'payment_method_id' => $cash, 'reference' => 'Reçu 42'])
            ->assertCreated()
            ->assertJsonPath('data.balance_due', '0.00')
            ->assertJsonPath('data.can.pay', false)
            ->assertJsonCount(2, 'data.payments');

        $this->assertSame(2, AuditLog::where('action', 'purchase.payment_added')->count());
    }

    public function test_cancellation_requires_a_reason_and_is_impossible_after_a_receipt(): void
    {
        $purchase = $this->createPurchase()->json('data');

        $this->postJson("/api/v1/purchases/{$purchase['id']}/cancel")->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/purchases/{$purchase['id']}/receipts", [
            'location_id' => $this->location('MAG'),
            'lines' => [['purchase_line_id' => $purchase['lines'][0]['id'], 'quantity' => 1]],
        ]);
        $this->postJson("/api/v1/purchases/{$purchase['id']}/cancel", ['reason' => 'Erreur'])->assertUnprocessable();

        $other = $this->createPurchase()->json('data');
        $this->postJson("/api/v1/purchases/{$other['id']}/cancel", ['reason' => 'Commande en double'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Commande en double');
    }

    public function test_the_list_shows_reception_progress_and_filters_unpaid(): void
    {
        $cash = PaymentMethod::where('code', 'cash')->value('id');
        $paid = $this->createPurchase(['lines' => [['article_id' => $this->mouse->id, 'quantity' => 2, 'unit_price' => '500']], 'extra_costs' => 0, 'initial_payment' => ['amount' => '1000', 'payment_method_id' => $cash]])->json('data');
        $open = $this->createPurchase()->json('data');

        $this->getJson('/api/v1/purchases')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $open['id'])
            ->assertJsonPath('data.0.lines_count', 2)
            ->assertJsonPath('data.0.lines_completed', 0);

        $this->getJson('/api/v1/purchases?unpaid=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open['id']);
        $this->getJson('/api/v1/purchases?search=infodis')->assertJsonCount(2, 'data');
        $this->assertNotSame($paid['id'], $open['id']);
    }

    public function test_cashier_has_no_access_to_purchases(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create());

        $this->getJson('/api/v1/purchases')->assertForbidden();
        $this->createPurchase()->assertForbidden();
    }
}
