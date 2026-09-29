<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * The 19 notification events — NOTIFICATIONS.md sections 2 and 2.1.
 *
 * Keeping the key, the channels and the default copy together in one enum means
 * the mapping cannot drift: there is no separate config file to fall out of step
 * with the listeners.
 *
 * Three of these were missing from an early draft of the specification and each
 * was a real functional hole:
 *   - STAY_EXTENSION_REQUESTED: nobody was told an extension needed deciding
 *   - STAY_CHECKED_OUT: no notification, so the feedback loop could never start
 *   - REQUEST_CANCELLED omitted the requester, so an admin could cancel a booking
 *     without the guest ever learning of it
 */
enum NotificationEvent: string
{
    case REQUEST_SUBMITTED = 'request.submitted';
    case REQUEST_RESUBMITTED = 'request.resubmitted';
    case REQUEST_MORE_INFO = 'request.more_info';
    case REQUEST_APPROVED_MANAGER = 'request.approved.manager';
    case REQUEST_REJECTED = 'request.rejected';
    case REQUEST_APPROVED_ADG = 'request.approved.adg';
    case ROOMS_ALLOTTED = 'rooms.allotted';
    case ROOMS_NONE_AVAILABLE = 'rooms.none_available';
    case AVAILABILITY_RECHECK = 'availability.recheck';
    case STAY_CHECKIN_REMINDER = 'stay.checkin_reminder';
    case STAY_CHECKOUT_REMINDER = 'stay.checkout_reminder';
    case STAY_CHECKED_IN = 'stay.checked_in';
    case STAY_EXTENSION_REQUESTED = 'stay.extension_requested';
    case STAY_EXTENDED = 'stay.extended';
    case STAY_EXTENSION_DENIED = 'stay.extension_denied';
    case STAY_CHECKED_OUT = 'stay.checked_out';
    case STAY_EARLY_CHECKOUT = 'stay.early_checkout';
    case REQUEST_CANCELLED = 'request.cancelled';
    case ANNOUNCEMENT_GENERAL = 'announcement.general';

    /**
     * Channels this event uses — NOTIFICATIONS.md section 2.
     *
     * SMS is reserved for events that need action or are time-critical, because
     * every SMS costs money. Email carries everything; in-app carries everything
     * except the two purely informational reminders that would clutter the bell.
     *
     * @return array<int, string>
     */
    public function channels(): array
    {
        return match ($this) {
            // Action needed or time-critical: all three channels.
            self::REQUEST_MORE_INFO,
            self::REQUEST_REJECTED,
            self::ROOMS_ALLOTTED,
            self::ROOMS_NONE_AVAILABLE,
            self::STAY_CHECKIN_REMINDER,
            self::STAY_CHECKOUT_REMINDER,
            self::STAY_EXTENDED,
            self::STAY_EXTENSION_DENIED => ['EMAIL', 'SMS', 'IN_APP'],

            // In-app only: useful to see, not worth an email.
            self::STAY_CHECKED_IN => ['IN_APP'],

            // Everything else: email plus in-app.
            default => ['EMAIL', 'IN_APP'],
        };
    }

    public function usesSms(): bool
    {
        return in_array('SMS', $this->channels(), true);
    }

    /**
     * Default subject line. Templates in `email_templates` override this, but the
     * default means a missing template degrades to a sensible message rather than
     * a blank email.
     */
    public function defaultSubject(): string
    {
        return match ($this) {
            self::REQUEST_SUBMITTED => 'New booking request {{request_no}} awaiting your review',
            self::REQUEST_RESUBMITTED => 'Request {{request_no}} resubmitted with the information you asked for',
            self::REQUEST_MORE_INFO => 'More information needed for request {{request_no}}',
            self::REQUEST_APPROVED_MANAGER => 'Request {{request_no}} approved by the Manager',
            self::REQUEST_REJECTED => 'Request {{request_no}} has been rejected',
            self::REQUEST_APPROVED_ADG => 'Request {{request_no}} approved by the ADG — availability check needed',
            self::ROOMS_ALLOTTED => 'Rooms allotted for request {{request_no}}',
            self::ROOMS_NONE_AVAILABLE => 'No room available for request {{request_no}}',
            self::AVAILABILITY_RECHECK => 'Request {{request_no}} returned to the allotment queue',
            self::STAY_CHECKIN_REMINDER => 'Your stay begins tomorrow — request {{request_no}}',
            self::STAY_CHECKOUT_REMINDER => 'Check-out due today — request {{request_no}}',
            self::STAY_CHECKED_IN => 'Checked in — request {{request_no}}',
            self::STAY_EXTENSION_REQUESTED => 'Extension requested for {{request_no}} — decision needed',
            self::STAY_EXTENDED => 'Your stay has been extended — request {{request_no}}',
            self::STAY_EXTENSION_DENIED => 'Extension not granted for request {{request_no}}',
            self::STAY_CHECKED_OUT => 'Stay completed — request {{request_no}}',
            self::STAY_EARLY_CHECKOUT => 'Early check-out recorded — request {{request_no}}',
            self::REQUEST_CANCELLED => 'Request {{request_no}} has been cancelled',
            self::ANNOUNCEMENT_GENERAL => '{{title}}',
        };
    }

    /**
     * Short line for the in-app bell.
     */
    public function bellTitle(): string
    {
        return match ($this) {
            self::REQUEST_SUBMITTED => 'New request to review',
            self::REQUEST_RESUBMITTED => 'Request resubmitted',
            self::REQUEST_MORE_INFO => 'Information required',
            self::REQUEST_APPROVED_MANAGER => 'Approved by Manager',
            self::REQUEST_REJECTED => 'Request rejected',
            self::REQUEST_APPROVED_ADG => 'Approved by ADG',
            self::ROOMS_ALLOTTED => 'Rooms allotted',
            self::ROOMS_NONE_AVAILABLE => 'No room available',
            self::AVAILABILITY_RECHECK => 'Back in the allotment queue',
            self::STAY_CHECKIN_REMINDER => 'Your stay begins tomorrow',
            self::STAY_CHECKOUT_REMINDER => 'Check-out due today',
            self::STAY_CHECKED_IN => 'Checked in',
            self::STAY_EXTENSION_REQUESTED => 'Extension needs a decision',
            self::STAY_EXTENDED => 'Stay extended',
            self::STAY_EXTENSION_DENIED => 'Extension not granted',
            self::STAY_CHECKED_OUT => 'Stay completed',
            self::STAY_EARLY_CHECKOUT => 'Early check-out',
            self::REQUEST_CANCELLED => 'Request cancelled',
            self::ANNOUNCEMENT_GENERAL => 'Announcement',
        };
    }

    /**
     * Tone for the bell entry, so urgency is visible at a glance.
     */
    public function tone(): string
    {
        return match ($this) {
            self::REQUEST_MORE_INFO,
            self::STAY_CHECKOUT_REMINDER,
            self::STAY_EXTENSION_REQUESTED => 'attention',

            self::REQUEST_REJECTED,
            self::ROOMS_NONE_AVAILABLE,
            self::STAY_EXTENSION_DENIED => 'danger',

            self::ROOMS_ALLOTTED,
            self::STAY_EXTENDED,
            self::REQUEST_APPROVED_ADG,
            self::REQUEST_APPROVED_MANAGER => 'success',

            default => 'neutral',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
