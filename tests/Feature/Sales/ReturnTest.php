<?php

namespace Tests\Feature\Sales;

use App\Enums\MovementType;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use Database\Seeders\LocationSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReturnTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private Article $mouse;

    private int $cash;

    /** @var array<string, mixed> */
    private array $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([LocationSeeder::class, PaymentMethodSeeder::class]);
        $this->owner = User::factory()->owner()->create();
        $this->cashier = User::factory()->cashier()->create();
        $this->mouse = Article::factory()->create(['designation' => 'Souris USB', 'sale_price' => '7500']);
        $this->cash = PaymentMethod::where('code', 'cash')->value('id');
        app(StockService::class)->apply([StockChange::in($this->mouse->id, $this->loc('BTQ'), MovementType::Adjustment, 10, '5000')], $this->owner);

        // Le caissier vend 3 souris (22 500) en espèces.
        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/v1/cash-sessions', ['opening_amount' => '10000']);
        $this->sale = $this->postJson('/api/v1/sales', [
            'lines' => [['article_id' => $this->mouse->id, 'quantity' => 3]],
            'payments' => [['payment_method_id' => $this->cash, 'amount' => '22500']],
        ])->assertCreated()->json('data');
    }

    private function loc(string $code): int
    {
        return Location::where('code', $code)->value('id');
    }

    private function stock(string $code): string
    {
        return StockBalance::where('article_id', $this->mouse->id)->where('location_id', $this->loc($code))->value('quantity_on_hand') ?? '0.000';
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function requestReturn(int|string $quantity, string $condition = 'good', array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/returns', $extra + [
            'sale_id' => $this->sale['id'],
            'reason' => 'Ne fonctionne pas',
            'lines' => [['sale_line_id' => $this->sale['lines'][0]['id'], 'quantity' => $quantity, 'condition' => $condition]],
        ]);
    }

    public function test_a_cashier_request_does_not_touch_stock_until_validated(): void
    {
        $id = $this->requestReturn(1)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total_value', '7500.00')
            ->json('data.id');

        // RG-RET-03 : pas de retour automatique dans le stock vendable.
        $this->assertSame('7.000', $this->stock('BTQ'));
        $this->postJson("/api/v1/returns/{$id}/validate")->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/returns/{$id}/validate")
            ->assertOk()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.lines.0.destination.code', 'BTQ');

        $this->assertSame('8.000', $this->stock('BTQ'));
    }

    /** TST-013 : retourner plus que le reste retournable est refusé (retours antérieurs compris). */
    public function test_returning_more_than_the_remaining_quantity_is_refused(): void
    {
        $this->requestReturn(2)->assertCreated();

        $this->requestReturn(2)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Quantité retournée trop élevée pour « Souris USB » : 1 retournable(s) (vendu 3, déjà retourné ou en cours 2).');

        Sanctum::actingAs($this->owner);
        $this->getJson("/api/v1/returns/sale?reference={$this->sale['reference']}")
            ->assertOk()
            ->assertJsonPath('data.lines.0.returnable_quantity', '1.000');
    }

    /** TST-014 : un produit endommagé ne rejoint pas le stock vendable. */
    public function test_a_damaged_product_goes_to_the_defective_location_and_never_to_a_sellable_one(): void
    {
        $id = $this->requestReturn(1, 'damaged')->json('data.id');
        Sanctum::actingAs($this->owner);
        $returnLineId = $this->getJson("/api/v1/returns/{$id}")->json('data.lines.0.id');

        foreach (['BTQ', 'MAG'] as $code) {
            $this->postJson("/api/v1/returns/{$id}/validate", ['destinations' => [$returnLineId => $this->loc($code)]])
                ->assertUnprocessable()
                ->assertJsonPath('message', '« Souris USB » est endommagé : il doit aller dans un emplacement « Retours / Défectueux ».');
        }

        $this->postJson("/api/v1/returns/{$id}/validate")->assertOk()->assertJsonPath('data.lines.0.destination.code', 'DEF');
        $this->assertSame('1.000', $this->stock('DEF'));
        $this->assertSame('7.000', $this->stock('BTQ'));
    }

    /** TST-015 : remboursement distinct et historisé, sorti de la caisse de celui qui rembourse. */
    public function test_a_refund_is_a_separate_traced_operation_taken_from_the_refunder_cash_drawer(): void
    {
        $id = $this->requestReturn(1)->json('data.id');
        Sanctum::actingAs($this->owner);

        // Espèces : il faut une caisse ouverte.
        $this->postJson("/api/v1/returns/{$id}/validate", ['refund' => ['amount' => '7500', 'payment_method_id' => $this->cash]])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cash_session_required');

        $this->postJson('/api/v1/cash-sessions', ['opening_amount' => '50000']);
        $this->postJson("/api/v1/returns/{$id}/validate", ['refund' => ['amount' => '8000', 'payment_method_id' => $this->cash]])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Remboursement trop élevé : 7500 au maximum.');

        $this->postJson("/api/v1/returns/{$id}/validate", ['refund' => ['amount' => '7500', 'payment_method_id' => $this->cash]])
            ->assertOk()
            ->assertJsonPath('data.refunded', '7500.00')
            ->assertJsonPath('data.can.refund', false)
            ->assertJsonPath('data.refunds.0.amount', '7500.00');

        $this->assertSame('7500.00', Sale::find($this->sale['id'])->refunded_amount);
        $this->assertSame(1, AuditLog::where('action', 'refund.created')->count());
        // La caisse du propriétaire attend 50 000 − 7 500 d'espèces.
        $this->getJson('/api/v1/cash-sessions/current')->assertJsonPath('data.expected_cash', '42500.00');
    }

    public function test_a_return_can_be_rejected_with_a_reason_and_frees_the_quantity(): void
    {
        $id = $this->requestReturn(3)->json('data.id');
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/returns/{$id}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/returns/{$id}/reject", ['reason' => 'Produit utilisé'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Produit utilisé');

        $this->assertSame('7.000', $this->stock('BTQ'));
        $this->requestReturn(3)->assertCreated(); // la quantité refusée redevient retournable
    }

    /** RG-RET-02 : délai de retour (7 jours par défaut) ; au-delà, seul un responsable l'accepte. */
    public function test_the_return_delay_is_enforced_for_cashiers(): void
    {
        Sale::whereKey($this->sale['id'])->update(['sold_at' => now()->subDays(10)]);

        $this->requestReturn(1)->assertUnprocessable()->assertJsonPath('code', 'return_delay_exceeded');

        Sanctum::actingAs($this->owner);
        $this->requestReturn(1)->assertCreated();
    }

    /** TST-022 : annulation d'une vente validée, traçable, stock et paiements rétablis. */
    public function test_the_owner_cancels_a_sale_with_a_reason_and_everything_is_reversed(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/sales/{$this->sale['id']}/cancel", ['reason' => 'Erreur de saisie'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cash_session_required');

        $this->postJson('/api/v1/cash-sessions', ['opening_amount' => '30000']);
        $this->postJson("/api/v1/sales/{$this->sale['id']}/cancel", ['reason' => 'Erreur de saisie'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Erreur de saisie')
            ->assertJsonPath('data.refunded_amount', '22500.00');

        $this->assertSame('10.000', $this->stock('BTQ'));
        $this->assertSame(1, Refund::count());
        $this->assertSame('Erreur de saisie', AuditLog::where('action', 'sale.cancelled')->sole()->reason);
        $this->requestReturn(1)->assertUnprocessable();
    }

    public function test_a_cashier_cannot_cancel_a_sale_and_a_sale_with_returns_cannot_be_cancelled(): void
    {
        $this->postJson("/api/v1/sales/{$this->sale['id']}/cancel", ['reason' => 'Erreur'])->assertForbidden();

        $this->requestReturn(1);
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/cash-sessions', ['opening_amount' => '0']);
        $this->postJson("/api/v1/sales/{$this->sale['id']}/cancel", ['reason' => 'Erreur'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Des retours sont enregistrés sur cette vente : traitez-la par les retours.');
    }

    public function test_the_owner_can_record_and_validate_a_return_in_one_step(): void
    {
        Sanctum::actingAs($this->owner);

        $this->requestReturn(1, 'good', ['validate' => true])
            ->assertCreated()
            ->assertJsonPath('data.status', 'validated');

        $this->assertSame('8.000', $this->stock('BTQ'));
        $this->getJson('/api/v1/returns')->assertJsonCount(1, 'data');
    }
}
