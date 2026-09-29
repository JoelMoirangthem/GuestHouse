<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\RoomStatus;
use App\Models\Allotment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tariff;
use Illuminate\Database\Seeder;

/**
 * Room types, tariffs and the physical room inventory.
 *
 * Inventory as supplied by the client on 2026-09-29: Block A (Hostel A) only,
 * floor 0 (the two VIP suites) and floors 1-7. Floor 0 no longer holds a single
 * ground-floor room; it holds VIP Suite 1 and VIP Suite 2 (SUITE_AC type). The
 * list totals 20 rooms. Block B (Hostel B) is not in service yet and is
 * deliberately not seeded; see config gh.room_board_blocks.
 */
class RoomSeeder extends Seeder
{
    /**
     * Block A (Hostel A), floor => room numbers. Floor 0 holds the VIP suites.
     *
     * @var array<int, array<int, string>>
     */
    public const BLOCK_A = [
        0 => ['VIP-01', 'VIP-02'],
        1 => ['101', '102'],
        2 => ['201', '202'],
        3 => ['301', '302'],
        4 => ['401', '402'],
        5 => ['501', '502'],
        6 => ['601', '602', '603', '604'],
        7 => ['701', '702', '703', '704'],
    ];

    /** VIP suite room numbers (floor 0), given the SUITE_AC room type. */
    private const VIP_SUITES = ['VIP-01', 'VIP-02'];

    /** Room type given to every Block A room. Change per room in Admin → Rooms. */
    private const BLOCK_A_TYPE = 'STANDARD_AC';

    /** Room type for the VIP suites on floor 0. */
    private const VIP_SUITE_TYPE = 'SUITE_AC';

    public function run(): void
    {
        $types = [
            ['DELUXE_AC',   'Deluxe AC',   'VIP',    2, true,  1, '2500.00'],
            ['SUITE_AC',    'Suite AC',    'VIP',    3, true,  2, '3500.00'],
            ['STANDARD_AC', 'Standard AC', 'NORMAL', 2, true,  3, '1200.00'],
            ['NON_AC',      'Non-AC',      'NORMAL', 2, false, 4, '700.00'],
        ];

        $typeIds = [];

        foreach ($types as [$code, $name, $category, $capacity, $hasAc, $order, $rate]) {
            $type = RoomType::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'category' => $category,
                    'default_capacity' => $capacity,
                    'has_ac' => $hasAc,
                    'sort_order' => $order,
                    'is_active' => true,
                    'description' => $category === 'VIP'
                        ? 'Reserved for senior officers and official guests.'
                        : 'Standard accommodation for trainees and officers.',
                ],
            );

            // A single open-ended tariff per type, effective from the start of the
            // financial year.
            Tariff::updateOrCreate(
                ['room_type_id' => $type->id, 'purpose' => null, 'effective_from' => '2026-04-01'],
                ['amount_per_night' => $rate, 'effective_to' => null, 'is_active' => true],
            );

            $typeIds[$code] = $type->id;
        }

        $numbers = [];

        foreach (self::BLOCK_A as $floor => $rooms) {
            foreach ($rooms as $number) {
                $typeCode = in_array($number, self::VIP_SUITES, true)
                    ? self::VIP_SUITE_TYPE
                    : self::BLOCK_A_TYPE;

                Room::updateOrCreate(
                    ['room_number' => $number],
                    [
                        'room_type_id' => $typeIds[$typeCode],
                        'floor' => $floor,
                        'block' => 'A',
                        'capacity' => null,          // inherit the type default
                        'status' => RoomStatus::ACTIVE,
                        'block_reason' => null,
                        'blocked_from' => null,
                        'blocked_to' => null,
                    ],
                );
                $numbers[] = $number;
            }
        }

        $this->pruneRoomsNotInInventory($numbers);
    }

    /**
     * Re-seeding an older database removes rooms that are no longer part of the
     * inventory. A room with allotment history is kept (its bookings and
     * revenue must stay intact) but BLOCKED so it can never be allotted again.
     *
     * @param  array<int, string>  $keep
     */
    private function pruneRoomsNotInInventory(array $keep): void
    {
        $stale = Room::query()->whereNotIn('room_number', $keep)->get();

        foreach ($stale as $room) {
            if (Allotment::where('room_id', $room->id)->exists()) {
                $room->update([
                    'status' => RoomStatus::BLOCKED,
                    'block_reason' => 'Removed from inventory',
                ]);

                continue;
            }

            $room->delete();
        }
    }
}
