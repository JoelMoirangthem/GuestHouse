<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\RoleSlug;
use App\Models\BookingRequest;
use App\Models\RequestDocument;
use App\Models\RequestOccupant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The "My decisions" history on the Manager's queue page: once a Manager
 * approves or rejects a request it leaves the pending queue and appears here.
 */
class ManagerDecisionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $adg = User::factory()->role(RoleSlug::ADG)->create();
        $this->manager = User::factory()->role(RoleSlug::MANAGER)->create(['reporting_manager_id' => $adg->id]);
        $this->employee = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();
    }

    private function pendingRequest(): BookingRequest
    {
        $request = BookingRequest::factory()->for_($this->employee)->pendingManager($this->manager)->create();

        RequestOccupant::factory()->primary()->create(['booking_request_id' => $request->id, 'name' => $this->employee->name]);
        RequestDocument::factory()->create(['booking_request_id' => $request->id, 'uploaded_by' => $this->employee->id]);

        return $request->refresh();
    }

    /** The rendered "My decisions" section only, so queue rows cannot satisfy an assertion. */
    private function historySection(array $query = []): string
    {
        $html = $this->actingAs($this->manager)->get(route('manager.requests.index', $query))->assertOk()->getContent();

        $start = strpos($html, 'id="history"');
        $this->assertNotFalse($start, 'The decision history section is missing.');

        return substr($html, $start);
    }

    #[Test]
    public function approved_and_rejected_requests_move_from_the_queue_to_the_history(): void
    {
        $approved = $this->pendingRequest();
        $rejected = $this->pendingRequest();
        $stillPending = $this->pendingRequest();

        $this->approveWithRooms($this->manager, $approved, ['remarks' => 'Recommended.']);
        $this->actingAs($this->manager)->post(route('manager.requests.reject', $rejected), ['remarks' => 'Dates clash with the audit.']);

        // The redirect after a decision shows a one-time "REQ/... has been
        // rejected" banner; consume it so it cannot be mistaken for a queue row.
        $this->actingAs($this->manager)->get(route('manager.requests.index'))
            ->assertSee("{$rejected->request_no} has been rejected.");

        $html = $this->actingAs($this->manager)->get(route('manager.requests.index'))->assertOk()->getContent();
        [$queue, $history] = explode('id="history"', $html, 2);

        // Decided requests have left the queue; the undecided one is still there.
        $this->assertStringContainsString($stillPending->request_no, $queue);
        $this->assertStringNotContainsString($approved->request_no, $queue);
        $this->assertStringNotContainsString($rejected->request_no, $queue);

        // ...and are in the history with the decision shown.
        $this->assertStringContainsString($approved->request_no, $history);
        $this->assertStringContainsString($rejected->request_no, $history);
        $this->assertStringNotContainsString($stillPending->request_no, $history);
        $this->assertStringContainsString('Approved', $history);
        $this->assertStringContainsString('Rejected', $history);
        $this->assertStringContainsString('Dates clash with the audit.', $history);
    }

    #[Test]
    public function the_history_can_be_filtered_by_decision(): void
    {
        $approved = $this->pendingRequest();
        $rejected = $this->pendingRequest();

        $this->approveWithRooms($this->manager, $approved);
        $this->actingAs($this->manager)->post(route('manager.requests.reject', $rejected), ['remarks' => 'Not eligible.']);

        $onlyApproved = $this->historySection(['decision' => 'approved']);
        $this->assertStringContainsString($approved->request_no, $onlyApproved);
        $this->assertStringNotContainsString($rejected->request_no, $onlyApproved);

        $onlyRejected = $this->historySection(['decision' => 'rejected']);
        $this->assertStringContainsString($rejected->request_no, $onlyRejected);
        $this->assertStringNotContainsString($approved->request_no, $onlyRejected);
    }

    #[Test]
    public function asking_for_more_information_is_not_a_decision(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->manager)->post(route('manager.requests.moreInfo', $request), ['remarks' => 'Attach the training order.']);

        $this->assertStringNotContainsString($request->request_no, $this->historySection());
    }

    #[Test]
    public function a_manager_sees_only_their_own_decisions_and_can_open_them(): void
    {
        $mine = $this->pendingRequest();
        $this->approveWithRooms($this->manager, $mine);

        $other = User::factory()->role(RoleSlug::MANAGER)->create();
        $otherEmployee = User::factory()->role(RoleSlug::USER)->reportingTo($other)->create();
        $theirs = BookingRequest::factory()->for_($otherEmployee)->pendingManager($other)->create();
        RequestOccupant::factory()->primary()->create(['booking_request_id' => $theirs->id]);
        RequestDocument::factory()->create(['booking_request_id' => $theirs->id, 'uploaded_by' => $otherEmployee->id]);
        $this->actingAs($other)->post(route('manager.requests.reject', $theirs), ['remarks' => 'Not eligible.']);

        $history = $this->historySection();
        $this->assertStringContainsString($mine->request_no, $history);
        $this->assertStringNotContainsString($theirs->request_no, $history);

        // The "View" link opens the decided request.
        $this->actingAs($this->manager)->get(route('manager.requests.show', $mine))->assertOk()->assertSee($mine->request_no);
    }

    #[Test]
    public function the_managers_own_booking_and_requests_outside_the_reporting_line_appear_in_the_history(): void
    {
        // The single Manager decides every booking, so these must be recorded too.
        $noLine = User::factory()->role(RoleSlug::USER)->create(['reporting_manager_id' => null]);
        $outside = BookingRequest::factory()->for_($noLine)->pendingManager($this->manager)->create();
        RequestOccupant::factory()->primary()->create(['booking_request_id' => $outside->id]);
        RequestDocument::factory()->create(['booking_request_id' => $outside->id, 'uploaded_by' => $noLine->id]);

        // e.g. a room the Manager reserves for a visiting VIP.
        $own = BookingRequest::factory()->for_($this->manager)->pendingManager($this->manager)->create();
        RequestOccupant::factory()->primary()->create(['booking_request_id' => $own->id, 'name' => 'Visiting Dignitary']);
        RequestDocument::factory()->create(['booking_request_id' => $own->id, 'uploaded_by' => $this->manager->id]);

        $this->approveWithRooms($this->manager, $own)->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('manager.requests.reject', $outside), ['remarks' => 'Not eligible.'])
            ->assertSessionHasNoErrors();

        $approved = $this->historySection(['decision' => 'approved']);
        $this->assertStringContainsString($own->request_no, $approved);
        $this->assertStringNotContainsString($outside->request_no, $approved);

        $rejected = $this->historySection(['decision' => 'rejected']);
        $this->assertStringContainsString($outside->request_no, $rejected);
        $this->assertStringNotContainsString($own->request_no, $rejected);
    }
}
