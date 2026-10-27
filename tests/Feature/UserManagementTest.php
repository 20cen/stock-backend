<?php

namespace Tests\Feature;

use App\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function cashierRoleId(): int
    {
        return Role::where('code', RoleCode::Cashier->value)->value('id');
    }

    public function test_owner_creates_a_cashier_account_and_it_is_audited(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());

        $this->postJson('/api/v1/users', [
            'name' => 'Awa Caisse',
            'username' => 'Awa',
            'password' => 'secret123',
            'role_id' => $this->cashierRoleId(),
        ])->assertCreated()->assertJsonPath('data.username', 'awa');

        $log = AuditLog::where('action', 'user.created')->sole();
        $this->assertArrayNotHasKey('password', $log->after_data);
    }

    public function test_duplicate_username_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());
        User::factory()->create(['username' => 'awa']);

        $this->postJson('/api/v1/users', [
            'name' => 'Autre',
            'username' => 'AWA',
            'password' => 'secret123',
            'role_id' => $this->cashierRoleId(),
        ])->assertUnprocessable()->assertJsonValidationErrors('username');
    }

    /** TST-003 (variante) : le contrôle est fait par le serveur, pas seulement par l'interface. */
    public function test_cashier_cannot_manage_users(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create());

        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/users', [])->assertForbidden();
    }

    public function test_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();
    }

    public function test_deactivating_a_user_revokes_all_their_tokens(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());
        $cashier = User::factory()->create();
        $cashier->createToken('tablette');

        $this->putJson("/api/v1/users/{$cashier->id}", ['active' => false])
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->assertSame(0, $cashier->tokens()->count());
        $this->assertSame(1, AuditLog::where('action', 'user.updated')->count());
    }

    public function test_owner_cannot_deactivate_their_own_account(): void
    {
        $owner = User::factory()->owner()->create();
        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/users/{$owner->id}", ['active' => false])->assertUnprocessable();

        $this->assertTrue($owner->fresh()->active);
    }
}
