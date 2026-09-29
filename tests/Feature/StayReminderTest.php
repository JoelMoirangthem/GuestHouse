<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Services\StayService;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Models\Allotment;
use App\Models\BookingRequest;
use App\Models\Notification;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Scheduled stay reminders — NOTIFICATIONS.md events 9 and 10, the rest of the
 * Phase 7 gate: "each event produces the right notifications; zero real sends".
 */
class StayReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private RoomType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(EmailTemplateSeeder::class);
        Carbon::setTestNow('2026-10-10 07:30:00');

        $this->admin = User::factory()->role(RoleSlug::ADMIN)->create();
        $manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $this->employee = User::factory()->role(RoleSlug::USER)->reportingTo($manager)->create();
        $this->type = RoomType::factory()->create(['default_capacity' => 2]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function stay(RequestStatus $status, string $in, string $out, string $room = '101'): BookingRequest
    {
        $r = BookingRequest::factory()->for_($this->employee)->status($status)->members(1)->dates($in, $out)->create();
        $rm = Room::factory()->ofType($this->type)->number($room)->create();
        Allotment::factory()->forRoom($rm)->dates($in, $out)->create([
            'booking_request_id' => $r->id,
            'status' => in_array($status, [RequestStatus::CHECKED_IN, RequestStatus::EXTENSION_REQUESTED], true) ? 'CHECKED_IN' : 'ALLOTTED',
        ]);

        return $r;
    }

    /** @return \Illuminate\Support\Collection<int, Notification> */
    private function sent(string $event, BookingRequest $r)
    {
        return Notification::where('event_key', $event)->where('booking_request_id', $r->id)->get();
    }

    // ============================================================ check-in

    #[Test]
    public function an_allotted_guest_arriving_tomorrow_is_reminded_on_every_channel(): void
    {
        $r = $this->stay(RequestStatus::ALLOTTED, '2026-10-11', '2026-10-13', '204');

        $this->artisan('gh:stay-reminders')
            ->expectsOutputToContain('1 check-in, 0 check-out')
            ->assertSuccessful();

        $rows = $this->sent('stay.checkin_reminder', $r);
        // Email + in-app; SMS is switched off in this deployment (config gh.notify.sms).
        $this->assertEqualsCanonicalizing(['EMAIL', 'IN_APP'], $rows->pluck('channel')->all());
        $this->assertSame([$this->employee->id], $rows->pluck('user_id')->unique()->values()->all());

        $email = $rows->firstWhere('channel', 'EMAIL');
        $this->assertStringContainsString('11/10/2026', $email->body);
        $this->assertStringContainsString('204', $email->body, 'The room number placeholder must be filled.');
        $this->assertSame(route('my.requests.show', $r->id), $email->action_url);
    }

    /** @return array<string, array{0: RequestStatus, 1: string}> */
    public static function noCheckinReminder(): array
    {
        return [
            'arrives today, too late' => [RequestStatus::ALLOTTED, '2026-10-10'],
            'arrives in two days, too early' => [RequestStatus::ALLOTTED, '2026-10-12'],
            'only partly allotted (cannot check in)' => [RequestStatus::PARTIALLY_ALLOTTED, '2026-10-11'],
            'cancelled' => [RequestStatus::CANCELLED, '2026-10-11'],
            'still awaiting a room' => [RequestStatus::PENDING_ALLOTMENT, '2026-10-11'],
        ];
    }

    #[Test]
    #[DataProvider('noCheckinReminder')]
    public function no_checkin_reminder_is_sent_to_a_request_that_does_not_qualify(RequestStatus $status, string $in): void
    {
        $r = $this->stay($status, $in, Carbon::parse($in)->addDays(2)->format('Y-m-d'));

        $this->artisan('gh:stay-reminders')->assertSuccessful();

        $this->assertCount(0, $this->sent('stay.checkin_reminder', $r));
    }

    // =========================================================== check-out

    #[Test]
    public function a_guest_due_to_leave_today_is_reminded(): void
    {
        $r = $this->stay(RequestStatus::CHECKED_IN, '2026-10-08', '2026-10-10');
        $ext = $this->stay(RequestStatus::EXTENSION_REQUESTED, '2026-10-08', '2026-10-10', '102');
        $notYet = $this->stay(RequestStatus::CHECKED_IN, '2026-10-08', '2026-10-11', '103');

        $this->artisan('gh:stay-reminders')->expectsOutputToContain('0 check-in, 2 check-out')->assertSuccessful();

        $this->assertCount(2, $this->sent('stay.checkout_reminder', $r));
        // An undecided extension does not move the date, so the guest still needs the reminder.
        $this->assertCount(2, $this->sent('stay.checkout_reminder', $ext));
        $this->assertCount(0, $this->sent('stay.checkout_reminder', $notYet));
    }

    // ========================================================= idempotency

    #[Test]
    public function running_twice_on_the_same_day_sends_nothing_twice(): void
    {
        $in = $this->stay(RequestStatus::ALLOTTED, '2026-10-11', '2026-10-13');
        $out = $this->stay(RequestStatus::CHECKED_IN, '2026-10-08', '2026-10-10', '102');

        $this->artisan('gh:stay-reminders')->assertSuccessful();
        Carbon::setTestNow('2026-10-10 16:00:00');
        $this->artisan('gh:stay-reminders')
            ->expectsOutputToContain('0 check-in, 0 check-out, 2 already sent')
            ->assertSuccessful();

        $this->assertCount(2, $this->sent('stay.checkin_reminder', $in));
        $this->assertCount(2, $this->sent('stay.checkout_reminder', $out));
    }

    #[Test]
    public function an_extended_stay_is_reminded_again_on_its_new_departure_date(): void
    {
        // Reminded on the original day; extension approved; reminded on the new day.
        $room = Room::factory()->ofType($this->type)->number('301')->create();
        $r = BookingRequest::factory()->for_($this->employee)->status(RequestStatus::ALLOTTED)->members(1)->dates('2026-10-08', '2026-10-10')->create();
        Allotment::factory()->forRoom($room)->dates('2026-10-08', '2026-10-10')->create(['booking_request_id' => $r->id]);

        Carbon::setTestNow('2026-10-08 12:00:00');
        app(StayService::class)->checkIn($r, $this->admin);

        Carbon::setTestNow('2026-10-10 07:30:00');
        $this->artisan('gh:stay-reminders')->assertSuccessful();
        $this->assertCount(2, $this->sent('stay.checkout_reminder', $r));

        $ext = app(StayService::class)->requestExtension($r->fresh(), $this->employee, '2026-10-12', 'Programme extended');
        app(StayService::class)->approveExtension($ext, $this->admin);
        $this->assertSame('2026-10-12', $r->fresh()->check_out_date->format('Y-m-d'));

        Carbon::setTestNow('2026-10-11 07:30:00');
        $this->artisan('gh:stay-reminders')->assertSuccessful();
        $this->assertCount(2, $this->sent('stay.checkout_reminder', $r), 'Nothing on a day that is not a departure day.');

        Carbon::setTestNow('2026-10-12 07:30:00');
        $this->artisan('gh:stay-reminders')->assertSuccessful();
        $this->assertCount(4, $this->sent('stay.checkout_reminder', $r));
    }

    // ============================================================ scheduling

    #[Test]
    public function the_command_is_scheduled_daily_in_the_morning(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'gh:stay-reminders'));

        $this->assertNotNull($event, 'gh:stay-reminders is not on the schedule.');
        $this->assertSame('30 7 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    #[Test]
    public function a_missed_day_can_be_caught_up_with_the_date_option(): void
    {
        $r = $this->stay(RequestStatus::ALLOTTED, '2026-10-16', '2026-10-18');

        $this->artisan('gh:stay-reminders', ['--date' => '2026-10-15'])
            ->expectsOutputToContain('Reminders for 15/10/2026: 1 check-in')
            ->assertSuccessful();
        $this->assertCount(2, $this->sent('stay.checkin_reminder', $r));

        $this->artisan('gh:stay-reminders', ['--date' => '15-10-2026'])->assertFailed();
    }

    #[Test]
    public function reminders_never_carry_an_identity_number_and_no_real_mail_leaves(): void
    {
        $r = $this->stay(RequestStatus::ALLOTTED, '2026-10-11', '2026-10-13');
        $o = \App\Models\RequestOccupant::factory()->create(['booking_request_id' => $r->id, 'id_proof_type' => 'AADHAAR']);
        $o->setIdProofNumber('9876 5432 1098');
        $o->save();

        $this->artisan('gh:stay-reminders')->assertSuccessful();

        foreach ($this->sent('stay.checkin_reminder', $r) as $row) {
            $this->assertStringNotContainsString('987654321098', $row->body);
            $this->assertStringNotContainsString('9876 5432 1098', $row->body);
        }

        Mail::assertNothingOutgoing();
    }
}
