<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Domain\Enums\VisitPurpose;
use App\Models\AuditLog;
use App\Models\BookingRequest;
use App\Models\RequestDocument;
use App\Models\RequestOccupant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
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

    /**
     * Form payload for a booking the Manager raises for themselves.
     *
     * @return array<string, mixed>
     */
    private function ownBookingPayload(): array
    {
        Storage::fake('private');

        return [
            'purpose' => VisitPurpose::SELF->value,
            'check_in_date' => now()->addDays(5)->format('Y-m-d'),
            'check_out_date' => now()->addDays(7)->format('Y-m-d'),
            'total_members' => 1,
            'contact_mobile' => '9876543210',
            'contact_email' => 'manager@nadt.gov.in',
            'occupants' => [
                ['name' => 'Visiting Dignitary', 'age' => 55, 'gender' => 'M', 'id_proof_type' => 'AADHAAR', 'id_proof_number' => '123456781234'],
            ],
            'documents' => [UploadedFile::fake()->create('Aadhaar Card.pdf', 120, 'application/pdf')],
        ];
    }

    // ------------------------------------------------------- the happy path

    #[Test]
    public function a_request_is_approved_by_the_manager_with_rooms_and_is_allotted(): void
    {
        $request = $this->pendingRequest();

        // The only approval — the Manager picks the room(s) and approves, which
        // allots them in the same step. No ADG step.
        $this->approveWithRooms($this->manager, $request, ['remarks' => 'Recommended.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('manager.requests.index'));

        $request->refresh();
        $this->assertSame(RequestStatus::ALLOTTED, $request->status);
        $this->assertSame($this->manager->id, $request->manager_id);
        $this->assertNotNull($request->manager_acted_at);
        $this->assertSame(1, $request->allotments()->occupying()->count());
    }

    #[Test]
    public function approving_without_selecting_a_room_is_refused(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->manager)
            ->post(route('manager.requests.approve', $request), ['remarks' => 'Recommended.'])
            ->assertSessionHasErrors('room_ids');

        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->fresh()->status);
        $this->assertSame(0, AuditLog::where('auditable_id', $request->id)->count());
    }

    #[Test]
    public function the_manager_approval_is_audited_once_followed_by_the_allotment(): void
    {
        $request = $this->pendingRequest();

        $this->approveWithRooms($this->manager, $request);

        $entries = AuditLog::where('auditable_id', $request->id)
            ->whereNotNull('to_status')->orderBy('id')->get();

        $this->assertCount(2, $entries);
        $this->assertSame('MANAGER_APPROVED', $entries[0]->action);
        $this->assertSame('PENDING_MANAGER', $entries[0]->from_status);
        $this->assertSame('PENDING_ALLOTMENT', $entries[0]->to_status);
        $this->assertSame($this->manager->id, $entries[0]->actor_id);
        $this->assertSame('ROOMS_CONFIRMED', $entries[1]->action);
        $this->assertSame('ALLOTTED', $entries[1]->to_status);
    }

    #[Test]
    public function the_audit_trail_cannot_be_edited_or_deleted(): void
    {
        $request = $this->pendingRequest();
        $this->approveWithRooms($this->manager, $request);

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

        // And the Manager can now approve it, allotting the room.
        $this->approveWithRooms($this->manager, $request)->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::ALLOTTED, $request->fresh()->status);
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
    public function the_manager_can_act_on_any_request_whatever_the_reporting_line(): void
    {
        // There is a single Manager who decides every booking, so a request
        // whose applicant reports elsewhere (or whose reporting line changed
        // after submission) must still be actionable.
        $otherManager = User::factory()->role(RoleSlug::MANAGER)->create();
        $otherEmployee = User::factory()->role(RoleSlug::USER)->reportingTo($otherManager)->create();

        $request = BookingRequest::factory()
            ->for_($otherEmployee)
            ->pendingManager($otherManager)
            ->create();

        $this->actingAs($this->manager)
            ->get(route('manager.requests.index'))
            ->assertOk()
            ->assertSee($request->request_no);

        $this->approveWithRooms($this->manager, $request)->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::ALLOTTED, $request->fresh()->status);
        $this->assertSame($this->manager->id, $request->fresh()->manager_id);
    }

    #[Test]
    public function the_managers_own_booking_is_approved_immediately_as_a_reservation(): void
    {
        // e.g. a room reserved by the Manager for a visiting VIP. The Manager is
        // the only approver, so it skips the review queue.
        $this->actingAs($this->manager)
            ->post(route('my.requests.store'), $this->ownBookingPayload())
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'approved'));

        $request = BookingRequest::where('user_id', $this->manager->id)->firstOrFail();
        $this->assertSame(RequestStatus::PENDING_ALLOTMENT, $request->status);
        $this->assertSame($this->manager->id, $request->manager_id);
        $this->assertNotNull($request->manager_acted_at);
        $this->assertSame('Reserved directly by the Manager.', $request->manager_remarks);

        // Not left in the Manager's queue; it is in their decision history.
        // (The first visit consumes the one-time success banner.)
        $this->actingAs($this->manager)->get(route('manager.requests.index'));
        [$queue, $history] = explode('id="history"', $this->actingAs($this->manager)
            ->get(route('manager.requests.index'))->getContent(), 2);
        $this->assertStringNotContainsString($request->request_no, $queue);
        $this->assertStringContainsString($request->request_no, $history);

        // The approval is audited like any other.
        $this->assertSame(1, AuditLog::where('auditable_id', $request->id)->where('action', 'MANAGER_APPROVED')->count());
    }

    #[Test]
    public function a_training_reservation_shows_the_guest_and_programme_not_just_the_manager(): void
    {
        $payload = $this->ownBookingPayload();
        $payload['purpose'] = \App\Domain\Enums\VisitPurpose::TRAINING->value;
        $payload['training_programme'] = 'Induction for IRS Probationers';

        $this->actingAs($this->manager)->post(route('my.requests.store'), $payload)->assertSessionHasNoErrors();

        $request = BookingRequest::firstOrFail();
        $this->assertSame('Visiting Dignitary', $request->guestName());

        // The Administration's allotment queue, where the reservation lands.
        $this->actingAs($this->admin)->get(route('admin.allotments.index'))
            ->assertOk()
            ->assertSee('Visiting Dignitary')
            ->assertSee('Induction for IRS Probationers');
    }

    #[Test]
    public function an_employees_booking_still_waits_for_the_manager(): void
    {
        $this->actingAs($this->employee)
            ->post(route('my.requests.store'), $this->ownBookingPayload())
            ->assertRedirect();

        $this->assertSame(RequestStatus::PENDING_MANAGER, BookingRequest::firstOrFail()->status);
    }

    #[Test]
    public function a_non_manager_cannot_approve(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->employee)
            ->post(route('manager.requests.approve', $request))
            ->assertForbidden();

        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->fresh()->status);
    }

    #[Test]
    public function a_manager_cannot_approve_the_same_request_twice(): void
    {
        $request = $this->pendingRequest();

        $this->approveWithRooms($this->manager, $request);
        $this->assertSame(RequestStatus::ALLOTTED, $request->fresh()->status);

        // The second attempt must be refused by the state machine, not silently
        // re-applied.
        $this->approveWithRooms($this->manager, $request)
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
        $this->approveWithRooms($this->manager, $request)
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
