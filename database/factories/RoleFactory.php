<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(1),
            'name' => fake()->word(),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
