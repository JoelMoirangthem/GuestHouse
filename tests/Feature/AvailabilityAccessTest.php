<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Services\AvailabilityService;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Models\BookingRequest;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TESTING.md — AvailabilityIsAdminOnlyTest and
 * AvailabilityBlockedBeforeApprovalTest.
 *
 * These two tests defend the rules that define this system. Core Rule 2 says
 * users never see availability; Core Rule 1 says nobody sees it before the
 * approval chain completes.
 */
class AvailabilityAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private User $manager;

    private User $adg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->role(RoleSlug::ADMIN)->create();
        $this->adg = User::factory()->role(RoleSlug::ADG)->create();
        $this->manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $this->employee = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();

        $type = RoomType::factory()->create();
        Room::factory()->ofType($type)->number('101')->create();
    }

    private function approved(): BookingRequest
    {
        return BookingRequest::factory()
            ->for_($this->employee)
            ->status(RequestStatus::PENDING_ALLOTMENT)
            ->dates('2026-10-20', '2026-10-23')
            ->create();
    }

    // ------------------------------------------------- CORE RULE 2: admin only

    #[Test]
    public function the_administrator_can_reach_the_availability_screen(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.availability.show', $this->approved()))
            ->assertOk()
            ->assertSee('Availability summary');
    }

    #[Test]
    public function no_other_role_can_reach_the_availability_screen(): void
    {
        $request = $this->approved();

        // The Manager runs the booking operation, so only the employee and the
        // ADG are refused.
        $this->actingAs($this->manager)->get(route('admin.availability.show', $request))->assertOk();

        foreach ([$this->employee, $this->adg] as $actor) {
            $this->actingAs($actor)
                ->get(route('admin.availability.show', $request))
                ->assertForbidden();
        }
    }

    #[Test]
    public function no_other_role_can_reach_the_allotment_screens(): void
    {
        $request = $this->approved();

        $this->actingAs($this->manager)->get(route('admin.allotments.index'))->assertOk();
        $this->actingAs($this->manager)->get(route('admin.allotments.create', $request))->assertOk();

        foreach ([$this->employee, $this->adg] as $actor) {
            $this->actingAs($actor)->get(route('admin.allotments.index'))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.allotments.create', $request))->assertForbidden();
            $this->actingAs($actor)->post(route('admin.allotments.store', $request), ['room_ids' => [1]])->assertForbidden();
        }
    }

    #[Test]
    public function the_room_inventory_screens_no_longer_exist(): void
    {
        $this->assertFalse(Route::has('admin.inventory'));
        $this->assertFalse(Route::has('adg.inventory'));

        foreach ([$this->manager, $this->adg] as $actor) {
            $this->actingAs($actor)->get('/admin/inventory')->assertNotFound();
            $this->actingAs($actor)->get('/adg/inventory')->assertNotFound();
            $this->actingAs($actor)->get(route('home'))->assertRedirect();
        }
    }

    #[Test]
    public function no_availability_route_exists_anywhere_outside_the_admin_prefix(): void
    {
        // Structural assertion. A route that does not exist cannot be reached by a
        // crafted request, a mistaken middleware change, or a future refactor.
        //
        // The Room Inventory screens were removed, so there are no exceptions.
        $allowed = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (in_array($uri, $allowed, true)) {
                $this->assertSame(['GET', 'HEAD'], $route->methods(), "{$uri} must be read-only.");

                continue;
            }

            if (str_contains($uri, 'availability') || str_contains($uri, 'allot') || str_contains($uri, 'inventory')) {
                $this->assertStringStartsWith(
                    'admin/',
                    $uri,
                    "Route '{$uri}' exposes availability or allotment outside the admin area."
                );
            }
        }
    }

    #[Test]
    public function the_user_area_contains_no_availability_route(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'my/')) {
                foreach (['availability', 'allot', 'inventory', 'rooms'] as $forbidden) {
                    $this->assertStringNotContainsString(
                        $forbidden,
                        $route->uri(),
                        'The user area must never expose room information. Core Rule 2.'
                    );
                }
            }
        }
    }

    #[Test]
    public function the_service_refuses_a_non_administrator_even_when_called_directly(): void
    {
        // Defence in depth: the service does not rely on the route's middleware.
        // A console command or queued job calling it must be refused too.
        $request = $this->approved();

        foreach ([$this->employee, $this->adg] as $actor) {
            try {
                app(AvailabilityService::class)->summaryForRequest($request, $actor);
                $this->fail("Role {$actor->role->slug} obtained an availability summary.");
            } catch (AuthorizationException $e) {
                $this->assertStringContainsString('Manager or the Administration only', $e->getMessage());
            }
        }

        $this->assertIsArray(app(AvailabilityService::class)->summaryForRequest($request, $this->manager));
    }

    // --------------------------------------- CORE RULE 1: only after approval

    #[Test]
    public function availability_cannot_be_checked_before_the_approval_chain_completes(): void
    {
        $states = [
            RequestStatus::DRAFT,
            RequestStatus::PENDING_MANAGER,
            RequestStatus::MORE_INFO_MANAGER,
            RequestStatus::PENDING_ADG,
        ];

        foreach ($states as $status) {
            $request = BookingRequest::factory()
                ->for_($this->employee)
                ->status($status)
                ->dates('2026-10-20', '2026-10-23')
                ->create();

            // 409 Conflict, not 403: the administrator is allowed to do this, just
            // not yet. The record's state is what is wrong, not the actor.
            $this->actingAs($this->admin)
                ->get(route('admin.availability.show', $request))
                ->assertStatus(409);
        }
    }

    #[Test]
    public function availability_cannot_be_checked_after_a_rejection(): void
    {
        foreach ([RequestStatus::REJECTED_MANAGER, RequestStatus::REJECTED_ADG, RequestStatus::CANCELLED] as $status) {
            $request = BookingRequest::factory()
                ->for_($this->employee)
                ->status($status)
                ->dates('2026-10-20', '2026-10-23')
                ->create();

            $this->actingAs($this->admin)
                ->get(route('admin.availability.show', $request))
                ->assertStatus(409);
        }
    }

    #[Test]
    public function availability_is_permitted_in_both_allotment_states(): void
    {
        foreach ([RequestStatus::PENDING_ALLOTMENT, RequestStatus::PARTIALLY_ALLOTTED] as $status) {
            $request = BookingRequest::factory()
                ->for_($this->employee)
                ->status($status)
                ->dates('2026-10-20', '2026-10-23')
                ->create();

            $this->actingAs($this->admin)
                ->get(route('admin.availability.show', $request))
                ->assertOk();
        }
    }

    // ------------------------------------------------- audit evidence

    #[Test]
    public function the_first_availability_check_is_stamped_and_audited(): void
    {
        $request = $this->approved();

        $this->assertNull($request->availability_checked_at);

        $this->actingAs($this->admin)->get(route('admin.availability.show', $request));

        $request->refresh();

        // This timestamp is the evidence that Core Rule 1 was honoured: it can be
        // compared against adg_acted_at to prove the check came after approval.
        $this->assertNotNull($request->availability_checked_at);
        $this->assertSame($this->admin->id, $request->availability_checked_by);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $request->id,
            'action' => 'AVAILABILITY_CHECKED',
            'actor_id' => $this->admin->id,
        ]);
    }

    #[Test]
    public function a_second_check_does_not_overwrite_the_original_timestamp(): void
    {
        $request = $this->approved();

        $this->actingAs($this->admin)->get(route('admin.availability.show', $request));
        $first = $request->fresh()->availability_checked_at;

        $this->travel(2)->minutes();

        $this->actingAs($this->admin)->get(route('admin.availability.show', $request));

        $this->assertEquals($first, $request->fresh()->availability_checked_at);
    }

    #[Test]
    public function an_inverted_date_window_is_refused(): void
    {
        $request = $this->approved();

        $this->actingAs($this->admin)
            ->get(route('admin.availability.show', $request).'?from=2026-10-25&to=2026-10-20')
            ->assertStatus(409);
    }
}
