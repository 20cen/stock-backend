<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
