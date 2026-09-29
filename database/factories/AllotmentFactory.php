<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\AllotmentStatus;
use App\Models\Allotment;
use App\Models\BookingRequest;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Allotment>
 */
class AllotmentFactory extends Factory
{
    protected $model = Allotment::class;

    public function definition(): array
    {
        return [
            'allotment_no' => 'ALT/'.now()->format('Y').'/'.fake()->unique()->numerify('#####'),
            'booking_request_id' => BookingRequest::factory(),
            'room_id' => Room::factory(),
            'check_in_date' => now()->addDays(5)->format('Y-m-d'),
            'check_out_date' => now()->addDays(7)->format('Y-m-d'),
            'occupants_count' => 1,
            'status' => AllotmentStatus::ALLOTTED->value,
            'rate_per_night' => '1200.00',
            'total_amount' => '2400.00',
            'allotted_by' => User::factory(),
            'allotted_at' => now(),
            'qr_token' => Str::random(40),
        ];
    }

    public function forRoom(Room $room): static
    {
        return $this->state(fn () => ['room_id' => $room->id]);
    }

    public function dates(string $from, string $to): static
    {
        return $this->state(fn () => ['check_in_date' => $from, 'check_out_date' => $to]);
    }

    public function status(AllotmentStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function cancelled(): static
    {
        return $this->status(AllotmentStatus::CANCELLED);
    }

    public function checkedOut(): static
    {
        return $this->status(AllotmentStatus::CHECKED_OUT);
    }
}
