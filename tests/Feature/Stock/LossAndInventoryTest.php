<?php

namespace Tests\Feature\Stock;

use App\Enums\MovementType;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LossAndInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private Article $mouse;

    private Article $paper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LocationSeeder::class);
        $this->owner = User::factory()->owner()->create();
        $this->cashier = User::factory()->cashier()->create();
        $this->mouse = Article::factory()->create(['designation' => 'Souris USB']);
        $this->paper = Article::factory()->create(['designation' => 'Ramette A4']);
        app(StockService::class)->apply([
            StockChange::in($this->mouse->id, $this->loc('BTQ'), MovementType::Adjustment, 10, '5000'),
            StockChange::in($this->paper->id, $this->loc('BTQ'), MovementType::Adjustment, 20, '2500'),
        ], $this->owner);
    }

    private function loc(string $code): int
    {
        return Location::where('code', $code)->value('id');
    }

    private function qty(Article $article, string $code = 'BTQ'): string
    {
        return StockBalance::where('article_id', $article->id)->where('location_id', $this->loc($code))->value('quantity_on_hand') ?? '0.000';
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function declare(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/losses', $overrides + [
            'article_id' => $this->mouse->id,
            'location_id' => $this->loc('BTQ'),
            'quantity' => 2,
            'reason' => 'breakage',
            'comment' => 'Tombées du présentoir',
        ]);
    }

    /** TST-016 : stock diminué uniquement après validation. */
    public function test_a_declared_loss_only_reduces_stock_once_validated(): void
    {
        Sanctum::actingAs($this->cashier);
        $id = $this->declare()
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.reason_label', 'Casse')
            ->assertJsonMissingPath('data.estimated_value')
            ->json('data.id');

        $this->assertSame('10.000', $this->qty($this->mouse));
        $this->postJson("/api/v1/losses/{$id}/validate")->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/losses/{$id}/validate")->assertOk()->assertJsonPath('data.status', 'validated');

        $this->assertSame('8.000', $this->qty($this->mouse));
        $movement = StockMovement::where('type', MovementType::Loss)->sole();
        $this->assertSame('5000.0000', $movement->unit_cost);
        $this->assertSame(1, AuditLog::where('action', 'loss.validated')->count());
    }

    public function test_the_owner_declaration_is_validated_at_once_and_valued(): void
    {
        Sanctum::actingAs($this->owner);

        $this->declare()->assertCreated()->assertJsonPath('data.status', 'validated')->assertJsonPath('data.estimated_value', '10000.00');
        $this->assertSame('8.000', $this->qty($this->mouse));
    }

    /** RG-PER-02 : sous le seuil paramétré, validation directe ; au-delà, approbation du propriétaire. */
    public function test_small_losses_below_the_threshold_are_validated_directly(): void
    {
        Setting::put(Setting::LOSS_AUTO_VALIDATE_LIMIT, '5000');
        Sanctum::actingAs($this->cashier);

        $this->declare(['quantity' => 1])->assertJsonPath('data.status', 'validated');   // 5 000 ≤ 5 000
        $this->declare(['quantity' => 2])->assertJsonPath('data.status', 'pending');     // 10 000 > 5 000
        $this->assertSame('9.000', $this->qty($this->mouse));
    }

    public function test_a_loss_must_be_justified_and_cannot_exceed_the_stock(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->declare(['reason' => 'other', 'comment' => ''])->assertUnprocessable()->assertJsonPath('message', 'Précisez le motif dans le commentaire.');
        $this->declare(['reason' => 'nimporte'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->declare(['quantity' => 11])->assertUnprocessable()->assertJsonPath('message', 'Stock insuffisant pour « Souris USB » (Boutique) : 10 disponible(s).');
    }

    public function test_a_rejected_loss_keeps_its_trace_and_leaves_stock_untouched(): void
    {
        Sanctum::actingAs($this->cashier);
        $id = $this->declare()->json('data.id');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/losses/{$id}/reject", ['reason' => 'Retrouvées en réserve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Retrouvées en réserve');

        $this->assertSame('10.000', $this->qty($this->mouse));
        $this->getJson('/api/v1/losses?status=rejected')->assertJsonCount(1, 'data');
    }

    /** TST-017 : saisir un écart sans valider → le stock reste inchangé. */
    public function test_counting_without_validating_leaves_stock_unchanged(): void
    {
        Sanctum::actingAs($this->owner);
        $year = now()->year;

        $inventory = $this->postJson('/api/v1/inventories', ['location_id' => $this->loc('BTQ')])
            ->assertCreated()
            ->assertJsonPath('data.reference', "INV-{$year}-00001")
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.summary.lines', 2)
            ->json('data');

        $this->putJson("/api/v1/inventories/{$inventory['id']}/counts", ['lines' => [['article_id' => $this->mouse->id, 'counted_quantity' => 7, 'reason' => 'Manquants']]])
            ->assertOk()
            ->assertJsonPath('data.summary.counted', 1)
            ->assertJsonPath('data.summary.with_variance', 1)
            ->assertJsonPath('data.summary.variance_value', '-15000.00');

        $this->assertSame('10.000', $this->qty($this->mouse));
        $this->postJson('/api/v1/inventories', ['location_id' => $this->loc('BTQ')])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Un inventaire est déjà en cours pour « Boutique ».');
    }

    /** TST-018 : valider un écart génère un mouvement d'ajustement historisé ; écart = compté − théorique figé. */
    public function test_validation_applies_variances_against_the_frozen_snapshot(): void
    {
        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/v1/inventories', ['location_id' => $this->loc('BTQ')])->json('data.id');

        // Pendant le comptage, une perte de 1 souris est validée : le stock passe à 9.
        $this->declare(['quantity' => 1]);
        $this->putJson("/api/v1/inventories/{$id}/counts", ['lines' => [
            ['article_id' => $this->mouse->id, 'counted_quantity' => 7],  // théorique figé 10 → écart −3
            ['article_id' => $this->paper->id, 'counted_quantity' => 20], // juste
        ]]);

        $this->postJson("/api/v1/inventories/{$id}/validate")->assertOk()->assertJsonPath('data.status', 'validated');

        $this->assertSame('6.000', $this->qty($this->mouse));   // 9 − 3
        $this->assertSame('20.000', $this->qty($this->paper));
        $adjustment = StockMovement::where('type', MovementType::InventoryAdjustment)->sole();
        $this->assertSame('-3.000', $adjustment->quantity);

        $this->putJson("/api/v1/inventories/{$id}/counts", ['lines' => [['article_id' => $this->mouse->id, 'counted_quantity' => 1]]])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cet inventaire est clôturé : les comptages ne sont plus modifiables.');
    }

    public function test_uncounted_articles_are_ignored_and_an_empty_count_cannot_be_validated(): void
    {
        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/v1/inventories', ['location_id' => $this->loc('BTQ')])->json('data.id');

        $this->postJson("/api/v1/inventories/{$id}/validate")->assertUnprocessable()->assertJsonPath('message', 'Aucun article n’a été compté.');

        $this->putJson("/api/v1/inventories/{$id}/counts", ['lines' => [['article_id' => $this->paper->id, 'counted_quantity' => 22]]]);
        $this->postJson("/api/v1/inventories/{$id}/validate")->assertOk();

        $this->assertSame('10.000', $this->qty($this->mouse));
        $this->assertSame('22.000', $this->qty($this->paper));
    }

    public function test_an_inventory_can_be_cancelled_with_a_reason_and_cashiers_have_no_access(): void
    {
        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/v1/inventories', ['location_id' => $this->loc('BTQ')])->assertForbidden();

        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/v1/inventories', ['location_id' => $this->loc('BTQ')])->json('data.id');
        $this->postJson("/api/v1/inventories/{$id}/cancel", ['reason' => 'Mauvais emplacement'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // Un nouvel inventaire peut alors être ouvert.
        $this->postJson('/api/v1/inventories', ['location_id' => $this->loc('BTQ')])->assertCreated();
    }
}
