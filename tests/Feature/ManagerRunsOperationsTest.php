<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Models\BookingRequest;
use App\Models\RequestDocument;
use App\Models\RequestOccupant;
use App\Models\Room;
use App\Models\StayExtension;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The single Manager runs the whole booking operation with no Administrator:
 * approve with rooms, finish the allotment, check in, decide an extension and
 * check out.
 */
class ManagerRunsOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['gh.notifications_enabled' => false]);

        $this->manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $this->employee = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();
    }

    #[Test]
    public function the_manager_takes_a_booking_from_approval_to_check_out_without_an_admin(): void
    {
        [$room1, $room2, $room3] = Room::factory()->count(3)->create()->all();

        // Arrives today for two nights and needs two rooms.
        $request = BookingRequest::factory()->for_($this->employee)->pendingManager($this->manager)
            ->dates(today()->format('Y-m-d'), today()->addDays(2)->format('Y-m-d'))
            ->create(['rooms_needed' => 2, 'total_members' => 3]);
        RequestOccupant::factory()->primary()->create(['booking_request_id' => $request->id]);
        RequestDocument::factory()->create(['booking_request_id' => $request->id, 'uploaded_by' => $this->employee->id]);

        // 1. Approve with one room: partially allotted.
        $this->actingAs($this->manager)
            ->post(route('manager.requests.approve', $request), ['room_ids' => [$room1->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::PARTIALLY_ALLOTTED, $request->fresh()->status);

        // 2. The Manager finishes the allotment from the Allotment Queue.
        $this->actingAs($this->manager)->get(route('admin.allotments.index'))
            ->assertOk()->assertSee($request->request_no);
        $this->actingAs($this->manager)
            ->post(route('admin.allotments.store', $request), ['room_ids' => [$room2->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::ALLOTTED, $request->fresh()->status);

        // 3. Check in at the Front Desk.
        $this->actingAs($this->manager)->get(route('admin.stays.index'))->assertOk()->assertSee($request->request_no);
        $this->actingAs($this->manager)->post(route('admin.stays.checkIn', $request))->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::CHECKED_IN, $request->fresh()->status);

        // 4. The guest asks for one more night; the Manager approves it.
        $this->actingAs($this->employee)
            ->post(route('my.requests.extension', $request), [
                'requested_check_out_date' => today()->addDays(3)->format('Y-m-d'),
                'reason' => 'Programme extended by a day',
            ])->assertSessionHasNoErrors();
        $extension = StayExtension::where('booking_request_id', $request->id)->firstOrFail();

        $this->actingAs($this->manager)->post(route('admin.extensions.approve', $extension))->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::CHECKED_IN, $request->fresh()->status);
        $this->assertSame(today()->addDays(3)->format('Y-m-d'), $request->fresh()->check_out_date->format('Y-m-d'));

        // 5. Check out: rooms go back to the pool.
        $this->actingAs($this->manager)->post(route('admin.stays.checkOut', $request))->assertSessionHasNoErrors();
        $this->assertContains($request->fresh()->status, [RequestStatus::CHECKED_OUT, RequestStatus::EARLY_CHECKOUT]);
        $this->assertSame(0, $request->allotments()->occupying()->count());

        // The third room was never touched.
        $this->assertSame(0, $room3->allotments()->count());
    }

    #[Test]
    public function the_managers_own_reservation_is_allotted_by_the_manager(): void
    {
        $room = Room::factory()->create();

        $reservation = BookingRequest::factory()->for_($this->manager)->status(RequestStatus::PENDING_ALLOTMENT)
            ->create(['manager_id' => $this->manager->id, 'manager_acted_at' => now()]);
        RequestOccupant::factory()->primary()->create(['booking_request_id' => $reservation->id, 'name' => 'Visiting Dignitary']);

        $this->actingAs($this->manager)->get(route('admin.allotments.index'))
            ->assertOk()->assertSee('Visiting Dignitary');

        $this->actingAs($this->manager)
            ->post(route('admin.allotments.store', $reservation), ['room_ids' => [$room->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::ALLOTTED, $reservation->fresh()->status);
    }

    #[Test]
    public function the_manager_sees_the_operations_menu_but_not_system_setup(): void
    {
        $this->actingAs($this->manager)->get(route('manager.requests.index'))
            ->assertOk()
            ->assertSee('Pending Review')
            ->assertSee('Allotment Queue')
            ->assertSee('Front Desk')
            ->assertDontSee('Room Inventory')
            ->assertDontSee(route('admin.users.index'), false)
            ->assertDontSee(route('admin.settings.edit'), false);

        $this->actingAs($this->manager)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($this->manager)->get(route('admin.settings.edit'))->assertForbidden();
    }

    #[Test]
    public function an_employee_and_the_adg_still_cannot_run_operations(): void
    {
        $adg = User::factory()->role(RoleSlug::ADG)->create();

        foreach ([$this->employee, $adg] as $actor) {
            $this->actingAs($actor)->get(route('admin.allotments.index'))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.stays.index'))->assertForbidden();
        }
    }
}
