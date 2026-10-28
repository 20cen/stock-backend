<?php

namespace Tests\Feature\Partners;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_a_customer_with_only_a_name(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());

        $this->postJson('/api/v1/customers', ['name' => 'Aminata Diallo'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Aminata Diallo')
            ->assertJsonPath('data.phone', null)
            ->assertJsonMissingPath('data.contact_name');

        $this->assertSame(1, AuditLog::where('action', 'customer.created')->count());
    }

    public function test_namesakes_are_allowed(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());
        Customer::factory()->create(['name' => 'Jean Kouassi']);

        $this->postJson('/api/v1/customers', ['name' => 'Jean Kouassi', 'phone' => '0707070707'])->assertCreated();
    }

    public function test_a_customer_can_be_found_by_phone_and_updated(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create());
        $customer = Customer::factory()->create(['name' => 'Moussa Traoré', 'phone' => '0505050505']);

        $this->getJson('/api/v1/customers?search=0505')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $customer->id);

        $this->putJson("/api/v1/customers/{$customer->id}", ['address' => 'Cocody, Abidjan'])
            ->assertOk()
            ->assertJsonPath('data.address', 'Cocody, Abidjan');
    }

    public function test_cashier_cannot_manage_customers(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create());

        $this->getJson('/api/v1/customers')->assertForbidden();
    }
}
