<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Services\AllotmentService;
use App\Application\Services\StayService;
use App\Domain\Contracts\AvailabilityQueryInterface;
use App\Domain\Enums\AllotmentStatus;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Models\Allotment;
use App\Models\BookingRequest;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tariff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TESTING.md — ExtensionTest and CapacityTest. The Phase 6 gate.
 *
 * The extension path is the risky one: granting extra nights on a room somebody
 * else already holds is the easiest way to double-book a guest house.
 */
class StayLifecycleTest extends TestCase
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
            'amount_per_night' => '1000.00',
        ]);
    }

    private function stays(): StayService
    {
        return app(StayService::class);
    }

    /**
     * A request already allotted and due to arrive today.
     */
    private function allottedStay(int $members = 1, int $roomCount = 1): array
    {
        $in = today()->format('Y-m-d');
        $out = today()->addDays(3)->format('Y-m-d');

        $request = BookingRequest::factory()
            ->for_($this->employee)
            ->status(RequestStatus::PENDING_ALLOTMENT)
            ->members($members)
            ->dates($in, $out)
            ->create();

        $rooms = collect(range(1, $roomCount))
            ->map(fn ($i) => Room::factory()->ofType($this->type)->number('10'.$i)->create());

        $request = app(AllotmentService::class)
            ->allot($request, $this->admin, $rooms->pluck('id')->all());

        return [$request, $rooms];
    }

    // ------------------------------------------------------------- check-in

    #[Test]
    public function a_fully_allotted_request_can_check_in(): void
    {
        [$request] = $this->allottedStay();

        $request = $this->stays()->checkIn($request, $this->admin);

        $this->assertSame(RequestStatus::CHECKED_IN, $request->status);

        $allotment = Allotment::where('booking_request_id', $request->id)->firstOrFail();
        $this->assertSame(AllotmentStatus::CHECKED_IN, $allotment->status);
        $this->assertNotNull($allotment->actual_check_in_at);
        $this->assertSame($this->admin->id, $allotment->checked_in_by);
    }

    #[Test]
    public function a_partially_allotted_request_cannot_check_in(): void
    {
        // Decision 9, confirmed by the client. A party holding only some of its
        // rooms must be completed first, so nobody is left without a bed.
        $request = BookingRequest::factory()
            ->for_($this->employee)
            ->status(RequestStatus::PENDING_ALLOTMENT)
            ->members(6)                               // needs 3 rooms
            ->dates(today()->format('Y-m-d'), today()->addDays(3)->format('Y-m-d'))
            ->create();

        $rooms = collect(['201', '202'])
            ->map(fn ($n) => Room::factory()->ofType($this->type)->number($n)->create());

        $request = app(AllotmentService::class)->allot($request, $this->admin, $rooms->pluck('id')->all());
        $this->assertSame(RequestStatus::PARTIALLY_ALLOTTED, $request->status);

        $this->expectExceptionMessageMatches('/only partly allotted/');

        $this->stays()->checkIn($request, $this->admin);
    }

    #[Test]
    public function check_in_is_refused_before_the_arrival_date(): void
    {
        $request = BookingRequest::factory()
            ->for_($this->employee)
            ->status(RequestStatus::PENDING_ALLOTMENT)
            ->dates(today()->addDays(5)->format('Y-m-d'), today()->addDays(8)->format('Y-m-d'))
            ->create();

        $room = Room::factory()->ofType($this->type)->number('301')->create();
        $request = app(AllotmentService::class)->allot($request, $this->admin, [$room->id]);

        $this->expectExceptionMessageMatches('/Check-in opens on/');

        $this->stays()->checkIn($request, $this->admin);
    }

    #[Test]
    public function only_the_manager_or_an_administrator_may_check_a_guest_in(): void
    {
        [$request] = $this->allottedStay();

        foreach ([RoleSlug::USER, RoleSlug::ADG] as $slug) {
            $actor = User::factory()->role($slug)->create();

            try {
                $this->stays()->checkIn($request, $actor);
                $this->fail("Role {$slug->value} checked a guest in.");
            } catch (\Throwable $e) {
                $this->assertStringContainsString('Only the Manager or the Administration', $e->getMessage());
            }
        }

        // The Manager runs the front desk.
        $manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $this->assertSame(RequestStatus::CHECKED_IN, $this->stays()->checkIn($request, $manager)->status);
    }

    #[Test]
    public function every_allotted_room_is_occupied_by_a_single_check_in(): void
    {
        [$request, $rooms] = $this->allottedStay(members: 4, roomCount: 2);

        $this->stays()->checkIn($request, $this->admin);

        $this->assertSame(2, Allotment::where('booking_request_id', $request->id)
            ->where('status', AllotmentStatus::CHECKED_IN->value)->count());

        unset($rooms);
    }

    // ------------------------------------------------------------- capacity

    #[Test]
    public function a_party_larger_than_the_allotted_capacity_cannot_check_in(): void
    {
        // Fire safety as much as comfort: four people cannot be housed in one
        // two-person room.
        $request = BookingRequest::factory()
            ->for_($this->employee)
            ->status(RequestStatus::PENDING_ALLOTMENT)
            ->dates(today()->format('Y-m-d'), today()->addDays(2)->format('Y-m-d'))
            ->create(['total_members' => 4, 'rooms_needed' => 1]);

        $room = Room::factory()->ofType($this->type)->number('401')->capacity(2)->create();
        $request = app(AllotmentService::class)->allot($request, $this->admin, [$room->id]);

        $this->assertSame(RequestStatus::ALLOTTED, $request->status);

        $this->expectExceptionMessageMatches('/hold 2 people in total, but the request is for 4/');

        $this->stays()->checkIn($request, $this->admin);
    }

    #[Test]
    public function an_occupant_count_above_the_room_capacity_is_refused(): void
    {
        [$request] = $this->allottedStay();

        $allotment = Allotment::where('booking_request_id', $request->id)->firstOrFail();
        $allotment->occupants_count = 5;       // room holds 2
        $allotment->save();

        $this->expectExceptionMessageMatches('/holds 2, but 5 occupants/');

        $this->stays()->checkIn($request, $this->admin);
    }

    // ------------------------------------------------------------ check-out

    #[Test]
    public function a_normal_check_out_completes_the_stay_and_frees_the_room(): void
    {
        [$request, $rooms] = $this->allottedStay();
        $request = $this->stays()->checkIn($request, $this->admin);

        // Travel to the scheduled checkout day.
        $this->travelTo(today()->addDays(3));

        $request = $this->stays()->checkOut($request, $this->admin);

        $this->assertSame(RequestStatus::CHECKED_OUT, $request->status);
        $this->assertTrue($request->status->isTerminal());

        $allotment = Allotment::where('booking_request_id', $request->id)->firstOrFail();
        $this->assertSame(AllotmentStatus::CHECKED_OUT, $allotment->status);

        // The room is available again.
        $this->assertTrue(
            app(AvailabilityQueryInterface::class)
                ->isStillFree($rooms[0]->id, today()->format('Y-m-d'), today()->addDays(2)->format('Y-m-d'))
        );
    }

    #[Test]
    public function leaving_early_is_recorded_as_such_and_recharges_for_nights_stayed(): void
    {
        [$request] = $this->allottedStay();        // 3 nights at 1000 = 3000
        $request = $this->stays()->checkIn($request, $this->admin);

        $allotment = Allotment::where('booking_request_id', $request->id)->firstOrFail();
        $this->assertSame('3000.00', $allotment->total_amount);

        // Leave after one night instead of three.
        $this->travelTo(today()->addDay());
        $request = $this->stays()->checkOut($request, $this->admin);

        $this->assertSame(RequestStatus::EARLY_CHECKOUT, $request->status);

        $allotment->refresh();
        $this->assertSame(AllotmentStatus::EARLY_CHECKOUT, $allotment->status);
        $this->assertSame('1000.00', $allotment->total_amount, 'The charge was not recomputed on nights stayed.');
    }

    // ------------------------------------------------------------ extensions

    #[Test]
    public function an_extension_is_approved_when_the_extra_nights_are_free(): void
    {
        [$request] = $this->allottedStay();
        $request = $this->stays()->checkIn($request, $this->admin);

        $newDate = today()->addDays(5)->format('Y-m-d');

        $extension = $this->stays()->requestExtension($request, $this->employee, $newDate, 'Programme extended.');

        $this->assertSame(RequestStatus::EXTENSION_REQUESTED, $request->fresh()->status);
        $this->assertSame(2, $extension->additionalNights());

        $extension = $this->stays()->approveExtension($extension, $this->admin);

        $this->assertSame('APPROVED', $extension->status);
        $this->assertSame(RequestStatus::CHECKED_IN, $request->fresh()->status);

        // Dates and money both moved.
        $this->assertSame($newDate, $request->fresh()->check_out_date->format('Y-m-d'));

        $allotment = Allotment::where('booking_request_id', $request->id)->firstOrFail();
        $this->assertSame($newDate, $allotment->check_out_date->format('Y-m-d'));
        $this->assertSame('5000.00', $allotment->total_amount);   // 5 nights at 1000
    }

    #[Test]
    public function an_extension_is_refused_when_the_same_room_is_taken_for_the_extra_nights(): void
    {
        // THE TEST THAT MATTERS. Extending onto a room somebody else already holds
        // would double-book it.
        [$request, $rooms] = $this->allottedStay();
        $request = $this->stays()->checkIn($request, $this->admin);

        // Another party already holds this room from the current checkout onward.
        $otherRequest = BookingRequest::factory()
            ->for_($this->employee)
            ->status(RequestStatus::ALLOTTED)
            ->create();

        Allotment::factory()
            ->forRoom($rooms[0])
            ->dates(today()->addDays(3)->format('Y-m-d'), today()->addDays(6)->format('Y-m-d'))
            ->create(['booking_request_id' => $otherRequest->id]);

        $extension = $this->stays()->requestExtension(
            $request,
            $this->employee,
            today()->addDays(5)->format('Y-m-d'),
        );

        try {
            $this->stays()->approveExtension($extension, $this->admin);
            $this->fail('An extension was granted onto a room already booked by someone else.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already booked', $e->getMessage());
        }

        // Nothing moved.
        $this->assertSame('REQUESTED', $extension->fresh()->status);
        $this->assertSame(
            today()->addDays(3)->format('Y-m-d'),
            $request->fresh()->check_out_date->format('Y-m-d'),
        );
    }

    #[Test]
    public function a_denied_extension_leaves_the_original_dates_intact(): void
    {
        [$request] = $this->allottedStay();
        $request = $this->stays()->checkIn($request, $this->admin);

        $original = $request->check_out_date->format('Y-m-d');

        $extension = $this->stays()->requestExtension(
            $request, $this->employee, today()->addDays(6)->format('Y-m-d')
        );

        $extension = $this->stays()->denyExtension($extension, $this->admin, 'Room committed to a scheduled programme.');

        $this->assertSame('DENIED', $extension->status);
        $this->assertSame(RequestStatus::CHECKED_IN, $request->fresh()->status);
        $this->assertSame($original, $request->fresh()->check_out_date->format('Y-m-d'));
    }

    #[Test]
    public function denying_an_extension_requires_a_reason(): void
    {
        [$request] = $this->allottedStay();
        $request = $this->stays()->checkIn($request, $this->admin);

        $extension = $this->stays()->requestExtension(
            $request, $this->employee, today()->addDays(6)->format('Y-m-d')
        );

        $this->expectExceptionMessageMatches('/requires written remarks/');

        $this->stays()->denyExtension($extension, $this->admin, '  ');
    }

    #[Test]
    public function an_extension_cannot_be_decided_twice(): void
    {
        [$request] = $this->allottedStay();
        $request = $this->stays()->checkIn($request, $this->admin);

        $extension = $this->stays()->requestExtension(
            $request, $this->employee, today()->addDays(5)->format('Y-m-d')
        );

        $this->stays()->approveExtension($extension, $this->admin);

        $this->expectExceptionMessageMatches('/already been decided/');

        $this->stays()->denyExtension($extension->fresh(), $this->admin, 'Changed my mind.');
    }

    #[Test]
    public function only_one_extension_request_may_be_pending_at_a_time(): void
    {
        [$request] = $this->allottedStay();
        $request = $this->stays()->checkIn($request, $this->admin);

        $this->stays()->requestExtension($request, $this->employee, today()->addDays(5)->format('Y-m-d'));

        $this->expectExceptionMessageMatches('/already awaiting a decision/');

        $this->stays()->requestExtension($request->fresh(), $this->employee, today()->addDays(6)->format('Y-m-d'));
    }

    #[Test]
    public function an_extension_must_move_the_date_forward(): void
    {
        [$request] = $this->allottedStay();
        $request = $this->stays()->checkIn($request, $this->admin);

        $this->expectExceptionMessageMatches('/must be later than the current one/');

        $this->stays()->requestExtension($request, $this->employee, today()->addDay()->format('Y-m-d'));
    }

    #[Test]
    public function an_extension_cannot_be_requested_when_no_stay_is_active(): void
    {
        [$request] = $this->allottedStay();   // allotted but not checked in

        $this->expectExceptionMessageMatches('/no active stay|not permitted/');

        $this->stays()->requestExtension($request, $this->employee, today()->addDays(5)->format('Y-m-d'));
    }

    // ------------------------------------------------------------ QR check-in

    #[Test]
    public function a_qr_token_resolves_to_its_allotment(): void
    {
        [$request] = $this->allottedStay();
        $allotment = Allotment::where('booking_request_id', $request->id)->firstOrFail();

        $found = $this->stays()->findByQrToken($allotment->qr_token);

        $this->assertNotNull($found);
        $this->assertSame($allotment->id, $found->id);
    }

    #[Test]
    public function an_unknown_qr_token_resolves_to_nothing(): void
    {
        $this->assertNull($this->stays()->findByQrToken(str_repeat('x', 40)));
    }

    // ------------------------------------------------------------ audit trail

    #[Test]
    public function the_stay_lifecycle_is_fully_audited(): void
    {
        [$request] = $this->allottedStay();
        $request = $this->stays()->checkIn($request, $this->admin);

        $extension = $this->stays()->requestExtension(
            $request, $this->employee, today()->addDays(5)->format('Y-m-d')
        );
        $this->stays()->approveExtension($extension, $this->admin);

        $this->travelTo(today()->addDays(5));
        $this->stays()->checkOut($request->fresh(), $this->admin);

        foreach (['CHECKED_IN', 'EXTENSION_REQUESTED', 'EXTENSION_APPROVED', 'CHECKED_OUT'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'auditable_id' => $request->id,
                'action' => $action,
            ]);
        }
    }
}
