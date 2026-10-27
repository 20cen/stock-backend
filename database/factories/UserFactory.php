<?php

namespace Database\Factories;

use App\Enums\RoleCode;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role_id' => fn () => self::roleId(RoleCode::Cashier),
            'active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => ['role_id' => self::roleId(RoleCode::Owner)]);
    }

    public function cashier(): static
    {
        return $this->state(fn () => ['role_id' => self::roleId(RoleCode::Cashier)]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    private static function roleId(RoleCode $code): int
    {
        if (! Role::where('code', $code->value)->exists()) {
            (new RolePermissionSeeder)->run();
        }

        return Role::where('code', $code->value)->value('id');
    }
}
