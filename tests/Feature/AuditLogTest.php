<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\User;
use App\Support\AuditCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_reads_the_log_with_labels_and_filters(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Moussa']);
        Sanctum::actingAs($owner);
        $article = $this->postJson('/api/v1/articles', ['reference' => 'R12', 'designation' => 'RAM', 'sale_price' => '20000'])->assertCreated()->json('data');
        $this->putJson("/api/v1/articles/{$article['id']}", ['sale_price' => '21000'])->assertOk();
        $this->postJson('/api/v1/suppliers', ['name' => 'InfoDistrib'])->assertCreated();

        $this->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.action_label', 'Fournisseur créé')
            ->assertJsonPath('data.1.action_label', 'Article modifié')
            ->assertJsonPath('data.1.user.name', 'Moussa')
            ->assertJsonPath('data.1.entity', ['type' => 'Article', 'id' => $article['id'], 'label' => 'R12'])
            ->assertJsonPath('data.1.before.sale_price', '20000.00')
            ->assertJsonPath('data.1.after.sale_price', '21000');

        $this->getJson('/api/v1/audit-logs?module=article')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/audit-logs?action=supplier.created')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/audit-logs?user_id='.($owner->id + 1))->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/audit-logs?from='.now()->addDay()->toDateString())->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/audit-logs/filters')->assertOk()->assertJsonPath('data.users.0.name', 'Moussa');
    }

    public function test_a_failed_login_appears_without_user(): void
    {
        $this->postJson('/api/v1/auth/login', ['username' => 'inconnu', 'password' => 'x', 'device_name' => 'test']);
        Sanctum::actingAs(User::factory()->owner()->create());

        $this->getJson('/api/v1/audit-logs?module=auth')
            ->assertJsonPath('data.0.action_label', 'Échec de connexion')
            ->assertJsonPath('data.0.user', null)
            ->assertJsonPath('data.0.after.username', 'inconnu');
    }

    public function test_the_cashier_has_no_access(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create());

        $this->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->getJson('/api/v1/audit-logs/filters')->assertForbidden();
    }

    /** Toute action journalisée dans le code a un libellé français et un module. */
    public function test_every_logged_action_has_a_label(): void
    {
        $actions = [];
        foreach (File::allFiles(app_path()) as $file) {
            preg_match_all("/->log\\('([a-z_]+\\.[a-z_]+)'/", $file->getContents(), $m);
            $actions = [...$actions, ...$m[1]];
        }
        foreach (['supplier', 'customer'] as $partner) { // PartnerController : "{$this->auditName()}.created"
            $actions[] = "{$partner}.created";
            $actions[] = "{$partner}.updated";
        }

        $this->assertGreaterThan(40, count(array_unique($actions)));
        foreach (array_unique($actions) as $action) {
            $this->assertArrayHasKey($action, AuditCatalog::ACTIONS, "Libellé manquant : {$action}");
            $this->assertArrayHasKey(explode('.', $action)[0], AuditCatalog::MODULES, "Module manquant : {$action}");
        }
        $this->assertInstanceOf(Article::class, new (array_key_first(AuditCatalog::ENTITIES)));
    }
}
