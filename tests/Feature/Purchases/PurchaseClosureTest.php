<?php

namespace Tests\Feature\Purchases;

use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\LocationSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Compléments achats : clôture d'une acquisition jamais livrée en entier, annulation d'un paiement saisi
 * par erreur, compte fournisseur.
 */
class PurchaseClosureTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private int $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([LocationSeeder::class, PaymentMethodSeeder::class]);
        Sanctum::actingAs(User::factory()->owner()->create());
        $this->supplier = Supplier::factory()->create(['name' => 'InfoDistrib']);
        $this->cash = PaymentMethod::where('code', 'cash')->value('id');
    }

    /**
     * Souris : 10 × 5000 − 2000 = 48 000 ; câble : 100 × 300 = 30 000 ; frais 1000 → total 79 000.
     *
     * @return array<string, mixed>
     */
    private function purchase(): array
    {
        $mouse = Article::factory()->create(['designation' => 'Souris USB']);
        $cable = Article::factory()->create(['designation' => 'Câble RJ45', 'allow_decimal_quantity' => true]);

        return $this->postJson('/api/v1/purchases', [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'extra_costs' => '1000',
            'confirm' => true,
            'lines' => [
                ['article_id' => $mouse->id, 'quantity' => 10, 'unit_price' => '5000', 'discount' => '2000'],
                ['article_id' => $cable->id, 'quantity' => '100', 'unit_price' => '300'],
            ],
        ])->assertCreated()->json('data');
    }

    /** Reçoit 4 souris sur 10 et aucun câble. */
    private function receiveFourMice(array $purchase): void
    {
        $this->postJson("/api/v1/purchases/{$purchase['id']}/receipts", [
            'location_id' => Location::where('code', 'MAG')->value('id'),
            'lines' => [['purchase_line_id' => $purchase['lines'][0]['id'], 'quantity' => 4]],
        ])->assertCreated();
    }

    private function pay(array $purchase, string $amount): array
    {
        return $this->postJson("/api/v1/purchases/{$purchase['id']}/payments", ['amount' => $amount, 'payment_method_id' => $this->cash])
            ->assertCreated()
            ->json('data');
    }

    public function test_closing_a_partially_received_purchase_removes_the_undelivered_value_from_the_balance(): void
    {
        $purchase = $this->purchase();
        $this->receiveFourMice($purchase);
        $this->pay($purchase, '10000');

        // Non livré : 6/10 de 48 000 = 28 800 + câble 30 000 = 58 800 ; dû : 79 000 − 58 800 = 20 200 (frais compris).
        $this->postJson("/api/v1/purchases/{$purchase['id']}/close", ['reason' => 'Fournisseur en rupture définitive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.status_label', 'Clôturée')
            ->assertJsonPath('data.total', '79000.00')
            ->assertJsonPath('data.undelivered_amount', '58800.00')
            ->assertJsonPath('data.net_total', '20200.00')
            ->assertJsonPath('data.balance_due', '10200.00')
            ->assertJsonPath('data.close_reason', 'Fournisseur en rupture définitive')
            ->assertJsonPath('data.can.receive', false)
            ->assertJsonPath('data.can.close', false)
            ->assertJsonPath('data.can.pay', true);

        $this->assertDatabaseHas('audit_logs', ['action' => 'purchase.closed', 'reason' => 'Fournisseur en rupture définitive']);

        // Le solde restant se paie normalement, sans pouvoir dépasser le montant dû.
        $this->postJson("/api/v1/purchases/{$purchase['id']}/payments", ['amount' => '10300', 'payment_method_id' => $this->cash])->assertUnprocessable();
        $this->pay($purchase, '10200');
        $this->getJson("/api/v1/purchases/{$purchase['id']}")->assertJsonPath('data.balance_due', '0.00');
        $this->getJson('/api/v1/purchases?unpaid=1')->assertJsonCount(0, 'data');
    }

    public function test_only_a_partially_received_purchase_can_be_closed(): void
    {
        $purchase = $this->purchase();

        $this->postJson("/api/v1/purchases/{$purchase['id']}/close", ['reason' => 'Jamais livrée'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Rien n’a encore été reçu : annulez l’acquisition au lieu de la clôturer.');
        $this->postJson("/api/v1/purchases/{$purchase['id']}/close", [])->assertJsonValidationErrors('reason');
    }

    public function test_closing_is_refused_when_payments_exceed_the_value_received(): void
    {
        $purchase = $this->purchase();
        $this->receiveFourMice($purchase);
        $payment = $this->pay($purchase, '30000')['payments'][0];

        $this->postJson("/api/v1/purchases/{$purchase['id']}/close", ['reason' => 'Rupture'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Les paiements enregistrés (30000) dépassent le montant dû pour les marchandises reçues (20200) : annulez d’abord le paiement en trop.');

        // Paiement annulé puis ressaisi au bon montant : la clôture passe.
        $this->postJson("/api/v1/purchases/{$purchase['id']}/payments/{$payment['id']}/cancel", ['reason' => 'Montant erroné'])->assertOk();
        $this->pay($purchase, '20000');
        $this->postJson("/api/v1/purchases/{$purchase['id']}/close", ['reason' => 'Rupture'])->assertOk()->assertJsonPath('data.balance_due', '200.00');
    }

    public function test_a_payment_entered_by_mistake_is_cancelled_not_deleted(): void
    {
        $purchase = $this->purchase();
        $payment = $this->pay($purchase, '50000')['payments'][0];

        $this->postJson("/api/v1/purchases/{$purchase['id']}/payments/{$payment['id']}/cancel", ['reason' => 'Saisi deux fois'])
            ->assertOk()
            ->assertJsonPath('data.paid_amount', '0.00')
            ->assertJsonPath('data.balance_due', '79000.00')
            ->assertJsonPath('data.payments.0.amount', '50000.00')
            ->assertJsonPath('data.payments.0.cancel_reason', 'Saisi deux fois')
            ->assertJsonPath('data.can.cancel', true); // plus aucun paiement actif

        $this->assertNotNull($this->getJson("/api/v1/purchases/{$purchase['id']}")->json('data.payments.0.cancelled_at'));
        $this->assertSame(1, AuditLog::where('action', 'purchase.payment_cancelled')->count());

        $this->postJson("/api/v1/purchases/{$purchase['id']}/payments/{$payment['id']}/cancel", ['reason' => 'Encore'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Ce paiement est déjà annulé.');

        // Le reçu d'un paiement annulé reste consultable (marqué « annulé »).
        $this->get("/api/v1/purchases/{$purchase['id']}/payments/{$payment['id']}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_a_payment_cannot_be_cancelled_through_another_purchase(): void
    {
        $first = $this->purchase();
        $second = $this->purchase();
        $payment = $this->pay($first, '1000')['payments'][0];

        $this->postJson("/api/v1/purchases/{$second['id']}/payments/{$payment['id']}/cancel", ['reason' => 'Erreur'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Ce paiement ne concerne pas cette acquisition.');
    }

    public function test_the_supplier_account_sums_validated_purchases_and_lists_those_left_to_pay(): void
    {
        $paid = $this->purchase();
        $this->pay($paid, '79000');
        $open = $this->purchase();
        $this->pay($open, '9000');
        $closed = $this->purchase();
        $this->receiveFourMice($closed);
        $this->postJson("/api/v1/purchases/{$closed['id']}/close", ['reason' => 'Rupture'])->assertOk();
        // Brouillon : non compté.
        $this->postJson('/api/v1/purchases', ['supplier_id' => $this->supplier->id, 'purchase_date' => now()->toDateString(), 'lines' => [['article_id' => Article::factory()->create()->id, 'quantity' => 1, 'unit_price' => '999']]])->assertCreated();

        $this->getJson("/api/v1/suppliers/{$this->supplier->id}/account")
            ->assertOk()
            ->assertJsonPath('data.purchases_count', 3)
            ->assertJsonPath('data.total', '178200.00') // 79 000 + 79 000 + 20 200
            ->assertJsonPath('data.paid', '88000.00')
            ->assertJsonPath('data.balance_due', '90200.00')
            ->assertJsonCount(2, 'data.unpaid_purchases')
            ->assertJsonPath('data.unpaid_purchases.0.reference', $open['reference'])
            ->assertJsonPath('data.unpaid_purchases.0.balance_due', '70000.00')
            ->assertJsonPath('data.unpaid_purchases.1.balance_due', '20200.00');
    }

    public function test_a_cashier_cannot_close_or_cancel_payments(): void
    {
        $purchase = $this->purchase();
        $payment = $this->pay($purchase, '1000')['payments'][0];
        Sanctum::actingAs(User::factory()->cashier()->create());

        $this->postJson("/api/v1/purchases/{$purchase['id']}/close", ['reason' => 'Rupture'])->assertForbidden();
        $this->postJson("/api/v1/purchases/{$purchase['id']}/payments/{$payment['id']}/cancel", ['reason' => 'Erreur'])->assertForbidden();
        $this->getJson("/api/v1/suppliers/{$this->supplier->id}/account")->assertForbidden();
    }
}
