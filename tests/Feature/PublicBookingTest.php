<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Models\BookingRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The public "Room & Stay Details" requisition on the landing page.
 */
class PublicBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('private');
        RateLimiter::clear('public-booking:GH-EMP-900');

        $adg = User::factory()->role(RoleSlug::ADG)->create();
        $this->manager = User::factory()->role(RoleSlug::MANAGER)->create(['reporting_manager_id' => $adg->id]);
        $this->employee = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create([
            'employee_code' => 'GH-EMP-900',
            'mobile' => '9876500001',
            'email' => 'employee900@nadt.gov.in',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'employee_code' => 'GH-EMP-900',
            'contact_mobile' => '9876500001',
            'purpose' => 'SELF',
            'id_type' => 'OFFICE_ID',
            'id_card' => UploadedFile::fake()->create('employee-id.pdf', 120, 'application/pdf'),
            'rooms' => 2,
            'check_in_date' => now()->addDays(3)->format('Y-m-d'),
            'check_in_time' => '14:00',
            'check_out_date' => now()->addDays(5)->format('Y-m-d'),
            'check_out_time' => '11:00',
            'terms_accepted' => '1',
        ], $overrides);
    }

    #[Test]
    public function the_form_shows_the_terms_and_conditions_before_final_submit(): void
    {
        $this->get(route('public.booking'))
            ->assertOk()
            ->assertSee('TERMS &amp; CONDITIONS', false)
            ->assertSee('Only married couples may occupy a room together.')
            ->assertSee('₹600 per room for training-related stay and ₹900 per room for other stays.')
            ->assertSee('please allow at least 12 hours for processing')
            ->assertSee('I certify that the information furnished above is correct')
            ->assertSee('Agreed for Terms &amp; Conditions', false)
            ->assertSee('name="terms_accepted"', false)
            ->assertSee('Final Submit')
            ->assertSeeInOrder(['9918143306', '9415984377', '8477862418']);
    }

    #[Test]
    public function a_submission_without_accepting_the_terms_still_succeeds(): void
    {
        // DEMO MODE: terms are no longer a blocking requirement.
        $this->post(route('public.booking.store'), $this->payload(['terms_accepted' => null]))
            ->assertRedirect(route('public.booking.submitted'));

        $this->assertSame(1, BookingRequest::count());
        $this->assertSame(RequestStatus::PENDING_MANAGER, BookingRequest::sole()->status);
    }

    #[Test]
    public function an_anonymous_employee_can_submit_and_it_enters_the_approval_chain(): void
    {
        $this->post(route('public.booking.store'), $this->payload())
            ->assertRedirect(route('public.booking.submitted'));

        $this->assertGuest();

        $request = BookingRequest::with(['occupants', 'documents'])->sole();

        $this->assertSame($this->employee->id, $request->user_id);
        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->status);
        $this->assertSame($this->manager->id, $request->manager_id);
        $this->assertSame(2, $request->rooms_needed);
        $this->assertSame(2, $request->nights);
        $this->assertStringStartsWith('14:00', (string) $request->check_in_time);
        $this->assertStringStartsWith('11:00', (string) $request->check_out_time);
        // Contact email comes from the account, not from the anonymous form.
        $this->assertSame('employee900@nadt.gov.in', $request->contact_email);

        $this->assertSame($this->employee->name, $request->occupants->sole()->name);
        $this->assertTrue($request->occupants->sole()->is_primary);
        $this->assertSame('OFFICE_ID', $request->documents->sole()->doc_type);
    }

    #[Test]
    public function the_confirmation_page_shows_the_request_number_once(): void
    {
        $this->post(route('public.booking.store'), $this->payload());
        $requestNo = BookingRequest::sole()->request_no;

        $this->get(route('public.booking.submitted'))->assertOk()->assertSee($requestNo);

        // Flash data only: a later visit reveals nothing.
        $this->get(route('public.booking.submitted'))->assertRedirect(route('public.booking'));
    }

    #[Test]
    public function the_submitted_request_appears_in_the_managers_queue(): void
    {
        $this->post(route('public.booking.store'), $this->payload());

        $this->actingAs($this->manager)
            ->get(route('manager.requests.index'))
            ->assertOk()
            ->assertSee(BookingRequest::sole()->request_no);
    }

    #[Test]
    public function a_guest_visit_books_for_the_guest_with_the_employee_as_host(): void
    {
        $this->post(route('public.booking.store'), $this->payload([
            'purpose' => 'GUEST',
            'guest_name' => 'Meera Nair',
            'id_type' => 'OFFICE_ID',
        ]))->assertRedirect(route('public.booking.submitted'));

        $request = BookingRequest::with(['occupants', 'documents'])->sole();

        $this->assertSame($this->employee->id, $request->host_employee_id);
        $this->assertSame('Meera Nair', $request->occupants->sole()->name);
        $this->assertSame('OFFICE_ID', $request->documents->sole()->doc_type);
    }

    #[Test]
    public function purpose_specific_fields_are_defaulted_and_never_block_submission(): void
    {
        // DEMO MODE: a training visit with no programme name still submits; the
        // controller supplies a default so the request always reaches review.
        $this->post(route('public.booking.store'), $this->payload(['purpose' => 'TRAINING']))
            ->assertRedirect(route('public.booking.submitted'));

        $this->assertSame(RequestStatus::PENDING_MANAGER, BookingRequest::sole()->status);
        $this->assertNotEmpty(BookingRequest::sole()->training_programme);
    }

    #[Test]
    public function an_empty_submission_defaults_everything_and_still_reaches_review(): void
    {
        // The extreme demo case: nothing filled in at all. It must still create
        // a request and land in the Manager's queue.
        $this->post(route('public.booking.store'), [
            'purpose' => null,
            'rooms' => null,
            'check_in_date' => null,
            'check_out_date' => null,
        ])->assertRedirect(route('public.booking.submitted'));

        $request = BookingRequest::sole();
        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->status);
        $this->assertNotNull($request->user_id);
        $this->assertSame(1, $request->rooms_needed);
        $this->assertGreaterThan(0, $request->nights);
    }

    #[Test]
    public function a_valid_employee_id_with_a_mismatched_mobile_is_rejected(): void
    {
        // When an Employee ID is given it must match its own registered mobile.
        $this->post(route('public.booking.store'), $this->payload(['contact_mobile' => '9999999999']))
            ->assertSessionHasErrors('submit');

        $this->assertSame(0, BookingRequest::count());
    }

    #[Test]
    public function employee_id_is_optional_and_the_mobile_alone_resolves_the_account(): void
    {
        // No Employee ID at all — the registered mobile identifies the employee.
        $this->post(route('public.booking.store'), $this->payload([
            'employee_code' => null,
            'contact_mobile' => '9876500001',
            'id_card' => null,
        ]))->assertRedirect(route('public.booking.submitted'));

        $request = BookingRequest::sole();
        $this->assertSame($this->employee->id, $request->user_id);
        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->status);
    }

    #[Test]
    public function a_requisition_with_no_identity_at_all_still_files_under_a_default_account(): void
    {
        // Demo / walk-in path: neither Employee ID nor mobile given. It must
        // still complete end to end so a request always reaches the queue.
        $this->post(route('public.booking.store'), $this->payload([
            'employee_code' => null,
            'contact_mobile' => null,
            'id_card' => null,
        ]))->assertRedirect(route('public.booking.submitted'));

        $request = BookingRequest::sole();
        $this->assertNotNull($request->user_id);
        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->status);
        // Filed under the only active employee who may raise requests.
        $this->assertSame($this->employee->id, $request->user_id);
    }

    #[Test]
    public function an_inactive_employee_cannot_submit(): void
    {
        // The sole employee is inactive, so there is no active account to file
        // under and the requisition is refused rather than silently reassigned.
        $this->employee->forceFill(['is_active' => false])->save();

        $this->post(route('public.booking.store'), $this->payload())->assertSessionHasErrors('submit');

        $this->assertSame(0, BookingRequest::count());
    }

    #[Test]
    public function repeated_failed_matches_lock_the_employee_id(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('public.booking.store'), $this->payload(['contact_mobile' => '9000000000']));
        }

        // Even the correct mobile is refused while locked out.
        $this->post(route('public.booking.store'), $this->payload())
            ->assertSessionHasErrors(['submit' => 'Too many unsuccessful attempts. Try again in 10 minute(s).']);

        $this->assertSame(0, BookingRequest::count());
    }

    #[Test]
    public function the_id_card_is_optional_and_bad_stay_values_are_defaulted_not_rejected(): void
    {
        // DEMO MODE: no field is validated. Missing/invalid stay values are
        // defaulted by the controller, and the request still submits.
        $this->post(route('public.booking.store'), $this->payload([
            'id_card' => null,
            'rooms' => 0,
            'check_in_date' => now()->subDay()->format('Y-m-d'),
            'check_in_time' => '',
        ]))->assertRedirect(route('public.booking.submitted'));

        $request = BookingRequest::sole();
        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->status);
        $this->assertSame(1, $request->rooms_needed);   // 0 -> defaulted to 1
        $this->assertGreaterThan(0, $request->nights);
    }

    #[Test]
    public function a_personal_visit_can_submit_without_an_id_card(): void
    {
        $this->post(route('public.booking.store'), $this->payload(['id_card' => null]))
            ->assertRedirect(route('public.booking.submitted'));

        $request = BookingRequest::with('documents')->sole();

        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->status);
        $this->assertTrue($request->documents->isEmpty());
    }

    #[Test]
    public function a_personal_visit_can_record_an_explicit_employee_name(): void
    {
        $this->post(route('public.booking.store'), $this->payload([
            'purpose' => 'SELF',
            'employee_name' => 'Ravi Kumar',
            'id_card' => null,
        ]))->assertRedirect(route('public.booking.submitted'));

        $request = BookingRequest::with('occupants')->sole();

        $this->assertSame('Ravi Kumar', $request->occupants->sole()->name);
    }

    #[Test]
    public function viewing_requests_still_requires_sign_in(): void
    {
        $this->post(route('public.booking.store'), $this->payload());

        $this->get(route('my.requests.show', BookingRequest::sole()))->assertRedirect(route('login'));
        $this->get(route('my.requests.index'))->assertRedirect(route('login'));
    }
}
