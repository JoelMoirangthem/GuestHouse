<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Services\FeedbackService;
use App\Application\Services\StayService;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Models\Allotment;
use App\Models\AuditLog;
use App\Models\BookingRequest;
use App\Models\Feedback;
use App\Models\Notification;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Post-stay feedback — workflow step 7, SCHEMA.md section 13, WORKFLOW.md section 3.
 */
class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $employee;

    private const ANSWERS = [
        'rating_cleanliness' => 4,
        'rating_staff' => 5,
        'rating_facilities' => 3,
        'rating_overall' => 4,
        'comments' => 'Clean rooms; the geyser in 204 was slow.',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow('2026-10-10 11:00:00');

        $this->admin = User::factory()->role(RoleSlug::ADMIN)->create();
        $this->manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $this->employee = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function stay(RequestStatus $status, ?User $owner = null): BookingRequest
    {
        return BookingRequest::factory()->for_($owner ?? $this->employee)->status($status)
            ->dates('2026-10-07', '2026-10-10')->create();
    }

    // ============================================================ happy path

    #[Test]
    public function after_checkout_the_guest_can_rate_the_stay_once(): void
    {
        $request = $this->stay(RequestStatus::CHECKED_OUT);

        $this->actingAs($this->employee)
            ->get(route('my.requests.show', $request))
            ->assertOk()
            ->assertSee(route('my.requests.feedback', $request), false);

        $this->get(route('my.requests.feedback', $request))->assertOk()->assertSee('Cleanliness');

        $this->post(route('my.requests.feedback.store', $request), self::ANSWERS)
            ->assertRedirect(route('my.requests.show', $request))
            ->assertSessionHasNoErrors();

        $fb = Feedback::where('booking_request_id', $request->id)->firstOrFail();
        $this->assertSame($this->employee->id, $fb->user_id);
        $this->assertSame([4, 5, 3, 4], [$fb->rating_cleanliness, $fb->rating_staff, $fb->rating_facilities, $fb->rating_overall]);
        $this->assertNotNull($fb->submitted_at);

        // Feedback is not a transition: the request stays terminal and unchanged.
        $this->assertSame(RequestStatus::CHECKED_OUT, $request->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'FEEDBACK_SUBMITTED')->where('auditable_id', $request->id)->exists());

        // The request page now shows the recorded ratings instead of the button.
        $this->get(route('my.requests.show', $request))
            ->assertOk()
            ->assertSee('5 / 5')
            ->assertDontSee(route('my.requests.feedback', $request), false);
    }

    #[Test]
    public function an_early_checkout_can_also_be_rated(): void
    {
        $request = $this->stay(RequestStatus::EARLY_CHECKOUT);

        $this->actingAs($this->employee)
            ->post(route('my.requests.feedback.store', $request), self::ANSWERS)
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Feedback::count());
    }

    #[Test]
    public function the_administration_sees_the_feedback_on_the_allotment_page(): void
    {
        $request = $this->stay(RequestStatus::CHECKED_OUT);
        app(FeedbackService::class)->submit($request, $this->employee, self::ANSWERS);

        $this->actingAs($this->admin)
            ->get(route('admin.allotments.show', $request))
            ->assertOk()
            ->assertSee('Guest feedback')
            ->assertSee('the geyser in 204 was slow');
    }

    // ======================================================= refusals

    #[Test]
    public function a_second_submission_is_refused_and_the_first_stands(): void
    {
        $request = $this->stay(RequestStatus::CHECKED_OUT);
        $this->actingAs($this->employee);

        $this->post(route('my.requests.feedback.store', $request), self::ANSWERS)->assertSessionHasNoErrors();
        $this->post(route('my.requests.feedback.store', $request), [...self::ANSWERS, 'rating_overall' => 1])
            ->assertSessionHasErrors('feedback');

        $this->assertSame(1, Feedback::count());
        $this->assertSame(4, Feedback::first()->rating_overall);

        // Revisiting the form just sends the guest back.
        $this->get(route('my.requests.feedback', $request))->assertRedirect(route('my.requests.show', $request));
    }

    #[Test]
    public function the_database_refuses_a_duplicate_even_if_the_service_is_bypassed(): void
    {
        $request = $this->stay(RequestStatus::CHECKED_OUT);
        app(FeedbackService::class)->submit($request, $this->employee, self::ANSWERS);

        $this->expectException(QueryException::class);
        DB::table('feedback')->insert([
            'booking_request_id' => $request->id, 'user_id' => $this->employee->id,
            'rating_cleanliness' => 1, 'rating_staff' => 1, 'rating_facilities' => 1, 'rating_overall' => 1,
            'submitted_at' => now(),
        ]);
    }

    #[Test]
    public function the_database_refuses_a_rating_outside_one_to_five(): void
    {
        $request = $this->stay(RequestStatus::CHECKED_OUT);

        $this->expectException(QueryException::class);
        DB::table('feedback')->insert([
            'booking_request_id' => $request->id, 'user_id' => $this->employee->id,
            'rating_cleanliness' => 6, 'rating_staff' => 1, 'rating_facilities' => 1, 'rating_overall' => 1,
            'submitted_at' => now(),
        ]);
    }

    /** @return array<string, array{0: RequestStatus}> */
    public static function notYetRateable(): array
    {
        return [
            'pending manager' => [RequestStatus::PENDING_MANAGER],
            'allotted' => [RequestStatus::ALLOTTED],
            'in residence' => [RequestStatus::CHECKED_IN],
            'extension pending' => [RequestStatus::EXTENSION_REQUESTED],
            'cancelled' => [RequestStatus::CANCELLED],
            'rejected' => [RequestStatus::REJECTED_ADG],
            'no room' => [RequestStatus::NO_ROOM_AVAILABLE],
        ];
    }

    #[Test]
    #[DataProvider('notYetRateable')]
    public function feedback_is_refused_until_the_stay_is_complete(RequestStatus $status): void
    {
        $request = $this->stay($status);
        $this->actingAs($this->employee);

        $this->get(route('my.requests.feedback', $request))->assertForbidden();
        $this->post(route('my.requests.feedback.store', $request), self::ANSWERS)->assertForbidden();

        $this->assertSame(0, Feedback::count());
    }

    #[Test]
    public function nobody_but_the_applicant_may_submit_it(): void
    {
        $request = $this->stay(RequestStatus::CHECKED_OUT);
        $other = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();

        // Including the reporting manager and the administrator, who can both
        // VIEW the request: rating it on the guest's behalf would falsify the report.
        foreach ([$other, $this->manager, $this->admin, User::factory()->role(RoleSlug::ADG)->create()] as $actor) {
            $this->actingAs($actor)->get(route('my.requests.feedback', $request))->assertForbidden();
            $this->actingAs($actor)->post(route('my.requests.feedback.store', $request), self::ANSWERS)->assertForbidden();
        }

        $this->assertSame(0, Feedback::count());
    }

    #[Test]
    public function every_rating_is_required_and_must_be_one_to_five(): void
    {
        $request = $this->stay(RequestStatus::CHECKED_OUT);
        $this->actingAs($this->employee);

        $this->post(route('my.requests.feedback.store', $request), ['comments' => 'nice'])
            ->assertSessionHasErrors(['rating_cleanliness', 'rating_staff', 'rating_facilities', 'rating_overall']);

        $this->post(route('my.requests.feedback.store', $request), [...self::ANSWERS, 'rating_staff' => 0, 'rating_overall' => 6])
            ->assertSessionHasErrors(['rating_staff', 'rating_overall']);

        $this->post(route('my.requests.feedback.store', $request), [...self::ANSWERS, 'comments' => str_repeat('a', 2001)])
            ->assertSessionHasErrors('comments');

        $this->assertSame(0, Feedback::count());
    }

    #[Test]
    public function extra_fields_cannot_attach_the_feedback_to_another_request_or_user(): void
    {
        $mine = $this->stay(RequestStatus::CHECKED_OUT);
        $someoneElses = $this->stay(RequestStatus::CHECKED_OUT, User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create());

        $this->actingAs($this->employee)->post(route('my.requests.feedback.store', $mine), [
            ...self::ANSWERS, 'booking_request_id' => $someoneElses->id, 'user_id' => $this->admin->id,
        ])->assertSessionHasNoErrors();

        $fb = Feedback::firstOrFail();
        $this->assertSame($mine->id, $fb->booking_request_id);
        $this->assertSame($this->employee->id, $fb->user_id);
    }

    // =========================================== end to end, through checkout

    #[Test]
    public function the_checkout_notification_links_the_guest_straight_to_the_feedback_form(): void
    {
        $this->seed(EmailTemplateSeeder::class);
        $type = RoomType::factory()->create(['default_capacity' => 2]);
        $room = Room::factory()->ofType($type)->create();
        $request = BookingRequest::factory()->for_($this->employee)->status(RequestStatus::ALLOTTED)
            ->members(1)->dates('2026-10-10', '2026-10-12')->create();
        Allotment::factory()->forRoom($room)->dates('2026-10-10', '2026-10-12')->create(['booking_request_id' => $request->id]);

        app(StayService::class)->checkIn($request, $this->admin);
        Carbon::setTestNow('2026-10-12 10:00:00');
        app(StayService::class)->checkOut($request->fresh(), $this->admin);

        $this->assertSame(RequestStatus::CHECKED_OUT, $request->fresh()->status);

        $row = Notification::where('user_id', $this->employee->id)->where('event_key', 'stay.checked_out')->firstOrFail();
        $this->assertSame(route('my.requests.feedback', $request->id), $row->action_url);

        // Following the link works.
        $this->actingAs($this->employee)->get($row->action_url)->assertOk();
    }

    #[Test]
    public function a_manager_who_booked_for_themselves_is_linked_to_their_own_request_not_the_review_queue(): void
    {
        // Regression: the deep link used to be chosen by role alone, sending a
        // Manager-applicant to /manager/requests/{id} for their OWN booking.
        $this->seed(EmailTemplateSeeder::class);
        $senior = User::factory()->role(RoleSlug::MANAGER)->create();
        $this->manager->forceFill(['reporting_manager_id' => $senior->id])->save();
        $request = $this->stay(RequestStatus::ALLOTTED, $this->manager);

        app(\App\Application\Services\NotificationDispatcher::class)
            ->dispatch(\App\Domain\Enums\NotificationEvent::ROOMS_ALLOTTED, $request);

        $row = Notification::where('user_id', $this->manager->id)->firstOrFail();
        $this->assertSame(route('my.requests.show', $request->id), $row->action_url);
    }
}
