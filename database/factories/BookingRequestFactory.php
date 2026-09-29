<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\VisitPurpose;
use App\Models\BookingRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<BookingRequest>
 */
class BookingRequestFactory extends Factory
{
    protected $model = BookingRequest::class;

    public function definition(): array
    {
        $checkIn = fake()->dateTimeBetween('+2 days', '+30 days');
        $checkOut = (clone $checkIn)->modify('+2 days');

        return [
            'request_no' => 'REQ/'.now()->format('Y').'/'.fake()->unique()->numerify('#####'),
            'user_id' => User::factory(),

            // Deterministic on purpose. An earlier version used
            // fake()->randomElement(VisitPurpose::cases()), which made every test
            // using this factory flaky: TRAINING additionally requires a
            // programme name and GUEST requires a host employee, so roughly two
            // runs in three produced a request that could not be submitted.
            // Use ->purpose(...) or the named states for the other two.
            'purpose' => VisitPurpose::SELF->value,
            'check_in_date' => $checkIn->format('Y-m-d'),
            'check_out_date' => $checkOut->format('Y-m-d'),
            'nights' => 2,
            'total_members' => 1,
            'rooms_needed' => 1,
            'contact_mobile' => (string) fake()->numerify('9#########'),
            'contact_email' => fake()->safeEmail(),
            'status' => RequestStatus::DRAFT->value,
        ];
    }

    public function status(RequestStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function for_(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function purpose(VisitPurpose $purpose): static
    {
        return $this->state(fn () => ['purpose' => $purpose->value]);
    }

    /**
     * A training request complete with its required programme name, so it is
     * actually submittable.
     */
    public function training(string $programme = 'Induction Training for IRS Probationers'): static
    {
        return $this->state(fn () => [
            'purpose' => VisitPurpose::TRAINING->value,
            'training_programme' => $programme,
        ]);
    }

    /**
     * A guest request complete with its required host, so it is submittable.
     */
    public function guestOf(User $host): static
    {
        return $this->state(fn () => [
            'purpose' => VisitPurpose::GUEST->value,
            'host_employee_id' => $host->id,
        ]);
    }

    /**
     * A request already submitted and sitting with the given manager.
     */
    public function pendingManager(?User $manager = null): static
    {
        return $this->state(fn () => [
            'status' => RequestStatus::PENDING_MANAGER->value,
            'submitted_at' => now(),
            'manager_id' => $manager?->id,
        ]);
    }

    public function members(int $count): static
    {
        return $this->state(fn () => [
            'total_members' => $count,
            'rooms_needed' => (int) ceil($count / 2),
        ]);
    }

    public function dates(string $checkIn, string $checkOut): static
    {
        return $this->state(fn () => [
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'nights' => Carbon::parse($checkIn)
                ->diffInDays(Carbon::parse($checkOut)),
        ]);
    }
}
