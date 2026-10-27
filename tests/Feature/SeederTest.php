<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_creates_locations_roles_and_an_owner_and_can_be_rerun(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(['BTQ', 'DEF', 'MAG'], Location::orderBy('code')->pluck('code')->all());
        $this->assertSame(['BTQ'], Location::where('is_sellable', true)->pluck('code')->all());

        $owner = User::sole();
        $this->assertTrue($owner->isOwner());
        $this->assertCount(count(Permission::cases()), $owner->permissionCodes());
    }
}
