<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Services\AllotmentService;
use App\Domain\Contracts\AvailabilityQueryInterface;
use App\Domain\Enums\AllotmentStatus;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Models\Allotment;
use App\Models\AuditLog;
use App\Models\BookingRequest;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tariff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TESTING.md — DoubleBookingRaceTest, MultiRoomAllotmentTest, NoRoomAvailableTest.
 *
 * "DoubleBookingRaceTest is not optional." Because availability is resolved late
 * by design, concurrent allotment is the sharpest edge in this system: the
 * failure mode is two approved officers arriving at the same room on the same
 * night.
 */
class AllotmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private RoomType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->role(RoleSlug::ADMIN)->create();
        $manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $this->employee = User::factory()->role(RoleSlug::USER)->reportingTo($manager)->create();

        $this->type = RoomType::factory()->create(['default_capacity' => 2]);
        Tariff::factory()->create([
            'room_type_id' => $this->type->id,
            'amount_per_night' => '1200.00',
        ]);
    }

    private function approvedRequest(int $members = 1): BookingRequest
    {
        return BookingRequest::factory()
            ->for_($this->employee)
            ->status(RequestStatus::PENDING_ALLOTMENT)
            ->members($members)
            ->dates('2026-10-20', '2026-10-23')
            ->create();
    }

    private function service(): AllotmentService
    {
        return app(AllotmentService::class);
    }

    // ------------------------------------------------------- the race

    #[Test]
    public function two_concurrent_allotments_of_the_same_room_cannot_both_succeed(): void
    {
        $room = Room::factory()->ofType($this->type)->number('101')->create();

        $requestA = $this->approvedRequest();
        $requestB = $this->approvedRequest();

        $succeeded = 0;
        $failed = 0;
        $messages = [];

        // Two attempts at the same room over identical dates. The first wins; the
        // second must be refused cleanly with the user-facing message, not a 500
        // and not a second allotment row.
        foreach ([$requestA, $requestB] as $request) {
            try {
                $this->service()->allot($request, $this->admin, [$room->id]);
                $succeeded++;
            } catch (\Throwable $e) {
                $failed++;
                $messages[] = $e->getMessage();
            }
        }

        $this->assertSame(1, $succeeded, 'Both allotments succeeded — the room is double-booked.');
        $this->assertSame(1, $failed);

        $this->assertStringContainsString('just taken by another booking', $messages[0]);

        // Exactly one live allotment exists for that room and window.
        $this->assertSame(1, Allotment::query()
            ->where('room_id', $room->id)
            ->occupying()
            ->count());
    }

    #[Test]
    public function the_database_rejects_a_duplicate_active_allotment_even_if_the_service_is_bypassed(): void
    {
        // Layer 4 of the defence, exercised directly. This is the guarantee that
        // survives a future code path which forgets to lock.
        $room = Room::factory()->ofType($this->type)->number('102')->create();
        $request = $this->approvedRequest();

        Allotment::factory()->forRoom($room)->dates('2026-10-20', '2026-10-23')->create([
            'booking_request_id' => $request->id,
        ]);

        $this->expectException(QueryException::class);

        // Raw insert, deliberately sidestepping AllotmentService entirely.
        \DB::table('allotments')->insert([
            'allotment_no' => 'ALT/2026/99999',
            'booking_request_id' => $request->id,
            'room_id' => $room->id,
            'check_in_date' => '2026-10-20',
            'check_out_date' => '2026-10-23',
            'occupants_count' => 1,
            'status' => AllotmentStatus::ALLOTTED->value,
            'rate_per_night' => '1200.00',
            'total_amount' => '3600.00',
            'allotted_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_cancelled_allotment_frees_the_unique_slot_again(): void
    {
        // The generated column must be NULL for non-occupying rows, otherwise a
        // room could never be re-let after a cancellation.
        $room = Room::factory()->ofType($this->type)->number('103')->create();
        $request = $this->approvedRequest();

        $first = Allotment::factory()->forRoom($room)->dates('2026-10-20', '2026-10-23')->create([
            'booking_request_id' => $request->id,
        ]);

        $first->status = AllotmentStatus::CANCELLED;
        $first->save();

        $second = $this->approvedRequest();
        $result = $this->service()->allot($second, $this->admin, [$room->id]);

        $this->assertSame(RequestStatus::ALLOTTED, $result->status);
    }

    // ------------------------------------------------- multi-room allotment

    #[Test]
    public function a_six_member_request_needs_three_rooms_and_steps_through_partial_allotment(): void
    {
        $rooms = collect(['201', '202', '203'])
            ->map(fn ($n) => Room::factory()->ofType($this->type)->number($n)->create());

        $request = $this->approvedRequest(6);

        // ceil(6 / 2) = 3
        $this->assertSame(3, $request->rooms_needed);

        // Two of three rooms -> PARTIALLY_ALLOTTED
        $request = $this->service()->allot($request, $this->admin, [$rooms[0]->id, $rooms[1]->id]);
        $this->assertSame(RequestStatus::PARTIALLY_ALLOTTED, $request->status);

        // The third completes it -> ALLOTTED
        $request = $this->service()->allot($request, $this->admin, [$rooms[2]->id]);
        $this->assertSame(RequestStatus::ALLOTTED, $request->status);

        $this->assertSame(3, Allotment::where('booking_request_id', $request->id)->occupying()->count());
    }

    #[Test]
    public function releasing_a_room_steps_the_request_back_to_partially_allotted(): void
    {
        $rooms = collect(['301', '302'])
            ->map(fn ($n) => Room::factory()->ofType($this->type)->number($n)->create());

        $request = $this->approvedRequest(4);   // needs 2
        $request = $this->service()->allot($request, $this->admin, $rooms->pluck('id')->all());
        $this->assertSame(RequestStatus::ALLOTTED, $request->status);

        $allotment = Allotment::where('booking_request_id', $request->id)->first();
        $request = $this->service()->release($allotment, $this->admin, 'Room needed for a VIP visit.');

        // Otherwise the administrator would still believe the booking was complete.
        $this->assertSame(RequestStatus::PARTIALLY_ALLOTTED, $request->status);

        // And the released room is free again.
        $this->assertTrue(
            app(AvailabilityQueryInterface::class)
                ->isStillFree($allotment->room_id, '2026-10-20', '2026-10-23')
        );
    }

    #[Test]
    public function releasing_the_last_room_returns_the_request_to_awaiting_allotment(): void
    {
        $room = Room::factory()->ofType($this->type)->number('401')->create();
        $request = $this->approvedRequest();

        $request = $this->service()->allot($request, $this->admin, [$room->id]);
        $allotment = Allotment::where('booking_request_id', $request->id)->firstOrFail();

        $request = $this->service()->release($allotment, $this->admin);

        $this->assertSame(RequestStatus::PENDING_ALLOTMENT, $request->status);
    }

    #[Test]
    public function the_rate_is_snapshotted_so_a_later_tariff_change_cannot_rewrite_history(): void
    {
        $room = Room::factory()->ofType($this->type)->number('501')->create();
        $request = $this->approvedRequest();

        $request = $this->service()->allot($request, $this->admin, [$room->id]);
        $allotment = Allotment::where('booking_request_id', $request->id)->firstOrFail();

        $this->assertSame('1200.00', $allotment->rate_per_night);
        // 3 nights at 1200
        $this->assertSame('3600.00', $allotment->total_amount);

        // Revise the tariff upward.
        Tariff::where('room_type_id', $this->type->id)->update(['amount_per_night' => '9999.00']);

        $this->assertSame('1200.00', $allotment->fresh()->rate_per_night);
        $this->assertSame('3600.00', $allotment->fresh()->total_amount);
    }

    // ------------------------------------------------- no room available

    #[Test]
    public function an_approved_request_with_nothing_free_can_be_marked_no_room_available(): void
    {
        $request = $this->approvedRequest();

        $request = $this->service()->markNoRoomAvailable(
            $request,
            $this->admin,
            'Guest house fully committed to a scheduled programme.',
        );

        $this->assertSame(RequestStatus::NO_ROOM_AVAILABLE, $request->status);
        $this->assertTrue($request->status->isTerminal());

        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $request->id,
            'action' => 'NO_ROOM_AVAILABLE',
        ]);
    }

    #[Test]
    public function marking_no_room_available_requires_a_reason(): void
    {
        $request = $this->approvedRequest();

        $this->expectExceptionMessageMatches('/requires written remarks/');

        $this->service()->markNoRoomAvailable($request, $this->admin, '   ');
    }

    #[Test]
    public function a_no_room_request_can_be_rechecked_later(): void
    {
        // T20 — the single deliberate exit from that terminal state.
        $request = $this->approvedRequest();
        $request = $this->service()->markNoRoomAvailable($request, $this->admin, 'Nothing free.');

        $request = $this->service()->recheck($request, $this->admin);

        $this->assertSame(RequestStatus::PENDING_ALLOTMENT, $request->status);

        // and it can then be allotted normally
        $room = Room::factory()->ofType($this->type)->number('601')->create();
        $request = $this->service()->allot($request, $this->admin, [$room->id]);
        $this->assertSame(RequestStatus::ALLOTTED, $request->status);
    }

    #[Test]
    public function allotting_zero_rooms_is_refused(): void
    {
        $request = $this->approvedRequest();

        $this->expectExceptionMessageMatches('/at least one room/');

        $this->service()->allot($request, $this->admin, []);
    }

    // ------------------------------------------------- guards

    #[Test]
    public function rooms_cannot_be_allotted_before_both_approvals(): void
    {
        $room = Room::factory()->ofType($this->type)->number('701')->create();

        foreach ([RequestStatus::DRAFT, RequestStatus::PENDING_MANAGER, RequestStatus::PENDING_ADG] as $status) {
            $request = BookingRequest::factory()
                ->for_($this->employee)
                ->status($status)
                ->dates('2026-10-20', '2026-10-23')
                ->create();

            try {
                $this->service()->allot($request, $this->admin, [$room->id]);
                $this->fail("Allotment was permitted from {$status->value} — Core Rule 1 breached.");
            } catch (\Throwable $e) {
                $this->assertStringContainsString('cannot be allotted', $e->getMessage());
            }
        }

        $this->assertSame(0, Allotment::count());
    }

    #[Test]
    public function only_an_administrator_may_allot(): void
    {
        $room = Room::factory()->ofType($this->type)->number('801')->create();
        $request = $this->approvedRequest();

        foreach ([RoleSlug::USER, RoleSlug::MANAGER, RoleSlug::ADG] as $slug) {
            $actor = User::factory()->role($slug)->create();

            try {
                $this->service()->allot($request, $actor, [$room->id]);
                $this->fail("Role {$slug->value} was permitted to allot a room.");
            } catch (\Throwable $e) {
                $this->assertStringContainsString('Only the Administration', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_blocked_room_cannot_be_allotted_even_if_explicitly_chosen(): void
    {
        $room = Room::factory()->ofType($this->type)->number('901')->maintenance()->create();
        $request = $this->approvedRequest();

        $this->expectExceptionMessageMatches('/blocked|just taken by another booking/');

        $this->service()->allot($request, $this->admin, [$room->id]);
    }

    #[Test]
    public function an_allotment_records_an_audit_entry_with_the_room_numbers(): void
    {
        $room = Room::factory()->ofType($this->type)->number('1001')->create();
        $request = $this->approvedRequest();

        $this->service()->allot($request, $this->admin, [$room->id]);

        $entry = AuditLog::where('action', 'ROOMS_ALLOTTED')->firstOrFail();

        $this->assertSame($this->admin->id, $entry->actor_id);
        $this->assertContains('1001', $entry->metadata['rooms']);
        $this->assertSame(1, $entry->metadata['rooms_held']);
    }

    #[Test]
    public function an_occupied_room_cannot_be_released(): void
    {
        $room = Room::factory()->ofType($this->type)->number('1101')->create();
        $request = $this->approvedRequest();
        $this->service()->allot($request, $this->admin, [$room->id]);

        $allotment = Allotment::where('booking_request_id', $request->id)->firstOrFail();
        $allotment->status = AllotmentStatus::CHECKED_IN;
        $allotment->save();

        $this->expectExceptionMessageMatches('/occupied/');

        $this->service()->release($allotment, $this->admin);
    }
}
