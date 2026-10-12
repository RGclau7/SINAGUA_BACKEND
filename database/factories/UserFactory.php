<?php

namespace Database\Factories;

use App\Models\Community;
use App\Models\Roles;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

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
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'community_id' => Community::factory(),
        ];
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

    public function forCommunity(Community $community): static
    {
        return $this->state(fn () => ['community_id' => $community->id]);
    }

    /**
     * Superadministrador: no pertenece a ninguna comunidad.
     */
    public function superAdmin(): static
    {
        return $this->state(fn () => ['community_id' => null])
            ->afterCreating(fn (User $user) => $user->assignRole(Role::findOrCreate(Roles::SUPERADMIN, 'web')));
    }
}
