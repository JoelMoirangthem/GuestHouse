<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\NotificationEvent;
use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * One template per notification event — NOTIFICATIONS.md sections 2.1 and 3.
 *
 * Copy is written in plain official English, addressed to the recipient, and
 * always states what happens next. A government applicant reading these should
 * never have to guess whether they need to do something.
 *
 * NOT ONE TEMPLATE CONTAINS an Aadhaar number, an identity-proof number or a
 * document link. Every message carries {{action_url}} instead, which requires a
 * login. SECURITY.md section 2 forbids sensitive PII in email or SMS, and the
 * test suite asserts it.
 */
class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $key => [$subject, $intro, $body, $sms]) {
            EmailTemplate::updateOrCreate(
                ['event_key' => $key],
                [
                    'subject' => $subject,
                    'body_html' => $this->wrap($intro, $body),
                    'body_text' => $this->plain($intro, $body),
                    'sms_text' => $sms,
                    'placeholders' => $this->placeholders(),
                    'is_active' => true,
                ],
            );
        }

        // Fail loudly rather than leaving an event with no template, which would
        // silently fall back to generic copy in production.
        foreach (NotificationEvent::cases() as $event) {
            if (! EmailTemplate::where('event_key', $event->value)->exists()) {
                throw new RuntimeException("No email template seeded for '{$event->value}'.");
            }
        }
    }

    /**
     * @return array<string, array{0:string,1:string,2:string,3:string|null}>
     */
    private function templates(): array
    {
        return [
            NotificationEvent::REQUEST_SUBMITTED->value => [
                'New booking request {{request_no}} awaiting your review',
                'A new guest house booking request needs your review.',
                '<p>{{applicant_name}} has requested accommodation from {{check_in_date}} to {{check_out_date}} '
                .'({{nights}} nights) for {{total_members}} person(s). Purpose: {{purpose}}.</p>'
                .'<p>Please review it at your earliest convenience.</p>',
                null,
            ],

            NotificationEvent::REQUEST_RESUBMITTED->value => [
                'Request {{request_no}} resubmitted with the information you asked for',
                'The applicant has supplied the information you requested.',
                '<p>{{applicant_name}} has updated request {{request_no}} and returned it for your review.</p>',
                null,
            ],

            NotificationEvent::REQUEST_MORE_INFO->value => [
                'More information needed for request {{request_no}}',
                'Your booking request cannot proceed until you provide some further information.',
                '<p>The Manager has asked for the following:</p>'
                .'<blockquote>{{more_info_note}}</blockquote>'
                .'<p>Please update your request and resend it. Your dates are held in the meantime, '
                .'but no room is reserved.</p>',
                'Guest House: request {{request_no}} needs more information. Please sign in and update it.',
            ],

            NotificationEvent::REQUEST_APPROVED_MANAGER->value => [
                'Request {{request_no}} approved by the Manager',
                'The Manager has approved a booking request, which now awaits room allotment by the Administration.',
                '<p>Request {{request_no}} from {{applicant_name}} for {{check_in_date}} to {{check_out_date}} '
                .'has been approved and sent to the Administration for room allotment.</p>',
                null,
            ],

            NotificationEvent::REQUEST_REJECTED->value => [
                'Request {{request_no}} has been rejected',
                'Your booking request has not been approved.',
                '<p>Request {{request_no}} for {{check_in_date}} to {{check_out_date}} was not approved.</p>'
                .'<p>Reason recorded:</p><blockquote>{{remarks}}</blockquote>'
                .'<p>If you believe this needs reconsideration, please contact the Administration.</p>',
                'Guest House: request {{request_no}} was not approved. Sign in for the reason.',
            ],

            NotificationEvent::REQUEST_APPROVED_ADG->value => [
                'Request {{request_no}} approved by the ADG — availability check needed',
                'A request has cleared both approval stages and now needs a room decision.',
                '<p>Request {{request_no}} from {{applicant_name}} is approved for {{check_in_date}} to '
                .'{{check_out_date}} ({{total_members}} person(s)).</p>'
                .'<p>Please check room availability and allot accordingly.</p>',
                null,
            ],

            NotificationEvent::ROOMS_ALLOTTED->value => [
                'Rooms allotted for request {{request_no}}',
                'Your accommodation has been confirmed.',
                '<p>Room(s) allotted: <strong>{{rooms}}</strong></p>'
                .'<p>Check in from {{check_in_date}}, check out by {{check_out_date}} ({{nights}} nights).</p>'
                .'<p>Please carry the identity document you submitted. Report to the front desk on arrival.</p>',
                'Guest House: rooms {{rooms}} allotted for {{check_in_date}}. Ref {{request_no}}.',
            ],

            NotificationEvent::ROOMS_NONE_AVAILABLE->value => [
                'No room available for request {{request_no}}',
                'Your request was approved, but no room could be offered for those dates.',
                '<p>Request {{request_no}} for {{check_in_date}} to {{check_out_date}} was approved by both '
                .'authorities, but the guest house has no room free for that period.</p>'
                .'<p>Please contact the Administration to discuss alternative dates.</p>',
                'Guest House: no room available for {{request_no}} ({{check_in_date}}). Please contact the Administration.',
            ],

            NotificationEvent::AVAILABILITY_RECHECK->value => [
                'Request {{request_no}} returned to the allotment queue',
                'Your request is being looked at again.',
                '<p>A room may now be available for {{check_in_date}} to {{check_out_date}}. '
                .'The Administration is re-checking request {{request_no}}.</p>',
                null,
            ],

            NotificationEvent::STAY_CHECKIN_REMINDER->value => [
                'Your stay begins tomorrow — request {{request_no}}',
                'This is a reminder that your guest house stay begins tomorrow.',
                '<p>Check in from {{check_in_date}}. Room(s): {{rooms}}.</p>'
                .'<p>Please bring the identity document you submitted with your request.</p>',
                'Guest House: your stay begins {{check_in_date}}. Ref {{request_no}}.',
            ],

            NotificationEvent::STAY_CHECKOUT_REMINDER->value => [
                'Check-out due today — request {{request_no}}',
                'Your stay ends today.',
                '<p>Check-out is due today, {{check_out_date}}. Please settle any dues at the front desk.</p>'
                .'<p>If you need additional nights, request an extension before checking out.</p>',
                'Guest House: check-out due today ({{check_out_date}}). Ref {{request_no}}.',
            ],

            NotificationEvent::STAY_CHECKED_IN->value => [
                'Checked in — request {{request_no}}',
                'Your check-in has been recorded.',
                '<p>Welcome. Your stay runs to {{check_out_date}}.</p>',
                null,
            ],

            NotificationEvent::STAY_EXTENSION_REQUESTED->value => [
                'Extension requested for {{request_no}} — decision needed',
                'A guest has asked to extend their stay.',
                '<p>{{applicant_name}} has requested additional nights on request {{request_no}}, '
                .'currently due to end on {{check_out_date}}.</p>'
                .'<p>Approving will re-verify that the room is free for the extra nights.</p>',
                null,
            ],

            NotificationEvent::STAY_EXTENDED->value => [
                'Your stay has been extended — request {{request_no}}',
                'Your extension has been approved.',
                '<p>Your stay now runs to {{check_out_date}}. The same room(s) are retained.</p>'
                .'<p>Charges have been updated accordingly.</p>',
                'Guest House: extension approved. New check-out {{check_out_date}}. Ref {{request_no}}.',
            ],

            NotificationEvent::STAY_EXTENSION_DENIED->value => [
                'Extension not granted for request {{request_no}}',
                'Your request for additional nights could not be granted.',
                '<p>Your original check-out date of {{check_out_date}} stands.</p>'
                .'<p>Reason recorded:</p><blockquote>{{remarks}}</blockquote>'
                .'<p>Please contact the Administration if you need to discuss alternatives.</p>',
                'Guest House: extension not granted. Check-out remains {{check_out_date}}. Ref {{request_no}}.',
            ],

            NotificationEvent::STAY_CHECKED_OUT->value => [
                'Stay completed — request {{request_no}}',
                'Thank you for staying with us.',
                '<p>Your stay under request {{request_no}} is now complete.</p>'
                .'<p>We would be grateful if you would record your feedback using the button below. '
                .'It takes a minute and helps the Administration improve the guest house.</p>',
                null,
            ],

            NotificationEvent::STAY_EARLY_CHECKOUT->value => [
                'Early check-out recorded — request {{request_no}}',
                'An early check-out has been recorded.',
                '<p>Request {{request_no}} was checked out before the scheduled date of {{check_out_date}}. '
                .'The room(s) have been returned to the available pool and charges recomputed on the '
                .'nights actually stayed.</p>',
                null,
            ],

            NotificationEvent::REQUEST_CANCELLED->value => [
                'Request {{request_no}} has been cancelled',
                'A booking request has been cancelled.',
                '<p>Request {{request_no}} for {{check_in_date}} to {{check_out_date}} has been cancelled. '
                .'Any rooms held against it have been released.</p>',
                null,
            ],

            NotificationEvent::ANNOUNCEMENT_GENERAL->value => [
                '{{title}}',
                'An announcement from the Guest House Administration.',
                '<p>{{body}}</p>',
                null,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function placeholders(): array
    {
        return [
            'user_name', 'request_no', 'applicant_name', 'purpose',
            'check_in_date', 'check_out_date', 'nights', 'total_members',
            'status', 'remarks', 'more_info_note', 'rooms',
            'action_url', 'institution', 'building', 'title',
        ];
    }

    /**
     * Minimal, table-free HTML. Government mail clients are often old, and a
     * single-column layout with inline styles renders predictably everywhere.
     */
    private function wrap(string $intro, string $body): string
    {
        return <<<HTML
        <div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#14181f;max-width:600px">
          <p style="margin:0 0 4px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#6b7484">{{institution}}</p>
          <h2 style="margin:0 0 16px;font-size:18px;color:#16283e">Guest House Booking System</h2>
          <p>Dear {{user_name}},</p>
          <p>{$intro}</p>
          {$body}
          <p style="margin:24px 0 8px">
            <a href="{{action_url}}" style="background:#1d3452;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block">Open in the system</a>
          </p>
          <hr style="border:none;border-top:1px solid #e7e5e0;margin:24px 0 12px">
          <p style="font-size:12px;color:#6b7484;margin:0">
            {{building}}, {{institution}}<br>
            This is an automated message. Please do not reply to it.
          </p>
        </div>
        HTML;
    }

    private function plain(string $intro, string $body): string
    {
        $text = strip_tags(str_replace(['</p>', '<br>', '</blockquote>'], "\n", $body));

        return "Dear {{user_name}},\n\n{$intro}\n\n"
            .trim(preg_replace('/\n{3,}/', "\n\n", $text) ?? '')
            ."\n\nOpen in the system: {{action_url}}\n\n"
            ."{{building}}, {{institution}}\nThis is an automated message. Please do not reply to it.";
    }
}
