<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\VisitPurpose;
use App\Models\RoomType;
use App\Models\Tariff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tariff>
 */
class TariffFactory extends Factory
{
    protected $model = Tariff::class;

    public function definition(): array
    {
        return [
            'room_type_id' => RoomType::factory(),
            'purpose' => null,                 // applies to every purpose
            'amount_per_night' => '1200.00',
            'effective_from' => '2020-01-01',
            'effective_to' => null,            // open-ended
            'is_active' => true,
        ];
    }

    public function forPurpose(VisitPurpose $purpose): static
    {
        return $this->state(fn () => ['purpose' => $purpose->value]);
    }

    public function amount(string $amount): static
    {
        return $this->state(fn () => ['amount_per_night' => $amount]);
    }

    public function effective(string $from, ?string $to = null): static
    {
        return $this->state(fn () => ['effective_from' => $from, 'effective_to' => $to]);
    }
}
