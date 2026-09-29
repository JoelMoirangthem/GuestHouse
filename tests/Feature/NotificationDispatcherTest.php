<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\DTOs\NotificationPayload;
use App\Application\Services\NotificationDispatcher;
use App\Domain\Contracts\NotificationChannel;
use App\Domain\Enums\NotificationEvent;
use App\Domain\Enums\RoleSlug;
use App\Models\BookingRequest;
use App\Models\EmailTemplate;
use App\Models\Notification;
use App\Models\RequestOccupant;
use App\Models\User;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A channel that records instead of delivering, so the whole suite can exercise
 * routing with zero real sends.
 */
class SpyChannel implements NotificationChannel
{
    /** @var array<int, NotificationPayload> */
    public static array $sent = [];

    public function __construct(private readonly string $key, private readonly bool $enabled = true) {}

    public function key(): string
    {
        return $this->key;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function send(NotificationPayload $payload): bool
    {
        self::$sent[] = $payload;

        return true;
    }
}

/**
 * TESTING.md — NotificationDispatcherTest. The Phase 7 gate.
 */
class NotificationDispatcherTest extends TestCase
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
        $this->seed(EmailTemplateSeeder::class);

        SpyChannel::$sent = [];

        // No real mail leaves the suite, whatever a channel tries to do.
        Mail::fake();

        $this->adg = User::factory()->role(RoleSlug::ADG)->create();
        $this->admin = User::factory()->role(RoleSlug::ADMIN)->create();
        $this->manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $this->employee = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();
    }

    private function spyDispatcher(): NotificationDispatcher
    {
        return new NotificationDispatcher([
            new SpyChannel('IN_APP'),
            new SpyChannel('EMAIL'),
            new SpyChannel('SMS', enabled: true),   // enabled here to test routing
        ]);
    }

    private function request(): BookingRequest
    {
        $r = BookingRequest::factory()
            ->for_($this->employee)
            ->pendingManager($this->manager)
            ->create();

        RequestOccupant::factory()->primary()->create([
            'booking_request_id' => $r->id,
            'name' => $this->employee->name,
        ]);

        return $r->refresh();
    }

    // ------------------------------------------------- every key has a template

    #[Test]
    public function every_event_key_has_a_seeded_email_template(): void
    {
        // Without this, an event silently falls back to generic copy in production.
        foreach (NotificationEvent::cases() as $event) {
            $this->assertDatabaseHas('email_templates', [
                'event_key' => $event->value,
                'is_active' => true,
            ]);
        }

        $this->assertSame(19, EmailTemplate::count(), 'Expected exactly 19 templates.');
        $this->assertSame(19, count(NotificationEvent::cases()));
    }

    #[Test]
    public function every_template_renders_without_leaving_raw_placeholders(): void
    {
        $tokens = [
            'user_name' => 'Rajesh Kumar', 'request_no' => 'REQ/2026/00001',
            'applicant_name' => 'Rajesh Kumar', 'purpose' => 'Self Visit',
            'check_in_date' => '10/11/2026', 'check_out_date' => '13/11/2026',
            'nights' => '3', 'total_members' => '1', 'status' => 'Allotted',
            'remarks' => 'Noted.', 'more_info_note' => 'Attach ID.', 'rooms' => '101',
            'action_url' => 'https://example.test/r/1', 'institution' => 'NADT',
            'building' => 'Pragya Bhawan', 'title' => 'Announcement', 'body' => 'Text.',
        ];

        foreach (EmailTemplate::all() as $template) {
            foreach (['subject', 'body_html', 'body_text'] as $field) {
                $rendered = EmailTemplate::render((string) $template->$field, $tokens);

                $this->assertStringNotContainsString('{{', $rendered,
                    "Template {$template->event_key}.{$field} left an unrendered placeholder.");
            }
        }
    }

    // ------------------------------------------------- PII must never travel

    #[Test]
    public function no_template_can_carry_an_aadhaar_number_or_a_document_link(): void
    {
        // SECURITY.md section 2 and NOTIFICATIONS.md section 4. Email and SMS reach
        // third-party carriers, so identity data must not be in them.
        foreach (EmailTemplate::all() as $t) {
            $all = strtolower($t->subject.' '.$t->body_html.' '.$t->body_text.' '.$t->sms_text);

            foreach (['aadhaar', 'id_proof', 'id proof number', '/documents/'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $all,
                    "Template {$t->event_key} references '{$forbidden}'.");
            }

            // No 12-digit run, which is the shape of an Aadhaar number.
            $this->assertDoesNotMatchRegularExpression('/\d{12}/', $all,
                "Template {$t->event_key} contains a 12-digit number.");
        }
    }

    #[Test]
    public function a_rendered_notification_body_contains_no_identity_number(): void
    {
        $request = $this->request();
        $request->occupants->first()->setIdProofNumber('432112345678');
        $request->occupants->first()->save();

        $this->spyDispatcher()->dispatch(NotificationEvent::ROOMS_ALLOTTED, $request);

        $this->assertNotEmpty(SpyChannel::$sent);

        foreach (SpyChannel::$sent as $payload) {
            foreach ([$payload->subject, $payload->bodyHtml, $payload->bodyText, (string) $payload->smsText] as $text) {
                $this->assertStringNotContainsString('432112345678', $text);
            }
        }
    }

    // ------------------------------------------------- channel routing

    /** @return array<string, array{0:NotificationEvent, 1:array<int,string>}> */
    public static function channelRouting(): array
    {
        return [
            'more info uses all three' => [NotificationEvent::REQUEST_MORE_INFO, ['EMAIL', 'SMS', 'IN_APP']],
            'rooms allotted uses all three' => [NotificationEvent::ROOMS_ALLOTTED, ['EMAIL', 'SMS', 'IN_APP']],
            'rejection uses all three' => [NotificationEvent::REQUEST_REJECTED, ['EMAIL', 'SMS', 'IN_APP']],
            'no room uses all three' => [NotificationEvent::ROOMS_NONE_AVAILABLE, ['EMAIL', 'SMS', 'IN_APP']],
            'checked in is in-app only' => [NotificationEvent::STAY_CHECKED_IN, ['IN_APP']],
            'submitted is email plus in-app' => [NotificationEvent::REQUEST_SUBMITTED, ['EMAIL', 'IN_APP']],
            'adg approval is email plus in-app' => [NotificationEvent::REQUEST_APPROVED_ADG, ['EMAIL', 'IN_APP']],
            'extension requested is email plus in-app' => [NotificationEvent::STAY_EXTENSION_REQUESTED, ['EMAIL', 'IN_APP']],
        ];
    }

    #[Test]
    #[DataProvider('channelRouting')]
    public function each_event_routes_to_its_specified_channels(NotificationEvent $event, array $expected): void
    {
        $this->assertEqualsCanonicalizing($expected, $event->channels());
    }

    #[Test]
    public function sms_is_reserved_for_action_needed_or_time_critical_events(): void
    {
        // Every SMS costs money, so the list is deliberately short.
        $withSms = array_values(array_filter(
            NotificationEvent::cases(),
            fn (NotificationEvent $e) => $e->usesSms(),
        ));

        $this->assertCount(8, $withSms);

        // Purely informational events must never cost an SMS.
        foreach ([NotificationEvent::REQUEST_SUBMITTED, NotificationEvent::STAY_CHECKED_IN,
            NotificationEvent::STAY_CHECKED_OUT, NotificationEvent::REQUEST_APPROVED_MANAGER] as $quiet) {
            $this->assertFalse($quiet->usesSms(), "{$quiet->value} must not send an SMS.");
        }
    }

    #[Test]
    public function a_disabled_channel_produces_no_notification_rows(): void
    {
        $dispatcher = new NotificationDispatcher([
            new SpyChannel('IN_APP'),
            new SpyChannel('EMAIL', enabled: false),
            new SpyChannel('SMS', enabled: false),
        ]);

        $dispatcher->dispatch(NotificationEvent::ROOMS_ALLOTTED, $this->request());

        $this->assertSame(0, Notification::where('channel', 'EMAIL')->count());
        $this->assertSame(0, Notification::where('channel', 'SMS')->count());
        $this->assertGreaterThan(0, Notification::where('channel', 'IN_APP')->count());
    }

    #[Test]
    public function sms_is_switched_off_in_this_deployment(): void
    {
        // No gateway has been chosen, and an Indian deployment needs DLT
        // registration before anything can be sent.
        $this->assertFalse((bool) config('gh.notify.sms'));
    }

    // ------------------------------------------------- recipient routing

    #[Test]
    public function a_submitted_request_notifies_the_reporting_manager(): void
    {
        $recipients = $this->spyDispatcher()
            ->recipientsFor(NotificationEvent::REQUEST_SUBMITTED, $this->request());

        $this->assertTrue($recipients->contains('id', $this->manager->id));
    }

    #[Test]
    public function adg_approval_notifies_the_administration_so_availability_can_be_checked(): void
    {
        $recipients = $this->spyDispatcher()
            ->recipientsFor(NotificationEvent::REQUEST_APPROVED_ADG, $this->request());

        $this->assertTrue($recipients->contains('id', $this->admin->id));
    }

    #[Test]
    public function an_extension_request_reaches_an_administrator(): void
    {
        // The gate requirement. Without this the request sits undecided forever —
        // a real hole in the first draft of the specification.
        $recipients = $this->spyDispatcher()
            ->recipientsFor(NotificationEvent::STAY_EXTENSION_REQUESTED, $this->request());

        $this->assertTrue($recipients->contains('id', $this->admin->id),
            'An extension request must reach an administrator.');
        $this->assertFalse($recipients->contains('id', $this->employee->id),
            'The applicant does not decide their own extension.');
    }

    #[Test]
    public function a_cancellation_reaches_the_requester_as_well_as_the_administration(): void
    {
        // An administrator may cancel on the guest's behalf; the guest must know.
        $recipients = $this->spyDispatcher()
            ->recipientsFor(NotificationEvent::REQUEST_CANCELLED, $this->request());

        $this->assertTrue($recipients->contains('id', $this->employee->id));
        $this->assertTrue($recipients->contains('id', $this->admin->id));
    }

    #[Test]
    public function a_normal_checkout_notifies_the_requester_so_feedback_can_begin(): void
    {
        $recipients = $this->spyDispatcher()
            ->recipientsFor(NotificationEvent::STAY_CHECKED_OUT, $this->request());

        $this->assertTrue($recipients->contains('id', $this->employee->id));
    }

    #[Test]
    public function every_event_resolves_to_at_least_one_recipient(): void
    {
        $request = $this->request();
        $dispatcher = $this->spyDispatcher();

        foreach (NotificationEvent::cases() as $event) {
            // Announcements are addressed explicitly by an administrator.
            if ($event === NotificationEvent::ANNOUNCEMENT_GENERAL) {
                continue;
            }

            $this->assertGreaterThan(
                0,
                $dispatcher->recipientsFor($event, $request)->count(),
                "Event {$event->value} would be sent to nobody."
            );
        }
    }

    // ------------------------------------------------- persistence

    #[Test]
    public function a_notification_row_is_written_before_delivery_is_attempted(): void
    {
        // So a mail outage leaves evidence that someone should have been told.
        $this->spyDispatcher()->dispatch(NotificationEvent::ROOMS_ALLOTTED, $this->request());

        $row = Notification::where('event_key', 'rooms.allotted')->first();

        $this->assertNotNull($row);
        $this->assertNotNull($row->action_url);
        $this->assertSame($this->employee->id, $row->user_id);
    }

    #[Test]
    public function the_action_url_is_role_appropriate_so_the_recipient_lands_where_they_can_act(): void
    {
        $request = $this->request();

        $this->spyDispatcher()->dispatch(NotificationEvent::REQUEST_SUBMITTED, $request);

        $managerRow = Notification::where('user_id', $this->manager->id)->first();
        $employeeRow = Notification::where('user_id', $this->employee->id)->first();

        $this->assertStringContainsString('/manager/requests/', (string) $managerRow->action_url);
        $this->assertStringContainsString('/my/requests/', (string) $employeeRow->action_url);
    }

    #[Test]
    public function no_real_mail_is_sent_during_the_suite(): void
    {
        $this->spyDispatcher()->dispatch(NotificationEvent::ROOMS_ALLOTTED, $this->request());

        Mail::assertNothingSent();
    }
}
