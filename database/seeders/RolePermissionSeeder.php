<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionCode;
use App\Enums\RoleCode;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Synchronise les permissions et la matrice des rôles. Peut être relancé sans risque.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCode::cases() as $code) {
            Permission::updateOrCreate(['code' => $code->value], ['description' => $code->description()]);
        }

        $ids = Permission::pluck('id', 'code');

        foreach (RoleCode::cases() as $roleCode) {
            $role = Role::updateOrCreate(['code' => $roleCode->value], ['name' => $roleCode->label()]);
            $role->permissions()->sync(
                array_map(fn (PermissionCode $p) => $ids[$p->value], $roleCode->permissions())
            );
        }
    }
}
