<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Services\DashboardService;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Domain\Enums\VisitPurpose;
use App\Models\Allotment;
use App\Models\BookingRequest;
use App\Models\Notification;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin dashboard figures.
 *
 * The fixture is small and hand-counted so every expected figure below can be
 * checked with a pencil. October 2026 has 31 days.
 *
 *   Rooms:  STD × 4 in service + 1 under maintenance, VIP × 1   → 5 in service
 *   R1  e1  CHECKED_OUT     S1 05→08 (3 nights)
 *   R2  e1  EARLY_CHECKOUT  S2 10→15 but left on the 12th (2 nights)
 *   R3  e2  ALLOTTED        S2 12→14 (2 nights) — the room R2 vacated
 *                           V1 12→14 CANCELLED (must be ignored everywhere)
 *   R4  e2  REJECTED_MANAGER
 *   R5  e1  DRAFT           (never counted)
 *   R6  e2  CANCELLED, submitted in September (outside the period)
 *   R7  e1  PENDING_MANAGER
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-10-01';

    private const TO = '2026-10-31';

    private User $admin;

    private User $e1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow('2026-10-15 12:00:00');

        $this->admin = User::factory()->role(RoleSlug::ADMIN)->create();
        $manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $this->e1 = User::factory()->role(RoleSlug::USER)->reportingTo($manager)->create();
        $e2 = User::factory()->role(RoleSlug::USER)->reportingTo($manager)->create();

        $std = RoomType::factory()->create(['code' => 'STD', 'name' => 'Standard AC', 'sort_order' => 1]);
        $vip = RoomType::factory()->vip()->create(['code' => 'VIP', 'sort_order' => 2]);
        $s1 = Room::factory()->ofType($std)->number('101')->create();
        $s2 = Room::factory()->ofType($std)->number('102')->create();
        Room::factory()->ofType($std)->number('103')->create();
        Room::factory()->ofType($std)->number('104')->create();
        Room::factory()->ofType($std)->number('105')->maintenance()->create();
        $v1 = Room::factory()->ofType($vip)->number('201')->create();

        $req = fn (User $u, RequestStatus $s, ?string $submitted, string $in, string $out, array $extra = []) => BookingRequest::factory()
            ->for_($u)->status($s)->purpose(VisitPurpose::SELF)->dates($in, $out)
            ->create(['submitted_at' => $submitted, ...$extra]);

        $r1 = $req($this->e1, RequestStatus::CHECKED_OUT, '2026-10-02 09:00:00', '2026-10-05', '2026-10-08');
        $r2 = $req($this->e1, RequestStatus::EARLY_CHECKOUT, '2026-10-03 09:00:00', '2026-10-10', '2026-10-15');
        $r3 = $req($e2, RequestStatus::ALLOTTED, '2026-10-05 09:00:00', '2026-10-12', '2026-10-14');
        $req($e2, RequestStatus::REJECTED_MANAGER, '2026-10-06 09:00:00', '2026-10-20', '2026-10-21');
        $req($this->e1, RequestStatus::DRAFT, null, '2026-10-25', '2026-10-26');
        $req($e2, RequestStatus::CANCELLED, '2026-09-20 09:00:00', '2026-10-01', '2026-10-02');
        $req($this->e1, RequestStatus::PENDING_MANAGER, '2026-10-07 09:00:00', '2026-10-28', '2026-10-30', ['manager_id' => $manager->id]);

        $allot = fn (BookingRequest $r, Room $room, string $in, string $out, string $status, array $extra = []) => Allotment::factory()
            ->forRoom($room)->dates($in, $out)
            ->create(['booking_request_id' => $r->id, 'status' => $status, 'rate_per_night' => '1000.00', 'total_amount' => '1000.00', ...$extra]);

        $allot($r1, $s1, '2026-10-05', '2026-10-08', 'CHECKED_OUT', [
            'allotted_at' => '2026-10-03 10:00:00', 'actual_check_in_at' => '2026-10-05 12:00:00', 'actual_check_out_at' => '2026-10-08 10:00:00']);
        $allot($r2, $s2, '2026-10-10', '2026-10-15', 'EARLY_CHECKOUT', [
            'allotted_at' => '2026-10-04 10:00:00', 'actual_check_in_at' => '2026-10-10 12:00:00', 'actual_check_out_at' => '2026-10-12 09:00:00']);
        $allot($r3, $s2, '2026-10-12', '2026-10-14', 'ALLOTTED', ['allotted_at' => '2026-10-06 10:00:00']);
        $allot($r3, $v1, '2026-10-12', '2026-10-14', 'CANCELLED', ['allotted_at' => '2026-10-06 10:00:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_dashboard_tiles_match_the_fixture(): void
    {
        Notification::create(['user_id' => $this->e1->id, 'event_key' => 'rooms.allotted', 'channel' => 'EMAIL',
            'title' => 't', 'body' => 'b', 'status' => 'FAILED']);

        $tiles = app(DashboardService::class)->dashboardTiles(self::FROM, self::TO);

        $this->assertSame([
            'total' => 5,                // R1 R2 R3 R4 R7; not the draft, not September's R6
            'pending_manager' => 1,      // R7
            'pending_adg' => 0,
            'awaiting_allotment' => 0,
            'rooms_allotted' => 3,       // rooms, not requests; the cancelled V1 row is excluded
            'failed_notifications' => 1,
        ], $tiles);
    }

    #[Test]
    public function daily_occupancy_does_not_double_count_a_room_vacated_early(): void
    {
        $byDay = collect(app(DashboardService::class)->occupancyByDay(self::FROM, self::TO))->keyBy('date');

        $this->assertCount(31, $byDay);
        $this->assertSame(1, $byDay['2026-10-05']['occupied']);
        $this->assertSame(0, $byDay['2026-10-08']['occupied'], 'Checkout day is free (half-open interval).');
        $this->assertSame(1, $byDay['2026-10-11']['occupied']);
        // R2 left on the 12th and R3 took the same room: one room, not two.
        $this->assertSame(1, $byDay['2026-10-12']['occupied']);
        $this->assertSame(20.0, $byDay['2026-10-12']['pct']);   // 1 of 5 rooms in service
        $this->assertSame(0, $byDay['2026-10-14']['occupied']);
    }

    #[Test]
    public function the_dashboard_renders_and_pages_by_month(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('October 2026')
            ->assertSee('Rooms allotted')
            ->assertSee('Standard AC')
            ->assertDontSee('All reports');

        $this->get(route('dashboard', ['month' => '2026-09']))->assertOk()->assertSee('September 2026');
        $this->get(route('dashboard', ['month' => 'not-a-month']))->assertSessionHasErrors('month');
    }

    #[Test]
    public function the_reports_pages_no_longer_exist(): void
    {
        $this->actingAs($this->admin)->get('/reports')->assertNotFound();
        $this->actingAs($this->admin)->get('/reports/booking')->assertNotFound();
        $this->actingAs($this->admin)->get('/reports/booking/export/xlsx')->assertNotFound();
    }
}
