<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PaymentMethodSeeder::class);
        $this->owner = User::factory()->owner()->create()->fresh();
        Sanctum::actingAs($this->owner);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'shop_name' => 'EMPIL Plateau',
            'shop_lines' => ['Rue du Commerce', ' ', 'Tél. 01 02 03 04'],
            'receipt_footer' => 'À bientôt !',
            'cashier_discount_limit' => '10',
            'allow_credit' => true,
            'return_days' => 15,
            'loss_auto_validate_limit' => '2500.5',
            'primary_color' => '#4f46e5',
            'dark_color' => '#111833',
        ];
    }

    public function test_defaults_are_returned_before_any_change(): void
    {
        $data = $this->getJson('/api/v1/settings')->assertOk()->json();

        $this->assertSame('EMPIL', $data['data']['shop_name']);
        $this->assertSame('5', $data['data']['cashier_discount_limit']);
        $this->assertFalse($data['data']['allow_credit']);
        $this->assertSame(7, $data['data']['return_days']);
        $this->assertTrue(collect($data['payment_methods'])->firstWhere('code', 'cash')['locked']);
    }

    public function test_owner_updates_settings_used_by_the_rules_and_the_receipt(): void
    {
        $res = $this->putJson('/api/v1/settings', $this->payload())->assertOk()->json();

        $this->assertSame(['Rue du Commerce', 'Tél. 01 02 03 04'], $res['data']['shop_lines']); // ligne vide retirée
        $this->assertSame('2500.5', $res['data']['loss_auto_validate_limit']);
        $this->assertSame('EMPIL Plateau', $res['shop']['name']);

        $this->assertSame('10', (string) Setting::get(Setting::CASHIER_DISCOUNT_LIMIT));
        $this->assertTrue(Setting::get(Setting::ALLOW_CREDIT));
        $this->assertSame(15, Setting::get(Setting::RETURN_DAYS));
        $this->assertSame('EMPIL Plateau', $this->getJson('/api/v1/auth/me')->json('shop.name'));
        $this->assertTrue($this->getJson('/api/v1/sales/config')->json('data.allow_credit'));

        // Seules les valeurs modifiées sont journalisées, avec l'avant/après.
        $log = AuditLog::where('action', 'settings.update')->sole();
        $this->assertSame(7, $log->before_data['return_days']);
        $this->assertSame(15, $log->after_data['return_days']);

        $this->putJson('/api/v1/settings', $this->payload())->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'settings.update')->count()); // rien n'a changé
    }

    public function test_invalid_values_are_refused(): void
    {
        $this->putJson('/api/v1/settings', $this->payload(['cashier_discount_limit' => '120']))->assertJsonValidationErrors('cashier_discount_limit');
        $this->putJson('/api/v1/settings', $this->payload(['loss_auto_validate_limit' => '-5']))->assertJsonValidationErrors('loss_auto_validate_limit');
        $this->putJson('/api/v1/settings', $this->payload(['return_days' => 400, 'shop_name' => '']))->assertJsonValidationErrors(['return_days', 'shop_name']);
    }

    public function test_payment_methods_can_be_added_renamed_and_deactivated_except_cash(): void
    {
        $methods = $this->postJson('/api/v1/settings/payment-methods', ['name' => 'Wave'])->assertCreated()->json('data');
        $wave = collect($methods)->firstWhere('name', 'Wave');
        $this->assertSame('wave', $wave['code']);
        $this->postJson('/api/v1/settings/payment-methods', ['name' => 'Wave'])->assertJsonValidationErrors('name');

        $this->putJson("/api/v1/settings/payment-methods/{$wave['id']}", ['name' => 'Wave Money', 'active' => false])->assertOk();
        $this->assertNotContains('Wave Money', collect($this->getJson('/api/v1/payment-methods')->json('data'))->pluck('name'));

        $cash = PaymentMethod::where('code', 'cash')->first();
        $this->putJson("/api/v1/settings/payment-methods/{$cash->id}", ['active' => false])->assertUnprocessable();
        $this->assertTrue($cash->fresh()->active);
    }

    public function test_settings_are_reserved_to_settings_manage(): void
    {
        // fresh() : toutes les colonnes chargées, comme pour un utilisateur authentifié réel.
        Sanctum::actingAs(User::factory()->cashier()->create()->fresh());

        $this->getJson('/api/v1/settings')->assertForbidden();
        $this->putJson('/api/v1/settings', $this->payload())->assertForbidden();
        $this->assertSame('EMPIL', $this->getJson('/api/v1/auth/me')->json('shop.name'));
    }

    /** Identité visuelle publique : l'écran de connexion l'affiche avant toute authentification. */
    public function test_branding_is_public_and_follows_the_settings(): void
    {
        $this->putJson('/api/v1/settings', $this->payload(['primary_color' => '#E21C20', 'dark_color' => '#202044']))
            ->assertOk()
            ->assertJsonPath('data.primary_color', '#e21c20')
            ->assertJsonPath('branding.dark_color', '#202044');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/branding')
            ->assertOk()
            ->assertExactJson(['data' => [
                'name' => 'EMPIL Plateau',
                'tagline' => 'Rue du Commerce',
                'primary_color' => '#e21c20',
                'dark_color' => '#202044',
                'logo_version' => null,
                'logo_url' => null,
            ]]);
    }

    public function test_colors_must_be_hexadecimal(): void
    {
        $this->putJson('/api/v1/settings', $this->payload(['primary_color' => 'red', 'dark_color' => '#12345']))
            ->assertJsonValidationErrors(['primary_color', 'dark_color']);
    }

    public function test_the_owner_uploads_replaces_and_removes_the_logo(): void
    {
        Storage::fake('local');

        $first = $this->post('/api/v1/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 120)], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('branding.logo_version');
        $this->assertNotNull($first);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.logo_updated']);

        $url = $this->getJson('/api/v1/branding')->json('data.logo_url');
        $this->assertStringEndsWith("/api/v1/branding/logo?v={$first}", $url);
        $this->get('/api/v1/branding/logo')->assertOk()->assertHeader('Content-Type', 'image/png');

        // Les PDF intègrent le logo.
        $this->assertStringStartsWith('data:image/png;base64,', Setting::logoDataUri());

        $this->travel(5)->seconds();
        $this->post('/api/v1/settings/logo', ['logo' => UploadedFile::fake()->image('nouveau.jpg', 200, 200)], ['Accept' => 'application/json'])->assertOk();
        $this->assertCount(1, Storage::disk('local')->files('branding')); // l'ancien fichier est supprimé

        $this->deleteJson('/api/v1/settings/logo')->assertOk()->assertJsonPath('branding.logo_version', null);
        $this->assertCount(0, Storage::disk('local')->files('branding'));
        $this->get('/api/v1/branding/logo')->assertNotFound();
    }

    public function test_svg_logos_and_cashiers_are_refused(): void
    {
        Storage::fake('local');
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->post('/api/v1/settings/logo', ['logo' => $svg], ['Accept' => 'application/json'])->assertJsonValidationErrors('logo');

        Sanctum::actingAs(User::factory()->cashier()->create());
        $this->post('/api/v1/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 100, 100)], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson('/api/v1/settings/logo')->assertForbidden();
    }
}
