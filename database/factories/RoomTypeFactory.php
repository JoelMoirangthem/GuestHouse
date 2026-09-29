<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RoomType>
 */
class RoomTypeFactory extends Factory
{
    protected $model = RoomType::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(Str::random(8)),
            'name' => 'Standard AC',
            'category' => 'NORMAL',
            'default_capacity' => 2,
            'has_ac' => true,
            'sort_order' => 1,
            'is_active' => true,
        ];
    }

    public function vip(): static
    {
        return $this->state(fn () => ['category' => 'VIP', 'name' => 'Deluxe AC']);
    }

    public function capacity(int $capacity): static
    {
        return $this->state(fn () => ['default_capacity' => $capacity]);
    }
}
