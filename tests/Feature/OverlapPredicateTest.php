<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Contracts\AvailabilityQueryInterface;
use App\Domain\Enums\AllotmentStatus;
use App\Models\Allotment;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TESTING.md — OverlapPredicateTest and AvailabilitySqlRegressionTest.
 *
 * The most important tests in the project. The overlap rule decides whether the
 * guest house can be double-booked, and whether it silently loses one night of
 * capacity on every single reservation.
 */
class OverlapPredicateTest extends TestCase
{
    use RefreshDatabase;

    private RoomType $type;

    private Room $room;

    private AvailabilityQueryInterface $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->type = RoomType::factory()->create(['default_capacity' => 2]);
        $this->room = Room::factory()->ofType($this->type)->number('101')->create();
        $this->query = app(AvailabilityQueryInterface::class);
    }

    /**
     * The six boundary cases from SCHEMA.md section 14, verbatim.
     *
     * Rows 1 and 5 are the ones that catch a closed-interval implementation. If
     * either fails, the guest house is losing a night of capacity per booking.
     *
     * @return array<string, array{0:string, 1:string, 2:string, 3:string, 4:bool}>
     */
    public static function boundaries(): array
    {
        return [
            'checkout day is reusable (20-22 vs 22-24)' => ['2026-10-20', '2026-10-22', '2026-10-22', '2026-10-24', false],
            'partial overlap at the end (20-22 vs 21-23)' => ['2026-10-20', '2026-10-22', '2026-10-21', '2026-10-23', true],
            'partial overlap at the start (20-22 vs 19-21)' => ['2026-10-20', '2026-10-22', '2026-10-19', '2026-10-21', true],
            'identical dates (20-22 vs 20-22)' => ['2026-10-20', '2026-10-22', '2026-10-20', '2026-10-22', true],
            'arrival day is reusable (20-22 vs 18-20)' => ['2026-10-20', '2026-10-22', '2026-10-18', '2026-10-20', false],
            'fully contained (20-26 vs 21-23)' => ['2026-10-20', '2026-10-26', '2026-10-21', '2026-10-23', true],
        ];
    }

    #[Test]
    #[DataProvider('boundaries')]
    public function the_overlap_predicate_matches_the_specification(
        string $existingFrom,
        string $existingTo,
        string $newFrom,
        string $newTo,
        bool $shouldClash,
    ): void {
        Allotment::factory()->forRoom($this->room)->dates($existingFrom, $existingTo)->create();

        $free = $this->query->isStillFree($this->room->id, $newFrom, $newTo);

        $this->assertSame(
            ! $shouldClash,
            $free,
            $shouldClash
                ? "Expected {$newFrom}..{$newTo} to clash with {$existingFrom}..{$existingTo}."
                : "Expected {$newFrom}..{$newTo} NOT to clash with {$existingFrom}..{$existingTo}."
        );
    }

    #[Test]
    #[DataProvider('boundaries')]
    public function the_summary_count_agrees_with_the_predicate(
        string $existingFrom,
        string $existingTo,
        string $newFrom,
        string $newTo,
        bool $shouldClash,
    ): void {
        // The display count and the write-path predicate must never disagree, or
        // the screen will offer a room the allotment step then refuses.
        Allotment::factory()->forRoom($this->room)->dates($existingFrom, $existingTo)->create();

        $summary = collect($this->query->summary($newFrom, $newTo))
            ->firstWhere('room_type_id', $this->type->id);

        $this->assertSame($shouldClash ? 0 : 1, $summary['available']);
        $this->assertSame($shouldClash ? 1 : 0, $summary['booked']);
    }

    // ------------------------------------------------- statuses that do not block

    #[Test]
    public function a_cancelled_allotment_does_not_block_the_room(): void
    {
        Allotment::factory()->forRoom($this->room)
            ->dates('2026-10-20', '2026-10-22')->cancelled()->create();

        $this->assertTrue($this->query->isStillFree($this->room->id, '2026-10-20', '2026-10-22'));
    }

    #[Test]
    public function a_completed_stay_does_not_block_the_room(): void
    {
        // Otherwise the guest house would slowly lose its entire inventory to
        // historical records.
        Allotment::factory()->forRoom($this->room)
            ->dates('2026-10-20', '2026-10-22')->checkedOut()->create();

        $this->assertTrue($this->query->isStillFree($this->room->id, '2026-10-20', '2026-10-22'));
    }

    #[Test]
    public function an_occupied_room_blocks_the_dates(): void
    {
        Allotment::factory()->forRoom($this->room)
            ->dates('2026-10-20', '2026-10-22')
            ->status(AllotmentStatus::CHECKED_IN)
            ->create();

        $this->assertFalse($this->query->isStillFree($this->room->id, '2026-10-21', '2026-10-23'));
    }

    // ------------------------------------------------- REGRESSION 1: NULL blocked_to

    #[Test]
    public function a_room_blocked_with_no_end_date_is_never_available(): void
    {
        // THE REGRESSION. blocked_to IS NULL means the block runs indefinitely.
        // A bare comparison yields NULL, and NOT(NULL) is not TRUE, so the first
        // draft of this query reported the room as available. Found while auditing
        // the specification, before any code existed.
        $room = Room::factory()->ofType($this->type)->number('201')
            ->blockedBetween('2026-01-01', null)->create();

        $this->assertFalse(
            $this->query->isStillFree($room->id, '2026-10-20', '2026-10-22'),
            'A room with an open-ended block was reported as free.'
        );

        $this->assertTrue($room->fresh()->isBlockedDuring('2026-10-20', '2026-10-22'));

        // and it must not appear in the selection list either
        $ids = $this->query->availableRooms('2026-10-20', '2026-10-22')->pluck('id')->all();
        $this->assertNotContains($room->id, $ids);
    }

    #[Test]
    public function a_closed_block_applies_only_within_its_own_range(): void
    {
        $room = Room::factory()->ofType($this->type)->number('202')
            ->blockedBetween('2026-10-01', '2026-10-10')->create();

        $this->assertFalse($this->query->isStillFree($room->id, '2026-10-05', '2026-10-07'));
        $this->assertTrue($this->query->isStillFree($room->id, '2026-10-20', '2026-10-22'));
    }

    #[Test]
    public function rooms_out_of_service_are_never_available(): void
    {
        $maintenance = Room::factory()->ofType($this->type)->number('203')->maintenance()->create();
        $blocked = Room::factory()->ofType($this->type)->number('204')->blocked()->create();

        $this->assertFalse($this->query->isStillFree($maintenance->id, '2026-10-20', '2026-10-22'));
        $this->assertFalse($this->query->isStillFree($blocked->id, '2026-10-20', '2026-10-22'));
    }

    // -------------------------------------- REGRESSION 2: candidate selection

    #[Test]
    public function locking_skips_occupied_rooms_instead_of_returning_nothing(): void
    {
        // THE SECOND REGRESSION. With the three lowest-numbered rooms occupied and
        // two rooms needed, the query must return two free higher-numbered rooms.
        //
        // The original draft filtered only on room status before LIMIT ... FOR
        // UPDATE, so it locked the first N rooms whether or not they were free and
        // then found nothing — reporting "no room available" to a fully approved
        // officer while other rooms of the same type sat empty.
        $rooms = collect(['301', '302', '303', '304', '305'])
            ->map(fn ($n) => Room::factory()->ofType($this->type)->number($n)->create());

        foreach ($rooms->take(3) as $occupied) {
            Allotment::factory()->forRoom($occupied)->dates('2026-10-20', '2026-10-22')->create();
        }

        \DB::beginTransaction();

        $candidates = $this->query->lockCandidates($this->type->id, '2026-10-20', '2026-10-22', 2);

        \DB::rollBack();

        $this->assertCount(2, $candidates, 'The lock query returned the wrong number of candidates.');

        // The three occupied rooms must not appear, whichever free rooms are
        // chosen. Room 101 from setUp is also free and sorts first by room_number,
        // so the result is not simply "the last two created".
        $occupiedIds = $rooms->take(3)->pluck('id')->all();

        foreach ($occupiedIds as $id) {
            $this->assertNotContains($id, $candidates, 'An occupied room was locked as a candidate.');
        }

        // And every returned room really is free.
        foreach ($candidates as $id) {
            $this->assertTrue(
                $this->query->isStillFree($id, '2026-10-20', '2026-10-22'),
                "Room id {$id} was offered as a candidate but is not free."
            );
        }
    }

    #[Test]
    public function locking_returns_fewer_candidates_when_fewer_are_free(): void
    {
        $rooms = collect(['401', '402'])
            ->map(fn ($n) => Room::factory()->ofType($this->type)->number($n)->create());

        Allotment::factory()->forRoom($rooms[0])->dates('2026-10-20', '2026-10-22')->create();

        \DB::beginTransaction();
        $candidates = $this->query->lockCandidates($this->type->id, '2026-10-20', '2026-10-22', 5);
        \DB::rollBack();

        // Only room 402 is free, plus the 101 created in setUp.
        $this->assertContains($rooms[1]->id, $candidates);
        $this->assertNotContains($rooms[0]->id, $candidates);
    }

    // ------------------------------------------------- inventory invariant

    #[Test]
    public function available_plus_booked_plus_blocked_always_equals_total(): void
    {
        // REPORTS.md section 4 requires this. A room counted twice, or not at all,
        // makes every occupancy figure wrong.
        Room::factory()->ofType($this->type)->number('501')->create();
        Room::factory()->ofType($this->type)->number('502')->maintenance()->create();
        Room::factory()->ofType($this->type)->number('503')->blockedBetween('2026-10-01', null)->create();
        $busy = Room::factory()->ofType($this->type)->number('504')->create();

        Allotment::factory()->forRoom($busy)->dates('2026-10-20', '2026-10-22')->create();

        $row = collect($this->query->summary('2026-10-20', '2026-10-22'))
            ->firstWhere('room_type_id', $this->type->id);

        $this->assertSame(
            $row['total'],
            $row['available'] + $row['booked'] + $row['blocked'],
            'Rooms are being double-counted or dropped between buckets.'
        );

        // 101 and 501 free; 504 booked; 502 and 503 blocked.
        $this->assertSame(5, $row['total']);
        $this->assertSame(2, $row['available']);
        $this->assertSame(1, $row['booked']);
        $this->assertSame(2, $row['blocked']);
    }

    #[Test]
    public function a_room_can_be_reused_the_day_a_guest_leaves(): void
    {
        // The single most valuable consequence of the half-open interval: it
        // recovers one night of capacity on every booking.
        Allotment::factory()->forRoom($this->room)->dates('2026-10-20', '2026-10-22')->create();

        $ids = $this->query->availableRooms('2026-10-22', '2026-10-24')->pluck('id')->all();

        $this->assertContains($this->room->id, $ids);
    }
}
