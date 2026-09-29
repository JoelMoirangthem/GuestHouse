<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BookingRequest;
use App\Models\RequestOccupant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RequestOccupant>
 */
class RequestOccupantFactory extends Factory
{
    protected $model = RequestOccupant::class;

    public function definition(): array
    {
        return [
            'booking_request_id' => BookingRequest::factory(),
            'name' => fake()->name(),
            'age' => fake()->numberBetween(21, 60),
            'gender' => fake()->randomElement(['M', 'F']),
            'is_primary' => false,
            'id_proof_type' => 'AADHAAR',
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => ['is_primary' => true]);
    }

    /**
     * Sets a full identity number through the model accessor so the encrypted
     * column and the last-4 display value stay consistent.
     */
    public function withAadhaar(string $number = '123456781234'): static
    {
        return $this->afterMaking(function (RequestOccupant $o) use ($number) {
            $o->setIdProofNumber($number);
        })->afterCreating(function (RequestOccupant $o) use ($number) {
            $o->setIdProofNumber($number);
            $o->save();
        });
    }
}
