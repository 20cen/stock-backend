<?php

namespace Tests\Feature\Sales;

use App\Enums\MovementType;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use Database\Seeders\LocationSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaleTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Article $mouse;

    private Article $cable;

    private int $cash;

    private int $momo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([LocationSeeder::class, PaymentMethodSeeder::class]);
        $owner = User::factory()->owner()->create();
        $this->cashier = User::factory()->cashier()->create(['name' => 'Awa']);
        $this->mouse = Article::factory()->create(['designation' => 'Souris USB', 'sale_price' => '7500']);
        $this->cable = Article::factory()->create(['designation' => 'Câble RJ45', 'sale_price' => '600', 'unit' => 'mètre', 'allow_decimal_quantity' => true]);
        $this->cash = PaymentMethod::where('code', 'cash')->value('id');
        $this->momo = PaymentMethod::where('code', 'mobile_money')->value('id');

        $shop = Location::where('code', 'BTQ')->value('id');
        app(StockService::class)->apply([
            StockChange::in($this->mouse->id, $shop, MovementType::Adjustment, 5, '5000'),
            StockChange::in($this->cable->id, $shop, MovementType::Adjustment, '50', '350'),
        ], $owner);

        Sanctum::actingAs($this->cashier);
    }

    private function openCash(string $amount = '10000'): void
    {
        $this->postJson('/api/v1/cash-sessions', ['opening_amount' => $amount])->assertCreated();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function sell(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/sales', $overrides + [
            'lines' => [['article_id' => $this->mouse->id, 'quantity' => 2], ['article_id' => $this->cable->id, 'quantity' => '2.5']],
            'payments' => [['payment_method_id' => $this->cash, 'amount' => '20000']],
        ]);
    }

    private function shopStock(Article $article): string
    {
        return StockBalance::where('article_id', $article->id)->where('location_id', Location::where('code', 'BTQ')->value('id'))->value('quantity_on_hand');
    }

    /** TST-025 : pas de vente sans caisse ouverte ; invitation à ouvrir la caisse. */
    public function test_a_sale_is_refused_without_an_open_cash_session(): void
    {
        $this->sell()
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cash_session_required')
            ->assertJsonPath('message', 'Aucune caisse ouverte : ouvrez votre caisse avant d’enregistrer une vente.');

        $this->assertSame('5.000', $this->shopStock($this->mouse));
    }

    /** TST-010 : numéro unique, paiement enregistré, stock boutique diminué, monnaie rendue. */
    public function test_a_cash_sale_is_numbered_paid_and_reduces_the_shop_stock(): void
    {
        $this->openCash();
        $year = now()->year;

        $this->sell()
            ->assertCreated()
            ->assertJsonPath('data.reference', "VTE-{$year}-00001")
            ->assertJsonPath('data.subtotal', '16500.00')       // 2 × 7500 + 2,5 × 600
            ->assertJsonPath('data.total', '16500.00')
            ->assertJsonPath('data.amount_paid', '16500.00')
            ->assertJsonPath('data.change_given', '3500.00')    // 20 000 reçus
            ->assertJsonPath('data.payments.0.amount', '16500.00')
            ->assertJsonPath('data.location.code', 'BTQ')
            ->assertJsonMissingPath('data.lines.0.unit_cost_snapshot');

        $this->assertSame('3.000', $this->shopStock($this->mouse));
        $this->assertSame('47.500', $this->shopStock($this->cable));
        $this->assertSame('5000.0000', Sale::sole()->lines()->first()->unit_cost_snapshot);
        $this->assertSame(2, StockMovement::where('type', MovementType::Sale)->count());
        $this->sell()->assertJsonPath('data.reference', "VTE-{$year}-00002");
    }

    /** TST-009 : stock insuffisant → vente refusée, rien n'est enregistré. */
    public function test_a_sale_beyond_available_stock_is_refused_atomically(): void
    {
        $this->openCash();

        $this->sell([
            'lines' => [['article_id' => $this->cable->id, 'quantity' => 1], ['article_id' => $this->mouse->id, 'quantity' => 6]],
            'payments' => [['payment_method_id' => $this->cash, 'amount' => '50000']],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Stock insuffisant pour « Souris USB » (Boutique) : 5 disponible(s), 6 demandé(s).');

        $this->assertSame(0, Sale::count());
        $this->assertSame('50.000', $this->shopStock($this->cable));
    }

    public function test_mixed_payment_is_accepted_and_change_only_comes_from_cash(): void
    {
        $this->openCash();

        $this->sell(['payments' => [['payment_method_id' => $this->momo, 'amount' => '10000', 'reference' => 'MM-123'], ['payment_method_id' => $this->cash, 'amount' => '10000']]])
            ->assertCreated()
            ->assertJsonPath('data.change_given', '3500.00')
            ->assertJsonPath('data.payments.0.amount', '10000.00')
            ->assertJsonPath('data.payments.1.amount', '6500.00');

        $this->sell(['payments' => [['payment_method_id' => $this->momo, 'amount' => '20000']]])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Le montant payé par carte, virement ou Mobile Money dépasse le total de la vente.');
    }

    public function test_an_insufficient_payment_is_refused_unless_credit_is_enabled_for_an_identified_customer(): void
    {
        $this->openCash();
        $payments = ['payments' => [['payment_method_id' => $this->cash, 'amount' => '10000']]];

        $this->sell($payments)->assertUnprocessable()->assertJsonPath('message', 'Montant insuffisant : il manque 6500.');

        Setting::put(Setting::ALLOW_CREDIT, true);
        $this->sell($payments)->assertUnprocessable()->assertJsonPath('code', 'customer_required');

        $customer = Customer::factory()->create();
        $this->sell($payments + ['customer_id' => $customer->id])
            ->assertCreated()
            ->assertJsonPath('data.amount_paid', '10000.00')
            ->assertJsonPath('data.balance_due', '6500.00')
            ->assertJsonPath('data.customer.id', $customer->id);
    }

    public function test_the_cashier_discount_is_capped_unless_granted_by_the_owner(): void
    {
        $this->openCash();
        // Plafond par défaut 5 % de 16 500 = 825.
        $lines = fn (string $discount) => ['lines' => [['article_id' => $this->mouse->id, 'quantity' => 2, 'discount' => $discount], ['article_id' => $this->cable->id, 'quantity' => '2.5']]];

        $this->sell($lines('900'))->assertUnprocessable()->assertJsonPath('code', 'discount_limit_exceeded');
        $this->sell($lines('800'))->assertCreated()->assertJsonPath('data.total', '15700.00');

        $owner = User::factory()->owner()->create();
        Sanctum::actingAs($owner);
        $this->openCash();
        $this->sell($lines('3000'))->assertCreated()->assertJsonPath('data.discount_total', '3000.00');
    }

    public function test_a_unit_article_cannot_be_sold_by_fraction_and_an_unpriced_article_is_refused(): void
    {
        $this->openCash();

        $this->sell(['lines' => [['article_id' => $this->mouse->id, 'quantity' => '0.5']]])
            ->assertUnprocessable()->assertJsonPath('message', 'L’article « Souris USB » se vend en unités entières.');

        $this->mouse->update(['sale_price' => null]);
        $this->sell()->assertUnprocessable()->assertJsonPath('message', 'L’article « Souris USB » n’a pas de prix de vente.');
    }

    public function test_a_cashier_only_sees_their_own_sales(): void
    {
        $this->openCash();
        $mine = $this->sell()->json('data.id');

        $other = User::factory()->cashier()->create();
        Sanctum::actingAs($other);
        $this->openCash();
        $this->sell(['lines' => [['article_id' => $this->mouse->id, 'quantity' => 1]]]);

        $this->getJson('/api/v1/sales')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/sales/{$mine}")->assertForbidden();

        Sanctum::actingAs(User::factory()->owner()->create());
        $this->getJson('/api/v1/sales')->assertJsonCount(2, 'data');
        $this->assertSame(2, AuditLog::where('action', 'sale.created')->count());
    }

    public function test_the_cashier_can_look_up_customers_for_a_sale(): void
    {
        Customer::factory()->create(['name' => 'Aminata Diallo', 'phone' => '0707112233']);

        $this->getJson('/api/v1/sales/customers?search=amin')->assertOk()->assertJsonPath('data.0.name', 'Aminata Diallo');
        $this->getJson('/api/v1/sales/customers?search=0707')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/customers')->assertForbidden();
    }

    public function test_the_sales_config_exposes_limits_and_methods(): void
    {
        $this->getJson('/api/v1/sales/config')
            ->assertOk()
            ->assertJsonPath('data.discount_limit_percent', '5')
            ->assertJsonPath('data.can_grant_discounts', false)
            ->assertJsonPath('data.allow_credit', false)
            ->assertJsonPath('data.locations.0.code', 'BTQ')
            ->assertJsonPath('data.payment_methods.0.code', 'cash');
    }

    public function test_sales_are_attached_to_the_open_cash_session(): void
    {
        $this->openCash();
        $this->sell();

        $this->assertSame(CashSession::sole()->id, Sale::sole()->cash_session_id);
    }

    /** Reçu ticket 80 mm en PDF (impression depuis Android) : réservé au caissier de la vente et au propriétaire. */
    public function test_the_receipt_is_available_as_a_ticket_pdf(): void
    {
        $this->openCash();
        $sale = $this->sell()->assertCreated()->json('data');

        $response = $this->get("/api/v1/sales/{$sale['id']}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="recu-'.$sale['reference'].'.pdf"');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        // Largeur 80 mm (226,8 pt) : MediaBox [0 0 226.8 hauteur].
        $this->assertMatchesRegularExpression('#/MediaBox \[0(\.0+)? 0(\.0+)? 226\.8\d* #', $response->getContent());

        Sanctum::actingAs(User::factory()->cashier()->create());
        $this->getJson("/api/v1/sales/{$sale['id']}/pdf")->assertForbidden();
        Sanctum::actingAs(User::factory()->owner()->create());
        $this->get("/api/v1/sales/{$sale['id']}/pdf")->assertOk();
    }
}
