<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Services\AvailabilityService;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Domain\Enums\RoomStatus;
use App\Domain\Enums\VisitPurpose;
use App\Models\Allotment;
use App\Models\AuditLog;
use App\Models\BookingRequest;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tariff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Admin masters — users, rooms (with block / unblock), room types, tariffs.
 * ROUTES.md "Masters", SECURITY.md sections 3–5, REPORTS.md section 3.
 */
class AdminMastersTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG = 'Sturdy#Pass2026';

    private User $admin;

    private User $manager;

    private RoomType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // Frozen so the date arithmetic below cannot drift as the calendar moves.
        Carbon::setTestNow('2026-10-01 10:00:00');

        $this->admin = User::factory()->role(RoleSlug::ADMIN)->create();
        $this->manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $this->type = RoomType::factory()->create(['code' => 'STD_AC', 'default_capacity' => 2]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function roleId(RoleSlug $slug): int
    {
        return Role::where('slug', $slug->value)->value('id');
    }

    /** @return array<string, mixed> */
    private function userPayload(array $overrides = []): array
    {
        return [
            'name' => 'Asha Verma',
            'email' => 'asha.verma@example.gov.in',
            'employee_code' => 'EMP-0042',
            'mobile' => '9876543210',
            'designation' => 'Assistant Commissioner',
            'department' => 'Training',
            'role_id' => $this->roleId(RoleSlug::USER),
            'reporting_manager_id' => $this->manager->id,
            'is_active' => '1',
            'password' => self::STRONG,
            'password_confirmation' => self::STRONG,
            ...$overrides,
        ];
    }

    /** @param  array<string, mixed>  $state  applied through the factory, since workflow columns are not fillable */
    private function allotRoom(Room $room, string $in, string $out, array $state = []): Allotment
    {
        $employee = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();
        $request = BookingRequest::factory()->for_($employee)->status(RequestStatus::ALLOTTED)->dates($in, $out)->create();

        return Allotment::factory()->forRoom($room)->dates($in, $out)->create(['booking_request_id' => $request->id, ...$state]);
    }

    // ============================================================== access

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function masterRoutes(): array
    {
        return [
            'users list' => ['GET', 'admin.users.index', false],
            'user create form' => ['GET', 'admin.users.create', false],
            'user store' => ['POST', 'admin.users.store', false],
            'rooms list' => ['GET', 'admin.rooms.index', false],
            'room store' => ['POST', 'admin.rooms.store', false],
            'room block' => ['POST', 'admin.rooms.block', true],
            'room unblock' => ['POST', 'admin.rooms.unblock', true],
            'room types list' => ['GET', 'admin.room-types.index', false],
            'tariffs list' => ['GET', 'admin.tariffs.index', false],
            'tariff store' => ['POST', 'admin.tariffs.store', false],
        ];
    }

    #[Test]
    #[DataProvider('masterRoutes')]
    public function no_role_other_than_admin_can_reach_a_master_route(string $method, string $name, bool $needsRoom): void
    {
        $room = Room::factory()->ofType($this->type)->create();
        $url = $needsRoom ? route($name, $room) : route($name);

        foreach ([RoleSlug::USER, RoleSlug::MANAGER, RoleSlug::ADG] as $slug) {
            $this->actingAs(User::factory()->role($slug)->create())
                ->call($method, $url, ['_token' => csrf_token()])
                ->assertForbidden();
        }

        // Nothing was written by any of the refused attempts.
        $this->assertSame(RoomStatus::ACTIVE, $room->fresh()->status);
        $this->assertSame(0, AuditLog::count());
    }

    #[Test]
    public function every_master_screen_renders_for_the_admin(): void
    {
        $room = Room::factory()->ofType($this->type)->create();
        Tariff::factory()->create(['room_type_id' => $this->type->id]);
        $this->actingAs($this->admin);

        foreach ([
            route('admin.users.index'), route('admin.users.create'), route('admin.users.edit', $this->manager),
            route('admin.rooms.index'), route('admin.rooms.create'), route('admin.rooms.edit', $room),
            route('admin.rooms.index', ['state' => 'blocked', 'type' => $this->type->id]),
            route('admin.room-types.index'), route('admin.room-types.create'), route('admin.room-types.edit', $this->type),
            route('admin.tariffs.index'),
            route('admin.users.index', ['q' => 'x', 'role' => 'manager']),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        // The navigation links to the new screens.
        $this->get(route('admin.users.index'))
            ->assertSee(route('admin.rooms.index'), false)
            ->assertSee(route('admin.tariffs.index'), false);
    }

    // =============================================================== users

    #[Test]
    public function the_admin_can_provision_an_employee_who_can_then_sign_in(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), $this->userPayload())
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors();

        $user = User::where('email', 'asha.verma@example.gov.in')->firstOrFail();
        $this->assertTrue($user->isUser());
        $this->assertSame($this->manager->id, $user->reporting_manager_id);
        $this->assertTrue(Hash::check(self::STRONG, $user->password));
        $this->assertTrue(AuditLog::where('action', 'USER_CREATED')->where('auditable_id', $user->id)->exists());

        auth()->logout();
        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => self::STRONG])
            ->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function an_employee_without_a_reporting_manager_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), $this->userPayload(['reporting_manager_id' => '']))
            ->assertSessionHasErrors('user');

        $this->assertDatabaseMissing('users', ['email' => 'asha.verma@example.gov.in']);
    }

    #[Test]
    public function the_reporting_manager_must_hold_the_manager_role(): void
    {
        $adg = User::factory()->role(RoleSlug::ADG)->create();

        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), $this->userPayload(['reporting_manager_id' => $adg->id]))
            ->assertSessionHasErrors('user');

        $this->assertDatabaseMissing('users', ['email' => 'asha.verma@example.gov.in']);
    }

    #[Test]
    public function a_weak_password_is_refused(): void
    {
        foreach (['short1!A', 'alllowercase123!', 'NoDigitsHere!!', 'NoSymbols12345'] as $weak) {
            $this->actingAs($this->admin)
                ->post(route('admin.users.store'), $this->userPayload(['password' => $weak, 'password_confirmation' => $weak]))
                ->assertSessionHasErrors('password');
        }

        $this->assertDatabaseMissing('users', ['email' => 'asha.verma@example.gov.in']);
    }

    #[Test]
    public function email_and_employee_code_must_be_unique(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), $this->userPayload(['email' => $this->manager->email, 'employee_code' => $this->manager->employee_code]))
            ->assertSessionHasErrors(['email', 'employee_code']);
    }

    #[Test]
    public function a_role_cannot_be_escalated_through_an_extra_form_field(): void
    {
        // role_id is not mass-assignable; only the explicit, validated role_id
        // chosen on the form applies. A crafted is_admin / role field is ignored.
        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), $this->userPayload(['role' => 'admin', 'is_admin' => 1]))
            ->assertSessionHasNoErrors();

        $this->assertTrue(User::where('email', 'asha.verma@example.gov.in')->firstOrFail()->isUser());
    }

    #[Test]
    public function a_role_change_is_audited_and_ends_the_users_sessions(): void
    {
        $employee = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();
        DB::table('sessions')->insert(['id' => 'live-session', 'user_id' => $employee->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $employee), $this->userPayload([
                'email' => $employee->email,
                'employee_code' => $employee->employee_code,
                'role_id' => $this->roleId(RoleSlug::MANAGER),
                'reporting_manager_id' => '',
                'password' => '', 'password_confirmation' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($employee->fresh()->isManager());
        $this->assertDatabaseMissing('sessions', ['id' => 'live-session']);

        $log = AuditLog::where('action', 'ROLE_CHANGED')->where('auditable_id', $employee->id)->firstOrFail();
        // assertEquals: MySQL's JSON type does not preserve key order.
        $this->assertEquals(['from' => 'user', 'to' => 'manager'], $log->metadata);
        $this->assertSame($this->admin->id, $log->actor_id);
    }

    #[Test]
    public function an_admin_cannot_demote_or_deactivate_themselves(): void
    {
        $base = [
            'name' => $this->admin->name, 'email' => $this->admin->email,
            'employee_code' => $this->admin->employee_code, 'reporting_manager_id' => '',
            'password' => '', 'password_confirmation' => '',
        ];

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $this->admin), [...$base, 'role_id' => $this->roleId(RoleSlug::USER), 'is_active' => '1', 'reporting_manager_id' => $this->manager->id])
            ->assertSessionHasErrors('user');

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $this->admin), [...$base, 'role_id' => $this->roleId(RoleSlug::ADMIN), 'is_active' => '0'])
            ->assertSessionHasErrors('user');

        $fresh = $this->admin->fresh();
        $this->assertTrue($fresh->isAdmin());
        $this->assertTrue($fresh->is_active);
    }

    #[Test]
    public function a_manager_with_requests_awaiting_review_cannot_be_deactivated(): void
    {
        $employee = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();
        BookingRequest::factory()->for_($employee)->pendingManager($this->manager)->create();

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $this->manager), [
                'name' => $this->manager->name, 'email' => $this->manager->email,
                'employee_code' => $this->manager->employee_code,
                'role_id' => $this->roleId(RoleSlug::MANAGER), 'is_active' => '0',
            ])
            ->assertSessionHasErrors('user');

        $this->assertTrue($this->manager->fresh()->is_active);
    }

    #[Test]
    public function deactivation_signs_the_user_out_and_blocks_future_sign_in(): void
    {
        $employee = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();
        DB::table('sessions')->insert(['id' => 'live-session', 'user_id' => $employee->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $employee), [
                'name' => $employee->name, 'email' => $employee->email, 'employee_code' => $employee->employee_code,
                'role_id' => $this->roleId(RoleSlug::USER), 'reporting_manager_id' => $this->manager->id,
                'is_active' => '0',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($employee->fresh()->is_active);
        $this->assertDatabaseMissing('sessions', ['id' => 'live-session']);
        $this->assertTrue(AuditLog::where('action', 'USER_DEACTIVATED')->where('auditable_id', $employee->id)->exists());

        auth()->logout();
        $this->post(route('login.attempt'), ['email' => $employee->email, 'password' => 'Password@123']);
        $this->assertGuest();
    }

    #[Test]
    public function an_admin_password_reset_is_audited_without_recording_the_password(): void
    {
        $employee = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $employee), [
                'name' => $employee->name, 'email' => $employee->email, 'employee_code' => $employee->employee_code,
                'role_id' => $this->roleId(RoleSlug::USER), 'reporting_manager_id' => $this->manager->id,
                'is_active' => '1', 'password' => self::STRONG, 'password_confirmation' => self::STRONG,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check(self::STRONG, $employee->fresh()->password));

        $log = AuditLog::where('action', 'USER_UPDATED')->where('auditable_id', $employee->id)->firstOrFail();
        $this->assertTrue($log->metadata['password_reset']);
        $this->assertStringNotContainsString(self::STRONG, json_encode($log->getAttributes()));
    }

    // =============================================================== rooms

    #[Test]
    public function a_new_room_is_immediately_counted_in_the_inventory(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.rooms.store'), ['room_number' => '301', 'room_type_id' => $this->type->id, 'floor' => 3])
            ->assertRedirect(route('admin.rooms.index'))
            ->assertSessionHasNoErrors();

        $summary = app(AvailabilityService::class)->summary('2026-10-10', '2026-10-12', $this->admin);
        $row = collect($summary)->firstWhere('name', $this->type->name);
        $this->assertSame(1, (int) $row['total']);
        $this->assertSame(1, (int) $row['available']);
        $this->assertTrue(AuditLog::where('action', 'ROOM_CREATED')->exists());
    }

    #[Test]
    public function room_numbers_are_unique(): void
    {
        Room::factory()->ofType($this->type)->number('301')->create();

        $this->actingAs($this->admin)
            ->post(route('admin.rooms.store'), ['room_number' => '301', 'room_type_id' => $this->type->id])
            ->assertSessionHasErrors('room_number');
    }

    #[Test]
    public function a_maintenance_block_removes_the_room_from_availability_until_unblocked(): void
    {
        $room = Room::factory()->ofType($this->type)->number('302')->create();
        $summary = fn () => collect(app(AvailabilityService::class)->summary('2026-10-10', '2026-10-12', $this->admin))
            ->firstWhere('name', $this->type->name);

        $this->actingAs($this->admin)
            ->post(route('admin.rooms.block', $room), ['mode' => 'MAINTENANCE', 'block_reason' => 'AC compressor replacement'])
            ->assertSessionHasNoErrors();

        $this->assertSame(RoomStatus::MAINTENANCE, $room->fresh()->status);
        $this->assertSame(0, (int) $summary()['available']);
        $this->assertSame(1, (int) $summary()['blocked']);

        $this->post(route('admin.rooms.unblock', $room))->assertSessionHasNoErrors();

        $fresh = $room->fresh();
        $this->assertSame(RoomStatus::ACTIVE, $fresh->status);
        $this->assertNull($fresh->block_reason);
        $this->assertSame(1, (int) $summary()['available']);

        $this->assertSame(['ROOM_BLOCKED', 'ROOM_UNBLOCKED'],
            AuditLog::where('auditable_id', $room->id)->where('auditable_type', $room->getMorphClass())->orderBy('id')->pluck('action')->all());
    }

    #[Test]
    public function a_room_cannot_be_blocked_while_it_is_allotted_in_that_period(): void
    {
        $room = Room::factory()->ofType($this->type)->number('303')->create();
        $allotment = $this->allotRoom($room, '2026-10-20', '2026-10-23');

        // Indefinite block: covers every future allotment.
        $this->actingAs($this->admin)
            ->post(route('admin.rooms.block', $room), ['mode' => 'BLOCKED', 'block_reason' => 'Renovation'])
            ->assertSessionHasErrors('room');

        // Date block overlapping the stay.
        $this->post(route('admin.rooms.block', $room), [
            'mode' => 'RANGE', 'blocked_from' => '2026-10-22', 'blocked_to' => '2026-10-25', 'block_reason' => 'Painting',
        ])->assertSessionHasErrors('room');

        $this->assertStringContainsString($allotment->allotment_no, session('errors')->first('room'));
        $this->assertSame(RoomStatus::ACTIVE, $room->fresh()->status);
        $this->assertNull($room->fresh()->blocked_from);
    }

    #[Test]
    public function block_boundaries_follow_the_half_open_rule(): void
    {
        $room = Room::factory()->ofType($this->type)->number('304')->create();
        $this->allotRoom($room, '2026-10-20', '2026-10-23');   // nights of 20, 21, 22

        $this->actingAs($this->admin);

        // Ends (inclusive) the day before arrival: no overlap.
        $this->post(route('admin.rooms.block', $room), [
            'mode' => 'RANGE', 'blocked_from' => '2026-10-15', 'blocked_to' => '2026-10-19', 'block_reason' => 'Deep clean',
        ])->assertSessionHasNoErrors();
        $this->post(route('admin.rooms.unblock', $room))->assertSessionHasNoErrors();

        // Starts on the checkout day: the room is free that night.
        $this->post(route('admin.rooms.block', $room), [
            'mode' => 'RANGE', 'blocked_from' => '2026-10-23', 'blocked_to' => null, 'block_reason' => 'Deep clean',
        ])->assertSessionHasNoErrors();
        $this->post(route('admin.rooms.unblock', $room))->assertSessionHasNoErrors();

        // Covers the arrival day: overlaps.
        $this->post(route('admin.rooms.block', $room), [
            'mode' => 'RANGE', 'blocked_from' => '2026-10-19', 'blocked_to' => '2026-10-20', 'block_reason' => 'Deep clean',
        ])->assertSessionHasErrors('room');
    }

    #[Test]
    public function a_cancelled_or_completed_allotment_does_not_prevent_a_block(): void
    {
        $room = Room::factory()->ofType($this->type)->number('305')->create();
        $this->allotRoom($room, '2026-10-20', '2026-10-23', ['status' => 'CANCELLED']);
        $this->allotRoom($room, '2026-10-25', '2026-10-27', ['status' => 'CHECKED_OUT']);

        $this->actingAs($this->admin)
            ->post(route('admin.rooms.block', $room), ['mode' => 'BLOCKED', 'block_reason' => 'Renovation'])
            ->assertSessionHasNoErrors();

        $this->assertSame(RoomStatus::BLOCKED, $room->fresh()->status);
    }

    #[Test]
    public function a_date_block_needs_a_start_and_a_reason_and_must_not_end_before_it_starts(): void
    {
        $room = Room::factory()->ofType($this->type)->number('306')->create();
        $this->actingAs($this->admin);

        $this->post(route('admin.rooms.block', $room), ['mode' => 'RANGE', 'block_reason' => 'x-ray'])
            ->assertSessionHasErrors('blocked_from');
        $this->post(route('admin.rooms.block', $room), ['mode' => 'MAINTENANCE'])
            ->assertSessionHasErrors('block_reason');
        $this->post(route('admin.rooms.block', $room), [
            'mode' => 'RANGE', 'blocked_from' => '2026-10-10', 'blocked_to' => '2026-10-05', 'block_reason' => 'Painting',
        ])->assertSessionHasErrors('blocked_to');

        $this->assertSame(0, AuditLog::count());
    }

    #[Test]
    public function unblocking_a_room_that_is_not_blocked_is_refused(): void
    {
        $room = Room::factory()->ofType($this->type)->number('307')->create();

        $this->actingAs($this->admin)
            ->post(route('admin.rooms.unblock', $room))
            ->assertSessionHasErrors('room');
    }

    #[Test]
    public function a_rooms_type_cannot_change_while_it_holds_an_allotment(): void
    {
        $room = Room::factory()->ofType($this->type)->number('308')->create();
        $this->allotRoom($room, '2026-10-20', '2026-10-23');
        $other = RoomType::factory()->vip()->create(['code' => 'DLX_AC']);

        $this->actingAs($this->admin)
            ->put(route('admin.rooms.update', $room), ['room_number' => '308', 'room_type_id' => $other->id])
            ->assertSessionHasErrors('room');

        $this->assertSame($this->type->id, $room->fresh()->room_type_id);

        // Other edits remain possible.
        $this->put(route('admin.rooms.update', $room), ['room_number' => '308', 'room_type_id' => $this->type->id, 'notes' => 'Corner room'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Corner room', $room->fresh()->notes);
    }

    // ========================================================== room types

    #[Test]
    public function a_room_type_can_be_added_and_its_code_is_normalised(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.room-types.store'), [
                'code' => 'family_suite', 'name' => 'Family Suite', 'category' => 'NORMAL',
                'default_capacity' => 4, 'has_ac' => '1', 'is_active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $type = RoomType::where('code', 'FAMILY_SUITE')->firstOrFail();
        $this->assertSame(4, $type->default_capacity);
        $this->assertTrue(AuditLog::where('action', 'ROOM_TYPE_CREATED')->exists());

        $this->post(route('admin.room-types.store'), [
            'code' => 'FAMILY_SUITE', 'name' => 'Duplicate', 'category' => 'NORMAL', 'default_capacity' => 2,
        ])->assertSessionHasErrors('code');
    }

    #[Test]
    public function retiring_a_room_type_removes_its_rooms_from_availability(): void
    {
        $room = Room::factory()->ofType($this->type)->number('309')->create();

        $this->actingAs($this->admin)
            ->put(route('admin.room-types.update', $this->type), [
                'code' => $this->type->code, 'name' => $this->type->name, 'category' => 'NORMAL',
                'default_capacity' => 2, 'has_ac' => '1', 'is_active' => '0',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->type->fresh()->is_active);
        $row = collect(app(AvailabilityService::class)->summary('2026-10-10', '2026-10-12', $this->admin))
            ->firstWhere('name', $this->type->name);
        $this->assertNull($row, 'A retired type must not be offered in availability.');

        // And a crafted POST naming the room directly is refused by the service.
        $employee = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();
        $request = BookingRequest::factory()->for_($employee)->status(RequestStatus::PENDING_ALLOTMENT)
            ->members(1)->dates('2026-10-10', '2026-10-12')->create();

        $this->post(route('admin.allotments.store', $request), ['room_ids' => [$room->id]]);

        $this->assertSame(0, Allotment::where('room_id', $room->id)->count());
        $this->assertSame(RequestStatus::PENDING_ALLOTMENT, $request->fresh()->status);
    }

    // ============================================================= tariffs

    #[Test]
    public function a_rate_revision_closes_the_previous_rate_the_day_before_it_starts(): void
    {
        $old = Tariff::factory()->create(['room_type_id' => $this->type->id, 'amount_per_night' => '1000.00', 'effective_from' => '2026-01-01']);

        $this->actingAs($this->admin)
            ->post(route('admin.tariffs.store'), [
                'room_type_id' => $this->type->id, 'amount_per_night' => '1250.00', 'effective_from' => '2026-11-01',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-10-31', $old->fresh()->effective_to->format('Y-m-d'));

        $rate = fn (string $d) => Tariff::resolveFor($this->type->id, VisitPurpose::SELF, Carbon::parse($d));
        $this->assertSame('1000.00', $rate('2026-10-31'));
        $this->assertSame('1250.00', $rate('2026-11-01'));
        $this->assertTrue(AuditLog::where('action', 'TARIFF_CREATED')->exists());
    }

    #[Test]
    public function a_revision_that_does_not_start_after_the_current_rate_is_refused(): void
    {
        Tariff::factory()->create(['room_type_id' => $this->type->id, 'amount_per_night' => '1000.00', 'effective_from' => '2026-06-01']);

        $this->actingAs($this->admin)
            ->post(route('admin.tariffs.store'), [
                'room_type_id' => $this->type->id, 'amount_per_night' => '900.00', 'effective_from' => '2026-06-01',
            ])
            ->assertSessionHasErrors('tariff');

        $this->assertSame(1, Tariff::count());
    }

    #[Test]
    public function a_revision_never_changes_the_rate_already_on_an_allotment(): void
    {
        Tariff::factory()->create(['room_type_id' => $this->type->id, 'amount_per_night' => '1000.00', 'effective_from' => '2026-01-01']);
        $room = Room::factory()->ofType($this->type)->number('310')->create();
        $allotment = $this->allotRoom($room, '2026-11-05', '2026-11-07', ['rate_per_night' => '1000.00', 'total_amount' => '2000.00']);

        $this->actingAs($this->admin)->post(route('admin.tariffs.store'), [
            'room_type_id' => $this->type->id, 'amount_per_night' => '1500.00', 'effective_from' => '2026-11-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame('1000.00', (string) $allotment->fresh()->rate_per_night);
        $this->assertSame('2000.00', (string) $allotment->fresh()->total_amount);
    }

    #[Test]
    public function tariff_resolution_prefers_the_purpose_specific_rate_and_ignores_expired_ones(): void
    {
        Tariff::factory()->create(['room_type_id' => $this->type->id, 'amount_per_night' => '1000.00', 'effective_from' => '2026-01-01']);
        Tariff::factory()->forPurpose(VisitPurpose::TRAINING)->create(['room_type_id' => $this->type->id, 'amount_per_night' => '500.00', 'effective_from' => '2026-01-01']);
        Tariff::factory()->forPurpose(VisitPurpose::GUEST)->effective('2025-01-01', '2025-12-31')
            ->create(['room_type_id' => $this->type->id, 'amount_per_night' => '9999.00']);

        $on = Carbon::parse('2026-10-10');
        $this->assertSame('500.00', Tariff::resolveFor($this->type->id, VisitPurpose::TRAINING, $on));
        $this->assertSame('1000.00', Tariff::resolveFor($this->type->id, VisitPurpose::SELF, $on));
        $this->assertSame('1000.00', Tariff::resolveFor($this->type->id, VisitPurpose::GUEST, $on), 'An expired rate must be ignored.');
    }

    #[Test]
    public function a_purpose_specific_revision_leaves_the_general_rate_alone(): void
    {
        $general = Tariff::factory()->create(['room_type_id' => $this->type->id, 'amount_per_night' => '1000.00', 'effective_from' => '2026-01-01']);

        $this->actingAs($this->admin)->post(route('admin.tariffs.store'), [
            'room_type_id' => $this->type->id, 'purpose' => 'TRAINING', 'amount_per_night' => '600.00', 'effective_from' => '2026-10-01',
        ])->assertSessionHasNoErrors();

        $this->assertNull($general->fresh()->effective_to);
    }

    #[Test]
    public function a_tariff_can_be_ended_but_not_before_it_starts(): void
    {
        $tariff = Tariff::factory()->create(['room_type_id' => $this->type->id, 'effective_from' => '2026-06-01']);
        $this->actingAs($this->admin);

        $this->post(route('admin.tariffs.end', $tariff), ['effective_to' => '2026-05-31'])->assertSessionHasErrors('tariff');
        $this->assertNull($tariff->fresh()->effective_to);

        $this->post(route('admin.tariffs.end', $tariff), ['effective_to' => '2026-12-31'])->assertSessionHasNoErrors();
        $this->assertSame('2026-12-31', $tariff->fresh()->effective_to->format('Y-m-d'));
        $this->assertTrue(AuditLog::where('action', 'TARIFF_ENDED')->exists());
    }

    #[Test]
    public function a_negative_rate_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('admin.tariffs.store'), [
            'room_type_id' => $this->type->id, 'amount_per_night' => '-1', 'effective_from' => '2026-10-01',
        ])->assertSessionHasErrors('amount_per_night');
    }
}
