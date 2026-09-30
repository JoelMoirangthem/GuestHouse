<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Services\AvailabilityService;
use App\Domain\Enums\RoleSlug;
use App\Models\Allotment;
use App\Models\BookingRequest;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Read-only inventory for the Manager and ADG — REPORTS.md section 4,
 * ROUTES.md "inventory.view". Current counts only; no date-range search.
 */
class ManagementInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $adg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow('2026-10-10 10:00:00');

        $this->manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $this->adg = User::factory()->role(RoleSlug::ADG)->create();

        // 4 rooms: 1 booked tonight, 1 booked only NEXT week, 1 under maintenance, 1 free.
        $type = RoomType::factory()->create(['name' => 'Standard AC']);
        $employee = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();
        $tonight = Room::factory()->ofType($type)->number('101')->create();
        $nextWeek = Room::factory()->ofType($type)->number('102')->create();
        Room::factory()->ofType($type)->number('103')->maintenance()->create();
        Room::factory()->ofType($type)->number('104')->create();

        foreach ([[$tonight, '2026-10-09', '2026-10-12'], [$nextWeek, '2026-10-17', '2026-10-19']] as [$room, $in, $out]) {
            $r = BookingRequest::factory()->for_($employee)->dates($in, $out)->create();
            Allotment::factory()->forRoom($room)->dates($in, $out)->create(['booking_request_id' => $r->id]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_adg_sees_tonights_counts(): void
    {
        $this->actingAs($this->adg)->get(route('adg.inventory'))
            ->assertOk()
            ->assertSee('Standard AC')
            ->assertSee('Position for tonight')
            ->assertViewHas('totals', ['total' => 4, 'available' => 2, 'booked' => 1, 'blocked' => 1]);
    }

    #[Test]
    public function the_manager_uses_the_full_room_inventory(): void
    {
        // The Manager runs the booking operation, so they use the same Room
        // Inventory as the Administration rather than a separate manager page.
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('manager.inventory'));
        $this->assertTrue($this->manager->hasPermission('inventory.view'));

        $this->actingAs($this->manager)->get(route('manager.requests.index'))
            ->assertOk()->assertSee('Room Inventory');
        $this->actingAs($this->manager)->get(route('admin.inventory'))->assertOk();
    }

    #[Test]
    public function a_date_range_in_the_query_string_is_ignored(): void
    {
        // Asking for next week must NOT reveal that room 102 is booked then.
        $this->actingAs($this->adg)
            ->get(route('adg.inventory', ['from' => '2026-10-17', 'to' => '2026-10-19']))
            ->assertOk()
            ->assertViewHas('totals', ['total' => 4, 'available' => 2, 'booked' => 1, 'blocked' => 1]);
    }

    #[Test]
    public function the_screen_offers_no_date_picker(): void
    {
        $html = $this->actingAs($this->adg)->get(route('adg.inventory'))->getContent();

        $this->assertStringNotContainsString('type="date"', $html);
        $this->assertStringNotContainsString('name="from"', $html);
    }

    #[Test]
    public function each_role_reaches_only_its_own_inventory_route(): void
    {
        $employee = User::factory()->role(RoleSlug::USER)->create();
        $admin = User::factory()->role(RoleSlug::ADMIN)->create();

        foreach ([$employee, $admin, $this->manager] as $u) {
            $this->actingAs($u)->get(route('adg.inventory'))->assertForbidden();
        }
    }

    #[Test]
    public function the_service_refuses_a_user_without_inventory_permission(): void
    {
        $this->expectException(AuthorizationException::class);
        app(AvailabilityService::class)->currentSnapshot(User::factory()->role(RoleSlug::USER)->create());
    }

    #[Test]
    public function the_adg_still_cannot_run_the_date_range_search(): void
    {
        // The Manager runs allotment and may search dates (admin.inventory);
        // the ADG sees only tonight's counts.
        $this->expectException(AuthorizationException::class);
        app(AvailabilityService::class)->summary('2026-10-17', '2026-10-19', $this->adg);
    }
}
