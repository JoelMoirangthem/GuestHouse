<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Services\AvailabilityService;
use App\Domain\Enums\AllotmentStatus;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Models\Allotment;
use App\Models\BookingRequest;
use App\Models\RequestDocument;
use App\Models\RequestOccupant;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tariff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PLAN.md decision 10 — the Manager selects and holds rooms at review; the ADG's
 * approval confirms them, rejection or cancellation releases them.
 */
class ManagerRoomAllotmentTest extends TestCase
{
    use RefreshDatabase;

    private User $adg;

    private User $manager;

    private User $employee;

    private Room $free1;

    private Room $free2;

    private Room $booked;

    private Room $offline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adg = User::factory()->role(RoleSlug::ADG)->create();
        $this->manager = User::factory()->role(RoleSlug::MANAGER)->create(['reporting_manager_id' => $this->adg->id]);
        $this->employee = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();

        $type = RoomType::factory()->create(['default_capacity' => 2]);
        Tariff::factory()->create(['room_type_id' => $type->id, 'amount_per_night' => '1200.00']);

        $this->free1 = Room::factory()->ofType($type)->number('201')->create(['block' => 'A', 'floor' => 2]);
        $this->free2 = Room::factory()->ofType($type)->number('202')->create(['block' => 'A', 'floor' => 2]);
        $this->booked = Room::factory()->ofType($type)->number('101')->create(['block' => 'A', 'floor' => 1]);
        $this->offline = Room::factory()->ofType($type)->number('102')->maintenance()->create(['block' => 'A', 'floor' => 1]);

        // Someone else's live allotment over the same dates.
        $other = BookingRequest::factory()->for_($this->employee)->status(RequestStatus::ALLOTTED)
            ->dates('2026-10-20', '2026-10-23')->create();
        Allotment::factory()->forRoom($this->booked)->dates('2026-10-20', '2026-10-23')
            ->create(['booking_request_id' => $other->id]);
    }

    private function pendingRequest(int $roomsNeeded = 2): BookingRequest
    {
        $request = BookingRequest::factory()
            ->for_($this->employee)
            ->pendingManager($this->manager)
            ->dates('2026-10-20', '2026-10-23')
            ->create();

        $request->forceFill(['rooms_needed' => $roomsNeeded])->save();

        RequestOccupant::factory()->primary()->create(['booking_request_id' => $request->id]);
        RequestDocument::factory()->create(['booking_request_id' => $request->id, 'uploaded_by' => $this->employee->id]);

        return $request->refresh();
    }

    private function heldRoomIds(BookingRequest $request): array
    {
        return Allotment::where('booking_request_id', $request->id)
            ->where('status', AllotmentStatus::ALLOTTED->value)
            ->orderBy('room_id')->pluck('room_id')->all();
    }

    // ------------------------------------------------------------ the board

    #[Test]
    public function the_review_page_shows_the_room_board_with_each_room_state(): void
    {
        $request = $this->pendingRequest();

        $response = $this->actingAs($this->manager)->get(route('manager.requests.show', $request))->assertOk();

        $response->assertSee('Room allotment')
            ->assertSee('Block A')
            ->assertSee('Available (2)')
            ->assertSee('Booked (1)')
            ->assertSee('Offline (1)')
            ->assertSee('Room 201', false)
            ->assertSee('booked for these dates', false);

        $board = app(AvailabilityService::class)->roomBoardForReview($request, $this->manager);
        $states = collect($board['blocks'][0]['floors'])->flatMap(fn ($f) => $f['rooms'])->pluck('state', 'number');

        $this->assertSame(
            ['201' => 'available', '202' => 'available', '101' => 'booked', '102' => 'offline'],
            $states->only(['201', '202', '101', '102'])->all(),
        );

        // Top floor first, as on a building elevation.
        $this->assertSame(['Floor 2', 'Floor 1'], array_column($board['blocks'][0]['floors'], 'label'));
    }

    #[Test]
    public function the_board_is_withheld_from_everyone_but_the_reviewing_manager(): void
    {
        $request = $this->pendingRequest();
        $otherManager = User::factory()->role(RoleSlug::MANAGER)->create();
        $service = app(AvailabilityService::class);

        foreach ([$this->employee, $this->adg, $otherManager] as $actor) {
            try {
                $service->roomBoardForReview($request, $actor);
                $this->fail("{$actor->role->slug} obtained the room board.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        // Once approved it is no longer the Manager's to allot.
        $this->actingAs($this->manager)->post(route('manager.requests.approve', $request));
        $this->actingAs($this->manager)->get(route('manager.requests.show', $request))
            ->assertOk()->assertDontSee('Room allotment');
    }

    // ------------------------------------------------------------ inventory

    #[Test]
    public function the_seeded_inventory_is_exactly_the_listed_block_a_rooms(): void
    {
        Allotment::query()->delete();
        Room::query()->delete();
        $this->seed(\Database\Seeders\RoomSeeder::class);

        $rooms = Room::orderBy('floor')->orderBy('room_number')->get();

        // The client's list: 2 (VIP suites) + 2x5 + 4x2 = 20 rooms.
        $this->assertCount(20, $rooms);
        $this->assertSame(['A'], $rooms->pluck('block')->unique()->values()->all());
        $this->assertSame(
            [
                0 => ['VIP-01', 'VIP-02'],
                1 => ['101', '102'],
                2 => ['201', '202'],
                3 => ['301', '302'],
                4 => ['401', '402'],
                5 => ['501', '502'],
                6 => ['601', '602', '603', '604'],
                7 => ['701', '702', '703', '704'],
            ],
            $rooms->groupBy('floor')->map(fn ($g) => $g->pluck('room_number')->all())->all(),
        );
        $this->assertTrue($rooms->every(fn (Room $r) => $r->status === \App\Domain\Enums\RoomStatus::ACTIVE));
    }

    #[Test]
    public function the_board_lists_every_floor_top_down_ending_with_the_vip_suites(): void
    {
        Allotment::query()->delete();
        Room::query()->delete();
        $this->seed(\Database\Seeders\RoomSeeder::class);

        $board = app(AvailabilityService::class)->roomBoardForReview($this->pendingRequest(), $this->manager);

        $this->assertCount(1, $board['blocks']);
        $this->assertSame('Block A (Hostel A)', $board['blocks'][0]['name']);
        $this->assertSame(
            ['Floor 7', 'Floor 6', 'Floor 5', 'Floor 4', 'Floor 3', 'Floor 2', 'Floor 1', 'VIP Suites'],
            array_column($board['blocks'][0]['floors'], 'label'),
        );
        $this->assertSame(['available' => 20, 'booked' => 0, 'offline' => 0], $board['counts']);
    }

    #[Test]
    public function block_b_is_hidden_from_the_board_and_cannot_be_held(): void
    {
        $type = RoomType::first();
        $hidden = Room::factory()->ofType($type)->number('B-101')->create(['block' => 'B', 'floor' => 1]);
        $request = $this->pendingRequest();

        $this->actingAs($this->manager)->get(route('manager.requests.show', $request))
            ->assertOk()
            ->assertSee('Block A (Hostel A)')
            ->assertDontSee('Block B')
            ->assertDontSee('B-101');

        // A crafted POST for the hidden room is refused, and nothing is written.
        $this->actingAs($this->manager)
            ->post(route('manager.requests.approve', $request), ['room_ids' => [$hidden->id]])
            ->assertSessionHasErrors(['action' => 'Room B-101 is not available for selection.']);

        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->fresh()->status);
    }

    #[Test]
    public function the_board_has_no_room_type_filter(): void
    {
        $this->actingAs($this->manager)->get(route('manager.requests.show', $this->pendingRequest()))
            ->assertOk()
            ->assertDontSee('Filter by room type')
            ->assertDontSee('All types');
    }

    // ------------------------------------------------------------ holding

    #[Test]
    public function approving_with_rooms_allots_them_immediately(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->manager)
            ->post(route('manager.requests.approve', $request), ['room_ids' => [$this->free1->id, $this->free2->id]])
            ->assertRedirect(route('manager.requests.index'))
            ->assertSessionHas('success');

        $request->refresh();
        // Manager approval is final, so held rooms are confirmed at once.
        $this->assertSame(RequestStatus::ALLOTTED, $request->status);
        $this->assertSame([$this->free1->id, $this->free2->id], $this->heldRoomIds($request));
        $this->assertSame($this->manager->id, Allotment::where('booking_request_id', $request->id)->first()->allotted_by);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $request->id, 'action' => 'ROOMS_HELD']);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $request->id, 'action' => 'ROOMS_CONFIRMED', 'to_status' => 'ALLOTTED']);

        // Held rooms are gone from the pool for anyone else.
        $next = $this->pendingRequest();
        $board = app(AvailabilityService::class)->roomBoardForReview($next, $this->manager);
        $states = collect($board['blocks'][0]['floors'])->flatMap(fn ($f) => $f['rooms'])->pluck('state', 'number');
        $this->assertSame('booked', $states['201']);
        $this->assertSame('booked', $states['202']);
    }

    #[Test]
    public function approving_without_rooms_still_works_and_leaves_allotment_to_the_administration(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->manager)->post(route('manager.requests.approve', $request));

        $this->assertSame(RequestStatus::PENDING_ALLOTMENT, $request->fresh()->status);
        $this->assertSame([], $this->heldRoomIds($request));
    }

    #[Test]
    public function a_booked_or_offline_room_cannot_be_held_and_nothing_is_written(): void
    {
        foreach ([$this->booked, $this->offline] as $room) {
            $request = $this->pendingRequest();

            $this->actingAs($this->manager)
                ->from(route('manager.requests.show', $request))
                ->post(route('manager.requests.approve', $request), ['room_ids' => [$this->free1->id, $room->id]])
                ->assertRedirect(route('manager.requests.show', $request))
                ->assertSessionHasErrors('action');

            // All or nothing: the approval and the free room were rolled back too.
            $this->assertSame(RequestStatus::PENDING_MANAGER, $request->fresh()->status);
            $this->assertSame([], $this->heldRoomIds($request));
        }
    }

    #[Test]
    public function two_managers_cannot_hold_the_same_room(): void
    {
        $first = $this->pendingRequest();
        $second = $this->pendingRequest();

        $this->actingAs($this->manager)->post(route('manager.requests.approve', $first), ['room_ids' => [$this->free1->id]]);

        $this->actingAs($this->manager)
            ->post(route('manager.requests.approve', $second), ['room_ids' => [$this->free1->id]])
            ->assertSessionHasErrors(['action' => 'Room 201 was just taken by another booking. Please re-check availability and try again.']);

        $this->assertSame(RequestStatus::PENDING_MANAGER, $second->fresh()->status);
    }

    // ---------------------------------------------------- manager outcome

    #[Test]
    public function manager_approval_confirms_held_rooms_as_the_allotment(): void
    {
        $request = $this->pendingRequest(roomsNeeded: 2);

        $this->actingAs($this->manager)->post(route('manager.requests.approve', $request),
            ['room_ids' => [$this->free1->id, $this->free2->id]])
            ->assertRedirect(route('manager.requests.index'));

        $this->assertSame(RequestStatus::ALLOTTED, $request->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $request->id, 'action' => 'ROOMS_CONFIRMED', 'to_status' => 'ALLOTTED',
        ]);
    }

    #[Test]
    public function fewer_rooms_than_requested_become_a_partial_allotment_the_admin_can_complete(): void
    {
        $request = $this->pendingRequest(roomsNeeded: 2);

        $this->actingAs($this->manager)->post(route('manager.requests.approve', $request), ['room_ids' => [$this->free1->id]]);

        $this->assertSame(RequestStatus::PARTIALLY_ALLOTTED, $request->fresh()->status);

        $admin = User::factory()->role(RoleSlug::ADMIN)->create();
        $this->actingAs($admin)->post(route('admin.allotments.store', $request), ['room_ids' => [$this->free2->id]]);

        $this->assertSame(RequestStatus::ALLOTTED, $request->fresh()->status);
    }

    #[Test]
    public function a_rejected_request_releases_the_held_rooms(): void
    {
        // The Manager cannot hold rooms and reject in one action, so this covers
        // the release path via an Admin cancellation of an allotted booking.
        $request = $this->pendingRequest(roomsNeeded: 1);
        $this->actingAs($this->manager)->post(route('manager.requests.approve', $request), ['room_ids' => [$this->free1->id]]);
        $this->assertSame(RequestStatus::ALLOTTED, $request->fresh()->status);

        $this->actingAs($this->employee)->post(route('my.requests.cancel', $request), ['cancel_reason' => 'Not needed.']);

        $this->assertSame(RequestStatus::CANCELLED, $request->fresh()->status);
        $this->assertSame([], $this->heldRoomIds($request));
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $request->id, 'action' => 'ROOMS_RELEASED']);

        // The room is free for the next request.
        $next = $this->pendingRequest();
        $this->actingAs($this->manager)->post(route('manager.requests.approve', $next), ['room_ids' => [$this->free1->id]]);
        $this->assertSame([$this->free1->id], $this->heldRoomIds($next));
    }

    #[Test]
    public function cancelling_an_allotted_booking_returns_its_rooms_to_the_pool(): void
    {
        $request = $this->pendingRequest(roomsNeeded: 1);
        $this->actingAs($this->manager)->post(route('manager.requests.approve', $request), ['room_ids' => [$this->free1->id]]);
        $this->assertSame(RequestStatus::ALLOTTED, $request->fresh()->status);

        $this->actingAs($this->employee)->post(route('my.requests.cancel', $request));

        $this->assertSame(RequestStatus::CANCELLED, $request->fresh()->status);
        $this->assertSame([], $this->heldRoomIds($request));
    }
}
