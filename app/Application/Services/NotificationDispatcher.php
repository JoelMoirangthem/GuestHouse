<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\NotificationPayload;
use App\Domain\Contracts\NotificationChannel;
use App\Domain\Enums\NotificationEvent;
use App\Domain\Enums\RoleSlug;
use App\Models\BookingRequest;
use App\Models\EmailTemplate;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns a workflow event into delivered notifications.
 *
 * Responsibilities, in order: resolve the recipients, render the template, write
 * a notifications row per channel, then hand each row to its channel.
 *
 * The row is written BEFORE delivery is attempted. That ordering matters: if the
 * mail server is down, there is still a record that the recipient should have been
 * told, and the in-app bell will show it regardless.
 */
class NotificationDispatcher
{
    /** @var array<string, NotificationChannel> */
    private array $channels = [];

    /**
     * @param  iterable<NotificationChannel>  $channels
     */
    public function __construct(iterable $channels)
    {
        foreach ($channels as $channel) {
            $this->channels[$channel->key()] = $channel;
        }
    }

    /**
     * Dispatch one event about one request.
     *
     * @param  array<string, string|int|null>  $extraTokens
     * @return int number of notification rows created
     */
    public function dispatch(
        NotificationEvent $event,
        ?BookingRequest $request = null,
        array $extraTokens = [],
        ?User $onlyTo = null,
    ): int {
        // Notifications are switched off for this installation (config/gh.php).
        // Nothing is sent and no bell entries are written.
        if (! config('gh.notifications_enabled')) {
            return 0;
        }

        $recipients = $onlyTo !== null
            ? collect([$onlyTo])
            : $this->recipientsFor($event, $request);

        if ($recipients->isEmpty()) {
            return 0;
        }

        $template = EmailTemplate::where('event_key', $event->value)->where('is_active', true)->first();
        $created = 0;

        foreach ($recipients->unique('id') as $recipient) {
            $tokens = $this->tokensFor($event, $recipient, $request, $extraTokens);

            $subject = EmailTemplate::render($template->subject ?? $event->defaultSubject(), $tokens);
            $html = EmailTemplate::render($template->body_html ?? $this->fallbackHtml($event), $tokens);
            $text = EmailTemplate::render($template->body_text ?? strip_tags($this->fallbackHtml($event)), $tokens);
            $sms = $template?->sms_text ? EmailTemplate::render($template->sms_text, $tokens) : null;

            foreach ($event->channels() as $channelKey) {
                $channel = $this->channels[$channelKey] ?? null;

                if ($channel === null || ! $channel->isEnabled()) {
                    continue;
                }

                $row = Notification::create([
                    'user_id' => $recipient->id,
                    'booking_request_id' => $request?->id,
                    'event_key' => $event->value,
                    'channel' => $channelKey,
                    'title' => $event->bellTitle(),
                    'body' => $channelKey === 'IN_APP' ? $this->bellBody($event, $request, $tokens) : $text,
                    'action_url' => $tokens['action_url'] ?? null,
                    'status' => 'PENDING',
                ]);

                $created++;

                // send() is contractually forbidden from throwing, so one broken
                // channel cannot stop the others.
                $channel->send(
                    (new NotificationPayload(
                        recipient: $recipient,
                        event: $event,
                        subject: $subject,
                        bodyHtml: $html,
                        bodyText: $text,
                        smsText: $sms,
                        actionUrl: $tokens['action_url'] ?? null,
                        request: $request,
                        tokens: $tokens,
                    ))->withNotificationId($row->id)
                );
            }
        }

        return $created;
    }

    /**
     * Who is told about each event — NOTIFICATIONS.md section 2.
     *
     * @return Collection<int, User>
     */
    public function recipientsFor(NotificationEvent $event, ?BookingRequest $request): Collection
    {
        $requester = $request?->requester;
        $manager = $request?->manager ?? $requester?->reportingManager;

        return match ($event) {
            // Manager must act.
            NotificationEvent::REQUEST_SUBMITTED,
            NotificationEvent::REQUEST_RESUBMITTED => collect([$manager, $requester])->filter(),

            // Applicant must act.
            NotificationEvent::REQUEST_MORE_INFO,
            NotificationEvent::STAY_CHECKIN_REMINDER,
            NotificationEvent::STAY_CHECKOUT_REMINDER,
            NotificationEvent::STAY_CHECKED_IN,
            NotificationEvent::STAY_EXTENDED,
            NotificationEvent::STAY_EXTENSION_DENIED,
            NotificationEvent::STAY_CHECKED_OUT,
            NotificationEvent::AVAILABILITY_RECHECK => collect([$requester])->filter(),

            // ADG must act next.
            NotificationEvent::REQUEST_APPROVED_MANAGER => $this->role(RoleSlug::ADG)->push($requester)->filter(),

            // Administration must act next: check availability.
            NotificationEvent::REQUEST_APPROVED_ADG => $this->role(RoleSlug::ADMIN)->push($requester)->filter(),

            // Administration must decide the extension. Without this the request
            // sits undecided forever, which was a real hole in the first draft.
            NotificationEvent::STAY_EXTENSION_REQUESTED => $this->role(RoleSlug::ADMIN),

            // A rejection concerns the applicant and the manager who forwarded it.
            NotificationEvent::REQUEST_REJECTED => collect([$requester, $manager])->filter(),

            // Nothing free: the applicant and the authority that approved it.
            NotificationEvent::ROOMS_NONE_AVAILABLE => $this->role(RoleSlug::ADG)->push($requester)->filter(),

            NotificationEvent::ROOMS_ALLOTTED => collect([$requester])->filter(),

            NotificationEvent::STAY_EARLY_CHECKOUT => $this->role(RoleSlug::ADMIN)->push($requester)->filter(),

            // The requester is included deliberately: an administrator may cancel
            // on their behalf, and the guest must not be left unaware.
            NotificationEvent::REQUEST_CANCELLED => $this->role(RoleSlug::ADMIN)
                ->merge(collect([$requester, $manager])->filter()),

            NotificationEvent::ANNOUNCEMENT_GENERAL => collect(),
        };
    }

    /**
     * @return Collection<int, User>
     */
    private function role(RoleSlug $slug): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', $slug->value))
            ->get();
    }

    /**
     * Placeholder values — NOTIFICATIONS.md section 3.
     *
     * NOTE what is absent: no Aadhaar number, no identity-proof number, and no
     * document link. Notifications carry a login-protected action URL instead,
     * because an email or SMS is the last place sensitive PII should travel
     * (SECURITY.md section 2).
     *
     * @param  array<string, string|int|null>  $extra
     * @return array<string, string|int|null>
     */
    private function tokensFor(
        NotificationEvent $event,
        User $recipient,
        ?BookingRequest $request,
        array $extra,
    ): array {
        $tokens = [
            'user_name' => $recipient->name,
            'institution' => config('gh.institution'),
            'building' => config('gh.building'),
            'action_url' => $request !== null ? url('/requests/'.$request->id) : url('/home'),
            'title' => $event->bellTitle(),
        ];

        if ($request !== null) {
            $tokens += [
                'request_no' => $request->request_no,
                'applicant_name' => $request->requester?->name ?? $request->guestName(),
                'purpose' => $request->purpose->label(),
                'check_in_date' => $request->check_in_date->format('d/m/Y'),
                'check_out_date' => $request->check_out_date->format('d/m/Y'),
                'nights' => (string) $request->nights,
                'total_members' => (string) $request->total_members,
                'status' => $request->status->label(),
                'remarks' => (string) ($request->manager_remarks ?? $request->adg_remarks ?? ''),
                'more_info_note' => (string) $request->more_info_note,
            ];

            // Role-appropriate deep link, so the recipient lands where they can act.
            // The applicant is checked FIRST: a Manager or ADG can book a room too,
            // and must be sent to their own request, not to an approval screen
            // for a request they are not permitted to decide. After a stay ends,
            // the applicant's link goes straight to the feedback form
            // (NOTIFICATIONS.md #15, "includes feedback link").
            $tokens['action_url'] = match (true) {
                $request->isOwnedBy($recipient) && in_array($event, [NotificationEvent::STAY_CHECKED_OUT, NotificationEvent::STAY_EARLY_CHECKOUT], true)
                    => route('my.requests.feedback', $request->id),
                $request->isOwnedBy($recipient) => route('my.requests.show', $request->id),
                $recipient->isManager() => route('manager.requests.show', $request->id),
                $recipient->isAdg() => route('adg.requests.show', $request->id),
                $recipient->isAdmin() => route('admin.allotments.show', $request->id),
                default => route('my.requests.show', $request->id),
            };
        }

        return array_merge($tokens, $extra);
    }

    private function bellBody(NotificationEvent $event, ?BookingRequest $request, array $tokens): string
    {
        $no = $tokens['request_no'] ?? null;

        return match ($event) {
            NotificationEvent::REQUEST_SUBMITTED => "{$tokens['applicant_name']} submitted {$no} for ".($tokens['check_in_date'] ?? '').'.',
            NotificationEvent::REQUEST_MORE_INFO => "The Manager needs more information on {$no}.",
            NotificationEvent::ROOMS_ALLOTTED => "Rooms have been allotted for {$no}.",
            NotificationEvent::ROOMS_NONE_AVAILABLE => "No room was available for {$no}.",
            NotificationEvent::REQUEST_REJECTED => "{$no} was not approved.",
            NotificationEvent::REQUEST_APPROVED_ADG => "{$no} is approved and needs an availability check.",
            NotificationEvent::STAY_EXTENSION_REQUESTED => "An extension has been requested for {$no}.",
            default => "{$no} — ".strtolower($event->bellTitle()).'.',
        };
    }

    private function fallbackHtml(NotificationEvent $event): string
    {
        return '<p>Dear {{user_name}},</p>'
            .'<p>'.$event->bellTitle().' for request <strong>{{request_no}}</strong>.</p>'
            .'<p>Stay: {{check_in_date}} to {{check_out_date}} ({{nights}} nights). Status: {{status}}.</p>'
            .'<p><a href="{{action_url}}">Open the request</a></p>'
            .'<p>{{building}}, {{institution}}</p>';
    }
}
