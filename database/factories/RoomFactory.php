<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\RoomStatus;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    public function definition(): array
    {
        return [
            'room_number' => (string) fake()->unique()->numberBetween(100, 999),
            'room_type_id' => RoomType::factory(),
            'floor' => 1,
            'block' => 'A',
            'capacity' => null,
            'status' => RoomStatus::ACTIVE->value,
        ];
    }

    public function ofType(RoomType $type): static
    {
        return $this->state(fn () => ['room_type_id' => $type->id]);
    }

    public function number(string $number): static
    {
        return $this->state(fn () => ['room_number' => $number]);
    }

    public function capacity(int $capacity): static
    {
        return $this->state(fn () => ['capacity' => $capacity]);
    }

    public function maintenance(): static
    {
        return $this->state(fn () => [
            'status' => RoomStatus::MAINTENANCE->value,
            'block_reason' => 'Under repair',
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => [
            'status' => RoomStatus::BLOCKED->value,
            'block_reason' => 'Blocked by the Administration',
        ]);
    }

    /**
     * A date-ranged block. Passing null for $to creates the open-ended case that
     * broke the first draft of the availability SQL.
     */
    public function blockedBetween(string $from, ?string $to): static
    {
        return $this->state(fn () => [
            'status' => RoomStatus::ACTIVE->value,
            'blocked_from' => $from,
            'blocked_to' => $to,
            'block_reason' => 'Reserved',
        ]);
    }
}
