<?php

namespace Tests\Feature\Partners;

use App\Models\AuditLog;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_a_supplier_and_it_is_audited(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());

        $this->postJson('/api/v1/suppliers', [
            'name' => '  InfoDistrib SARL ',
            'contact_name' => 'M. Koné',
            'phone' => '+225 07 01 02 03 04',
            'email' => 'Ventes@InfoDistrib.ci',
            'identifier' => 'CI-ABJ-2020-B-1234',
            'address' => '',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'InfoDistrib SARL')
            ->assertJsonPath('data.email', 'ventes@infodistrib.ci')
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.active', true);

        $this->assertSame(1, AuditLog::where('action', 'supplier.created')->count());
    }

    public function test_two_suppliers_cannot_share_a_name(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());
        Supplier::factory()->create(['name' => 'Bureau Plus']);

        $this->postJson('/api/v1/suppliers', ['name' => 'BUREAU PLUS'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Un fournisseur porte déjà ce nom.');
    }

    public function test_an_invalid_phone_or_email_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());

        $this->postJson('/api/v1/suppliers', ['name' => 'X', 'phone' => 'abc', 'email' => 'pas-un-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone', 'email']);
    }

    /** SFD-FOU : un fournisseur utilisé dans l'historique est désactivé, jamais supprimé. */
    public function test_a_supplier_is_deactivated_not_deleted_and_the_change_is_audited(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());
        $supplier = Supplier::factory()->create(['phone' => '0102030405']);

        $this->putJson("/api/v1/suppliers/{$supplier->id}", ['active' => false, 'phone' => '0607080910'])
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $log = AuditLog::where('action', 'supplier.updated')->sole();
        $this->assertSame(['phone' => '0102030405', 'active' => true], $log->before_data);
        $this->assertSame(405, $this->deleteJson("/api/v1/suppliers/{$supplier->id}")->status());
    }

    public function test_the_list_is_searchable_and_filtered_by_status(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());
        Supplier::factory()->create(['name' => 'Papeterie Centrale', 'contact_name' => 'Awa']);
        Supplier::factory()->create(['name' => 'Tech Import']);
        Supplier::factory()->inactive()->create(['name' => 'Ancien fournisseur']);

        $this->getJson('/api/v1/suppliers')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'Papeterie Centrale');
        $this->getJson('/api/v1/suppliers?search=awa')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/suppliers?status=inactive')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/suppliers?status=all')->assertJsonCount(3, 'data');
    }

    public function test_cashier_cannot_access_suppliers(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create());

        $this->getJson('/api/v1/suppliers')->assertForbidden();
        $this->postJson('/api/v1/suppliers', ['name' => 'X'])->assertForbidden();
    }
}
