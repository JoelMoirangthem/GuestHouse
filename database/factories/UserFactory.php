<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'employee_code' => 'GH-'.strtoupper(Str::random(6)),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => (string) fake()->numerify('9#########'),
            'email_verified_at' => now(),
            'password' => Hash::make('Password@123'),
            'designation' => fake()->jobTitle(),
            'department' => 'Assessment',
            'is_active' => true,
            // Defaults to the plain user role; override with ->role(...).
            'role_id' => fn () => Role::where('slug', RoleSlug::USER->value)->value('id')
                ?? Role::factory()->create(['slug' => RoleSlug::USER->value, 'name' => 'User'])->id,
        ];
    }

    /**
     * Assign a specific workflow role. The role row must already be seeded —
     * tests call RolePermissionSeeder so that permission checks are realistic
     * rather than passing against an empty permission set.
     */
    public function role(RoleSlug $slug): static
    {
        return $this->state(fn () => [
            'role_id' => Role::where('slug', $slug->value)->firstOrFail()->id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function reportingTo(User $manager): static
    {
        return $this->state(fn () => ['reporting_manager_id' => $manager->id]);
    }
}
