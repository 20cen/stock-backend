<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * « Mon compte » : chacun change son mot de passe ; le propriétaire réinitialise celui d'un caissier.
 */
class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user, string $password = 'password', string $device = 'navigateur'): string
    {
        return $this->postJson('/api/v1/auth/login', ['username' => $user->username, 'password' => $password, 'device_name' => $device])
            ->assertOk()
            ->json('token');
    }

    public function test_a_user_changes_their_password_and_other_devices_are_signed_out(): void
    {
        $user = User::factory()->cashier()->create();
        $phone = $this->login($user, device: 'mobile');
        $desk = $this->login($user, device: 'desktop');

        $this->withToken($desk)->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'Nouveau2026',
            'password_confirmation' => 'Nouveau2026',
        ])->assertOk();

        $this->assertTrue(Hash::check('Nouveau2026', $user->refresh()->password));
        $this->assertSame(1, $user->tokens()->count()); // seul l'appareil qui a fait le changement reste connecté
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_changed', 'user_id' => $user->id]);

        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($desk)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_the_current_password_and_the_rules_are_checked(): void
    {
        $user = User::factory()->cashier()->create();
        $token = $this->login($user);

        $this->withToken($token)->putJson('/api/v1/auth/password', ['current_password' => 'faux', 'password' => 'Nouveau2026', 'password_confirmation' => 'Nouveau2026'])
            ->assertJsonValidationErrors(['current_password' => 'Mot de passe actuel incorrect.']);
        $this->withToken($token)->putJson('/api/v1/auth/password', ['current_password' => 'password', 'password' => 'Nouveau2026', 'password_confirmation' => 'Autre2026'])
            ->assertJsonValidationErrors(['password' => 'Les deux saisies du nouveau mot de passe ne sont pas identiques.']);
        $this->withToken($token)->putJson('/api/v1/auth/password', ['current_password' => 'password', 'password' => 'court', 'password_confirmation' => 'court'])
            ->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_change_failed', 'user_id' => $user->id]);
    }

    public function test_the_owner_resets_a_cashier_password_and_signs_them_out(): void
    {
        $owner = User::factory()->owner()->create();
        $cashier = User::factory()->cashier()->create();
        $this->login($cashier);

        $this->withToken($this->login($owner))->putJson("/api/v1/users/{$cashier->id}", ['password' => 'Provisoire1'])->assertOk();

        $this->assertTrue(Hash::check('Provisoire1', $cashier->refresh()->password));
        $this->assertSame(0, $cashier->tokens()->count());
    }
}
