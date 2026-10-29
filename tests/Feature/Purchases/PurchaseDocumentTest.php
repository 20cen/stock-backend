<?php

namespace Tests\Feature\Purchases;

use App\Models\Article;
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
 * Documents imprimables des achats (bon de réception, reçu de paiement) et coût obligatoire
 * pour une entrée de stock manuelle sur un article sans coût connu.
 */
class PurchaseDocumentTest extends TestCase
{
    use RefreshDatabase;

    private array $purchase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([LocationSeeder::class, PaymentMethodSeeder::class]);
        Sanctum::actingAs(User::factory()->owner()->create());
        $mouse = Article::factory()->create(['designation' => 'Souris USB']);

        $this->purchase = $this->postJson('/api/v1/purchases', [
            'supplier_id' => Supplier::factory()->create(['name' => 'Bureau Pro'])->id,
            'purchase_date' => now()->toDateString(),
            'confirm' => true,
            'lines' => [['article_id' => $mouse->id, 'quantity' => 20, 'unit_price' => '5000']],
        ])->assertCreated()->json('data');

        $this->purchase = $this->postJson("/api/v1/purchases/{$this->purchase['id']}/receipts", [
            'location_id' => Location::where('code', 'MAG')->value('id'),
            'lines' => [['purchase_line_id' => $this->purchase['lines'][0]['id'], 'quantity' => 12]],
        ])->assertCreated()->json('data');

        $this->purchase = $this->postJson("/api/v1/purchases/{$this->purchase['id']}/payments", [
            'amount' => '60000', 'payment_method_id' => PaymentMethod::where('code', 'bank_transfer')->value('id'), 'reference' => 'VIR-889',
        ])->assertCreated()->json('data');
    }

    public function test_the_receipt_note_is_a_pdf(): void
    {
        $receipt = $this->purchase['receipts'][0];

        $response = $this->get("/api/v1/purchases/{$this->purchase['id']}/receipts/{$receipt['id']}/pdf")->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString("bon-reception-{$receipt['reference']}.pdf", $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_payment_receipt_is_a_pdf_numbered_by_purchase(): void
    {
        $payment = $this->purchase['payments'][0];

        $response = $this->get("/api/v1/purchases/{$this->purchase['id']}/payments/{$payment['id']}/pdf")->assertOk();

        $this->assertStringContainsString("recu-paiement-{$this->purchase['reference']}-P1.pdf", $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_a_document_of_another_purchase_is_not_found(): void
    {
        $other = $this->postJson('/api/v1/purchases', [
            'supplier_id' => Supplier::factory()->create()->id,
            'purchase_date' => now()->toDateString(),
            'lines' => [['article_id' => Article::factory()->create()->id, 'quantity' => 1, 'unit_price' => '100']],
        ])->json('data');

        $this->get("/api/v1/purchases/{$other['id']}/receipts/{$this->purchase['receipts'][0]['id']}/pdf")->assertNotFound();
        $this->get("/api/v1/purchases/{$other['id']}/payments/{$this->purchase['payments'][0]['id']}/pdf")->assertNotFound();
    }

    public function test_a_manual_stock_entry_needs_a_cost_when_the_article_has_none(): void
    {
        $article = Article::factory()->create(['designation' => 'Clé USB 32 Go']);
        $shop = Location::where('code', 'BTQ')->value('id');
        $entry = ['article_id' => $article->id, 'location_id' => $shop, 'quantity' => 5, 'reason' => 'Stock initial'];

        $this->postJson('/api/v1/stock/adjustments', $entry)->assertJsonValidationErrors('unit_cost');
        $this->postJson('/api/v1/stock/adjustments', $entry + ['unit_cost' => '4000'])->assertCreated();

        // Une fois le coût connu, il n'est plus obligatoire (le coût moyen s'applique).
        $this->postJson('/api/v1/stock/adjustments', $entry)->assertCreated();
        $this->assertSame('4000.0000', $article->fresh()->average_cost);
    }
}
