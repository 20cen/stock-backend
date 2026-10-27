<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $username, string $password = 'password')
    {
        return $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
            'device_name' => 'phpunit',
        ]);
    }

    /** TST-001 */
    public function test_valid_credentials_return_a_token_and_the_role_permissions(): void
    {
        $user = User::factory()->cashier()->create(['username' => 'caisse1']);

        $response = $this->login('CAISSE1')->assertOk();

        $response->assertJsonPath('user.username', 'caisse1')
            ->assertJsonPath('user.role.code', 'cashier')
            ->assertJsonStructure(['token', 'permissions']);
        $this->assertContains('sales.create', $response->json('permissions'));
        $this->assertNotContains('users.manage', $response->json('permissions'));

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$response->json('token')])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    /** TST-002 */
    public function test_wrong_password_and_unknown_user_get_the_same_generic_message(): void
    {
        User::factory()->create(['username' => 'caisse1']);

        $wrongPassword = $this->login('caisse1', 'mauvais')->assertStatus(422);
        $unknownUser = $this->login('inconnu')->assertStatus(422);

        $this->assertSame($wrongPassword->json('message'), $unknownUser->json('message'));
        $this->assertArrayNotHasKey('token', $wrongPassword->json());
        $this->assertSame(2, AuditLog::where('action', 'auth.login_failed')->count());
    }

    /** TST-024 */
    public function test_deactivated_user_cannot_log_in(): void
    {
        User::factory()->inactive()->create(['username' => 'ancien']);

        $this->login('ancien')->assertForbidden()->assertJsonMissingPath('token');
    }

    public function test_token_of_a_user_deactivated_after_login_is_refused(): void
    {
        $user = User::factory()->create(['username' => 'caisse1']);
        $token = $this->login('caisse1')->json('token');

        $user->update(['active' => false]);

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertForbidden();
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create(['username' => 'caisse1']);
        $token = $this->login('caisse1')->json('token');

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['username' => 'caisse1']);

        for ($i = 0; $i < 5; $i++) {
            $this->login('caisse1', 'mauvais')->assertStatus(422);
        }

        $this->login('caisse1')->assertStatus(429);
    }
}
