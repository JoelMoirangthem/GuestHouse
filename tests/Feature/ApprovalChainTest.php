<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Models\AuditLog;
use App\Models\BookingRequest;
use App\Models\RequestDocument;
use App\Models\RequestOccupant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TESTING.md — HappyPathWorkflowTest, MoreInfoLoopTest and
 * AdgCannotRequestMoreInfoTest. The Phase 4 gate.
 */
class ApprovalChainTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $manager;

    private User $adg;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adg = User::factory()->role(RoleSlug::ADG)->create();
        $this->admin = User::factory()->role(RoleSlug::ADMIN)->create();
        $this->manager = User::factory()->role(RoleSlug::MANAGER)->create([
            'reporting_manager_id' => $this->adg->id,
        ]);
        $this->employee = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();
    }

    /**
     * A complete request sitting with the manager.
     *
     * Occupants and a document are created because submit() re-validates those
     * preconditions on every submission, including a resubmission after
     * more-info. That is deliberate: an applicant could have deleted the ID proof
     * while editing, and the request must not slip back into the queue without it.
     */
    private function pendingRequest(): BookingRequest
    {
        $request = BookingRequest::factory()
            ->for_($this->employee)
            ->pendingManager($this->manager)
            ->create();

        RequestOccupant::factory()->primary()->create([
            'booking_request_id' => $request->id,
            'name' => $this->employee->name,
        ]);

        RequestDocument::factory()->create([
            'booking_request_id' => $request->id,
            'uploaded_by' => $this->employee->id,
        ]);

        return $request->refresh();
    }

    // ------------------------------------------------------- the happy path

    #[Test]
    public function a_request_is_approved_by_the_manager_and_goes_straight_to_awaiting_allotment(): void
    {
        $request = $this->pendingRequest();

        // The only approval — Manager approves and the request goes directly to
        // the Administration for room allotment. No ADG step.
        $this->actingAs($this->manager)
            ->post(route('manager.requests.approve', $request), ['remarks' => 'Recommended.'])
            ->assertRedirect(route('manager.requests.index'));

        $request->refresh();
        $this->assertSame(RequestStatus::PENDING_ALLOTMENT, $request->status);
        $this->assertSame($this->manager->id, $request->manager_id);
        $this->assertNotNull($request->manager_acted_at);

        // Core Rule 1: availability was never consulted during approval.
        $this->assertNull($request->availability_checked_at);
        $this->assertTrue($request->status->allowsAvailabilityCheck());
    }

    #[Test]
    public function the_manager_approval_writes_exactly_one_audit_entry(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->manager)->post(route('manager.requests.approve', $request));

        $entries = AuditLog::where('auditable_id', $request->id)->orderBy('id')->get();

        $this->assertCount(1, $entries);
        $this->assertSame('MANAGER_APPROVED', $entries[0]->action);
        $this->assertSame('PENDING_MANAGER', $entries[0]->from_status);
        $this->assertSame('PENDING_ALLOTMENT', $entries[0]->to_status);
        $this->assertSame($this->manager->id, $entries[0]->actor_id);
    }

    #[Test]
    public function the_audit_trail_cannot_be_edited_or_deleted(): void
    {
        $request = $this->pendingRequest();
        $this->actingAs($this->manager)->post(route('manager.requests.approve', $request));

        $entry = AuditLog::firstOrFail();

        // An approval history that can be rewritten is not a history.
        try {
            $entry->update(['remarks' => 'tampered']);
            $this->fail('An audit entry was modified.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $entry->delete();
            $this->fail('An audit entry was deleted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    // ------------------------------------------------------- more-info loop

    #[Test]
    public function the_more_info_loop_returns_to_the_manager_and_can_then_be_approved(): void
    {
        $request = $this->pendingRequest();

        // Manager asks for more information.
        $this->actingAs($this->manager)
            ->post(route('manager.requests.moreInfo', $request), [
                'remarks' => 'Please attach a legible Aadhaar copy.',
            ])->assertRedirect();

        $request->refresh();
        $this->assertSame(RequestStatus::MORE_INFO_MANAGER, $request->status);
        $this->assertNotNull($request->more_info_at);
        $this->assertSame('Please attach a legible Aadhaar copy.', $request->more_info_note);

        // The Manager has paused, not decided: the progress tracker must not
        // claim the review stage is finished.
        $this->assertNull($request->manager_acted_at);

        // Applicant resends.
        // assertSessionHasNoErrors matters here: a business-rule failure also
        // redirects, so assertRedirect alone would hide the reason.
        $this->actingAs($this->employee)
            ->post(route('my.requests.resubmit', $request))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $request->refresh();
        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->status);
        $this->assertNotNull($request->resubmitted_at);

        // And the Manager can now approve it, which sends it to allotment.
        $this->actingAs($this->manager)->post(route('manager.requests.approve', $request));
        $this->assertSame(RequestStatus::PENDING_ALLOTMENT, $request->fresh()->status);
    }

    #[Test]
    public function requesting_more_information_without_saying_what_is_needed_is_refused(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->manager)
            ->post(route('manager.requests.moreInfo', $request), ['remarks' => ''])
            ->assertSessionHasErrors('remarks');

        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->fresh()->status);
    }

    // ------------------------------------------------- DECISION 5: ADG limits

    #[Test]
    public function no_more_info_route_is_registered_for_the_adg(): void
    {
        // Layer 1 of four. Asserting the absence of the route, not merely that it
        // returns 403, because a route that exists can be reached by mistake later.
        $names = collect(Route::getRoutes())->map(fn ($r) => $r->getName())->filter()->all();

        foreach ($names as $name) {
            $this->assertStringNotContainsString(
                'adg.requests.moreInfo',
                (string) $name,
                'A more-info route exists for the ADG, violating decision 5.'
            );
        }

        $uris = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->all();

        foreach ($uris as $uri) {
            if (str_starts_with($uri, 'adg/')) {
                $this->assertStringNotContainsString('more-info', $uri);
            }
        }
    }

    #[Test]
    public function the_adg_cannot_reach_the_managers_more_info_endpoint(): void
    {
        // Layer 2: role middleware on the manager group.
        $request = BookingRequest::factory()
            ->for_($this->employee)
            ->status(RequestStatus::PENDING_ADG)
            ->create();

        $this->actingAs($this->adg)
            ->post(route('manager.requests.moreInfo', $request), ['remarks' => 'Need more detail.'])
            ->assertForbidden();

        $this->assertSame(RequestStatus::PENDING_ADG, $request->fresh()->status);
    }

    #[Test]
    public function the_adg_does_not_hold_a_more_info_permission(): void
    {
        // Layer 3: the permission simply does not exist for this role.
        $this->assertFalse($this->adg->hasPermission('request.moreinfo.manager'));
        $this->assertFalse($this->adg->hasPermission('request.moreinfo.adg'));

        // And the permission row itself was never seeded.
        $this->assertDatabaseMissing('permissions', ['slug' => 'request.moreinfo.adg']);
    }

    #[Test]
    public function the_adg_screen_renders_no_more_info_control(): void
    {
        // Layer 4, and the only user-visible one.
        $request = BookingRequest::factory()
            ->for_($this->employee)
            ->status(RequestStatus::PENDING_ADG)
            ->create();

        $response = $this->actingAs($this->adg)->get(route('adg.requests.show', $request));

        $response->assertOk()
            ->assertSee('Approve')
            ->assertSee('Reject')
            ->assertDontSee('More Info');
    }

    #[Test]
    public function the_manager_screen_does_render_a_more_info_control(): void
    {
        // The counterpart assertion: proves the previous test is detecting a real
        // difference rather than a broken template.
        $request = $this->pendingRequest();

        $this->actingAs($this->manager)
            ->get(route('manager.requests.show', $request))
            ->assertOk()
            ->assertSee('More Info');
    }

    // ------------------------------------------------------- stage integrity

    #[Test]
    public function the_adg_cannot_approve_a_request_the_manager_has_not_seen(): void
    {
        $request = $this->pendingRequest();   // still PENDING_MANAGER

        $this->actingAs($this->adg)
            ->post(route('adg.requests.approve', $request))
            ->assertSessionHasErrors('action');

        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->fresh()->status);
    }

    #[Test]
    public function a_manager_cannot_act_on_a_request_from_another_managers_team(): void
    {
        $otherManager = User::factory()->role(RoleSlug::MANAGER)->create();
        $otherEmployee = User::factory()->role(RoleSlug::USER)->reportingTo($otherManager)->create();

        $request = BookingRequest::factory()
            ->for_($otherEmployee)
            ->pendingManager($otherManager)
            ->create();

        $this->actingAs($this->manager)
            ->post(route('manager.requests.approve', $request))
            ->assertSessionHasErrors('action');

        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->fresh()->status);
    }

    #[Test]
    public function a_manager_cannot_approve_the_same_request_twice(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->manager)->post(route('manager.requests.approve', $request));
        $this->assertSame(RequestStatus::PENDING_ALLOTMENT, $request->fresh()->status);

        // The second attempt must be refused by the state machine, not silently
        // re-applied.
        $this->actingAs($this->manager)
            ->post(route('manager.requests.approve', $request))
            ->assertSessionHasErrors('action');

        $this->assertSame(1, AuditLog::where('action', 'MANAGER_APPROVED')->count());
    }

    #[Test]
    public function a_rejection_requires_a_recorded_reason(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->manager)
            ->post(route('manager.requests.reject', $request), ['remarks' => ''])
            ->assertSessionHasErrors('remarks');

        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->fresh()->status);
    }

    #[Test]
    public function a_rejected_request_is_terminal(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->manager)->post(route('manager.requests.reject', $request), [
            'remarks' => 'Guest house fully committed to a scheduled programme.',
        ]);

        $request->refresh();
        $this->assertSame(RequestStatus::REJECTED_MANAGER, $request->status);
        $this->assertTrue($request->status->isTerminal());

        // No further action can revive it.
        $this->actingAs($this->manager)
            ->post(route('manager.requests.approve', $request))
            ->assertSessionHasErrors('action');
    }

    #[Test]
    public function an_employee_cannot_reach_the_approval_queues(): void
    {
        $this->actingAs($this->employee)->get(route('manager.requests.index'))->assertForbidden();
        $this->actingAs($this->employee)->get(route('adg.requests.index'))->assertForbidden();
    }

    #[Test]
    public function the_applicant_sees_the_rejection_reason_on_their_own_request(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->manager)->post(route('manager.requests.reject', $request), [
            'remarks' => 'Dates clash with a scheduled programme.',
        ]);

        $this->actingAs($this->employee)
            ->get(route('my.requests.show', $request))
            ->assertOk()
            ->assertSee('Dates clash with a scheduled programme.');
    }
}
