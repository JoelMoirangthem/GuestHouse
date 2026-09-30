<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Domain\Enums\VisitPurpose;
use App\Models\BookingRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TESTING.md — DateValidationTest plus the Phase 3 submission gate.
 */
class BookingRequestSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('private');

        $adg = User::factory()->role(RoleSlug::ADG)->create();
        $this->manager = User::factory()->role(RoleSlug::MANAGER)->create(['reporting_manager_id' => $adg->id]);
        $this->employee = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'purpose' => VisitPurpose::SELF->value,
            'check_in_date' => now()->addDays(5)->format('Y-m-d'),
            'check_out_date' => now()->addDays(7)->format('Y-m-d'),
            'total_members' => 1,
            'contact_mobile' => '9876543210',
            'contact_email' => 'employee@nadt.gov.in',
            'occupants' => [
                ['name' => 'Rajesh Kumar', 'age' => 41, 'gender' => 'M', 'id_proof_type' => 'AADHAAR', 'id_proof_number' => '123456781234'],
            ],
            'documents' => [UploadedFile::fake()->create('Aadhaar Card.pdf', 120, 'application/pdf')],
        ], $overrides);
    }

    // ------------------------------------------------------- the Phase 3 gate

    #[Test]
    public function a_submitted_request_lands_in_pending_manager(): void
    {
        $this->actingAs($this->employee)
            ->post(route('my.requests.store'), $this->payload())
            ->assertRedirect();

        $request = BookingRequest::firstOrFail();

        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->status);
        $this->assertNotNull($request->submitted_at);

        // Routed to the applicant's own reporting manager, not to a shared pool.
        $this->assertSame($this->manager->id, $request->manager_id);
    }

    #[Test]
    public function submitting_creates_the_occupant_and_document_records(): void
    {
        $this->actingAs($this->employee)->post(route('my.requests.store'), $this->payload());

        $request = BookingRequest::with(['occupants', 'documents'])->firstOrFail();

        $this->assertCount(1, $request->occupants);
        $this->assertCount(1, $request->documents);
        $this->assertTrue($request->occupants->first()->is_primary);
    }

    #[Test]
    public function the_request_number_is_sequential_and_year_scoped(): void
    {
        $year = now()->format('Y');

        $this->actingAs($this->employee)->post(route('my.requests.store'), $this->payload());
        $this->actingAs($this->employee)->post(route('my.requests.store'), $this->payload());

        $numbers = BookingRequest::orderBy('id')->pluck('request_no')->all();

        $this->assertSame("REQ/{$year}/00001", $numbers[0]);
        $this->assertSame("REQ/{$year}/00002", $numbers[1]);
    }

    #[Test]
    public function an_identity_number_is_encrypted_at_rest_and_masked_for_display(): void
    {
        $this->actingAs($this->employee)->post(route('my.requests.store'), $this->payload());

        $occupant = BookingRequest::firstOrFail()->occupants->first();

        // Masked form for the UI.
        $this->assertSame('XXXX XXXX 1234', $occupant->maskedIdProof());

        // The raw value must not be readable in the database.
        $stored = \DB::table('request_occupants')->where('id', $occupant->id)->value('id_proof_number');
        $this->assertNotSame('123456781234', $stored);
        $this->assertStringNotContainsString('123456781234', (string) $stored);

        // But the application can still decrypt it.
        $this->assertSame('123456781234', $occupant->id_proof_number);
    }

    // ------------------------------------------------------- date validation

    #[Test]
    public function a_checkout_before_checkin_is_rejected(): void
    {
        $this->actingAs($this->employee)
            ->post(route('my.requests.store'), $this->payload([
                'check_in_date' => now()->addDays(10)->format('Y-m-d'),
                'check_out_date' => now()->addDays(8)->format('Y-m-d'),
            ]))
            ->assertSessionHasErrors('check_out_date');

        $this->assertSame(0, BookingRequest::count());
    }

    #[Test]
    public function a_same_day_checkout_is_rejected_because_a_stay_is_at_least_one_night(): void
    {
        $date = now()->addDays(5)->format('Y-m-d');

        $this->actingAs($this->employee)
            ->post(route('my.requests.store'), $this->payload([
                'check_in_date' => $date,
                'check_out_date' => $date,
            ]))
            ->assertSessionHasErrors('check_out_date');
    }

    #[Test]
    public function a_checkin_date_in_the_past_is_rejected(): void
    {
        $this->actingAs($this->employee)
            ->post(route('my.requests.store'), $this->payload([
                'check_in_date' => now()->subDay()->format('Y-m-d'),
                'check_out_date' => now()->addDays(2)->format('Y-m-d'),
            ]))
            ->assertSessionHasErrors('check_in_date');
    }

    #[Test]
    public function the_database_refuses_an_invalid_date_range_even_if_validation_is_bypassed(): void
    {
        // The CHECK constraint is the backstop for a bad import or a future code
        // path that forgets to validate.
        $this->expectException(QueryException::class);

        \DB::table('booking_requests')->insert([
            'request_no' => 'REQ/9999/00001',
            'user_id' => $this->employee->id,
            'purpose' => 'SELF',
            'check_in_date' => '2026-10-10',
            'check_out_date' => '2026-10-08',   // before check-in
            'nights' => 1,
            'total_members' => 1,
            'rooms_needed' => 1,
            'contact_mobile' => '9876543210',
            'contact_email' => 'x@nadt.gov.in',
            'status' => 'DRAFT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_stay_longer_than_thirty_nights_needs_separate_sanction(): void
    {
        $this->actingAs($this->employee)
            ->post(route('my.requests.store'), $this->payload([
                'check_in_date' => now()->addDays(2)->format('Y-m-d'),
                'check_out_date' => now()->addDays(40)->format('Y-m-d'),
            ]))
            ->assertSessionHasErrors('check_out_date');
    }

    // ------------------------------------------------------- occupant matching

    #[Test]
    public function the_occupant_count_must_equal_the_declared_member_count(): void
    {
        $this->actingAs($this->employee)
            ->post(route('my.requests.store'), $this->payload([
                'total_members' => 3,   // but only one occupant listed
            ]))
            ->assertSessionHasErrors('occupants');

        $this->assertSame(0, BookingRequest::count());
    }

    #[Test]
    public function rooms_needed_is_derived_from_the_member_count(): void
    {
        $this->actingAs($this->employee)->post(route('my.requests.store'), $this->payload([
            'total_members' => 5,
            'occupants' => [
                ['name' => 'A', 'id_proof_type' => 'AADHAAR'],
                ['name' => 'B'], ['name' => 'C'], ['name' => 'D'], ['name' => 'E'],
            ],
        ]));

        // ceil(5 / 2) = 3 — a suggestion only; the admin may override.
        $this->assertSame(3, BookingRequest::firstOrFail()->rooms_needed);
    }

    // ------------------------------------------------------- purpose branching

    #[Test]
    public function a_training_visit_requires_the_programme_name(): void
    {
        $this->actingAs($this->employee)
            ->post(route('my.requests.store'), $this->payload([
                'purpose' => VisitPurpose::TRAINING->value,
            ]))
            ->assertSessionHasErrors('training_programme');
    }

    #[Test]
    public function a_guest_visit_requires_a_host_employee(): void
    {
        $this->actingAs($this->employee)
            ->post(route('my.requests.store'), $this->payload([
                'purpose' => VisitPurpose::GUEST->value,
            ]))
            ->assertSessionHasErrors('host_employee_id');
    }

    #[Test]
    public function fields_belonging_to_another_purpose_are_discarded(): void
    {
        // An applicant who fills in a programme name then switches to Self Visit
        // must not leave a stale value for a reviewer to puzzle over.
        $this->actingAs($this->employee)->post(route('my.requests.store'), $this->payload([
            'purpose' => VisitPurpose::SELF->value,
            'training_programme' => 'Direct Taxes Refresher',
            'guest_of_name' => 'Somebody',
        ]));

        $request = BookingRequest::firstOrFail();

        $this->assertNull($request->training_programme);
        $this->assertNull($request->guest_of_name);
    }

    // ------------------------------------------------------- guards

    #[Test]
    public function a_request_cannot_be_submitted_without_an_identity_proof(): void
    {
        $payload = $this->payload();
        unset($payload['documents']);

        $this->actingAs($this->employee)
            ->post(route('my.requests.store'), $payload)
            ->assertSessionHasErrors('documents');
    }

    #[Test]
    public function an_employee_with_no_reporting_manager_is_routed_to_the_manager(): void
    {
        // A single Manager handles every booking, so a missing reporting line
        // must not block submission.
        $orphan = User::factory()->role(RoleSlug::USER)->create(['reporting_manager_id' => null]);

        $this->actingAs($orphan)
            ->post(route('my.requests.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $request = BookingRequest::firstOrFail();
        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->status);
        $this->assertSame($this->manager->id, $request->manager_id);
    }

    #[Test]
    public function submission_is_refused_when_no_active_manager_exists(): void
    {
        // is_active is deliberately not mass-assignable.
        $this->manager->forceFill(['is_active' => false])->save();

        $this->actingAs($this->employee)
            ->post(route('my.requests.store'), $this->payload())
            ->assertSessionHasErrors('submit');

        // The transaction must roll back cleanly rather than leaving a stranded
        // draft with no queue to sit in.
        $this->assertSame(0, BookingRequest::count());
    }

    #[Test]
    public function an_adgs_own_request_is_routed_to_a_manager_who_can_review_it(): void
    {
        // Regression: the ADG (or anyone whose reporting line does not name a
        // Manager) raising their own booking used to be routed to a non-manager
        // and sat in a queue no Manager could open — invisible to the review
        // screen. It must instead reach an active Manager. (The Manager's own
        // bookings are approved immediately; see ApprovalChainTest.)
        $adg = User::where('id', $this->manager->reporting_manager_id)->firstOrFail();

        $this->actingAs($adg)
            ->post(route('my.requests.store'), $this->payload())
            ->assertRedirect();

        $request = BookingRequest::firstOrFail();

        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->status);

        $assignedManager = User::find($request->manager_id);
        $this->assertNotNull($assignedManager, 'The request must be assigned to a real user.');
        $this->assertTrue($assignedManager->isManager(), 'The assignee must hold the Manager role.');

        // And it must actually surface in a manager\'s review queue.
        $this->actingAs($assignedManager)
            ->get(route('manager.requests.index'))
            ->assertOk()
            ->assertSee($request->request_no);
    }

    #[Test]
    public function an_employee_cannot_view_another_employees_request(): void
    {
        $other = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();
        $request = BookingRequest::factory()->for_($other)->create();

        $this->actingAs($this->employee)
            ->get(route('my.requests.show', $request))
            ->assertForbidden();
    }

    #[Test]
    public function a_request_under_review_can_no_longer_be_edited(): void
    {
        $request = BookingRequest::factory()
            ->for_($this->employee)
            ->status(RequestStatus::PENDING_ADG)
            ->create();

        $this->actingAs($this->employee)
            ->get(route('my.requests.edit', $request))
            ->assertForbidden();
    }

    #[Test]
    public function a_request_returned_for_more_information_becomes_editable_again(): void
    {
        $request = BookingRequest::factory()
            ->for_($this->employee)
            ->status(RequestStatus::MORE_INFO_MANAGER)
            ->create();

        $this->actingAs($this->employee)
            ->get(route('my.requests.edit', $request))
            ->assertOk();
    }
}
