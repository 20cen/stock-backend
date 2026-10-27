<?php

namespace Database\Seeders;

use App\Enums\RoleCode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([RolePermissionSeeder::class, LocationSeeder::class]);

        $username = env('INITIAL_OWNER_USERNAME', 'proprietaire');

        if (User::where('username', $username)->exists()) {
            return;
        }

        $password = env('INITIAL_OWNER_PASSWORD') ?: Str::password(16, symbols: false);

        User::create([
            'name' => 'Propriétaire',
            'username' => $username,
            'password' => $password,
            'role_id' => Role::where('code', RoleCode::Owner->value)->value('id'),
        ]);

        $this->command?->warn("Compte propriétaire créé : {$username} / {$password} — changez ce mot de passe après la première connexion.");
    }
}
