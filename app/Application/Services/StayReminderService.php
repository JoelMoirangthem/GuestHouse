<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Enums\NotificationEvent;
use App\Domain\Enums\RequestStatus;
use App\Models\Allotment;
use App\Models\BookingRequest;
use App\Models\Notification;
use Illuminate\Support\Carbon;

/**
 * The two scheduled notifications — NOTIFICATIONS.md events 9 and 10.
 *
 *   9  stay.checkin_reminder   the day before arrival, to ALLOTTED requests
 *   10 stay.checkout_reminder  the morning of departure, to guests in residence
 *
 * IDEMPOTENT. The scheduler may run twice (a retry, a manual run, two servers),
 * and a guest must not get two SMS for one stay. The notifications table is the
 * record of what was sent:
 *
 *   - a check-in reminder is sent once per request, ever: arrival dates are
 *     fixed once rooms are allotted
 *   - a check-out reminder is sent once per departure date: an approved
 *     extension moves the date, and the new date deserves its own reminder
 *
 * PARTIALLY_ALLOTTED requests get no check-in reminder. They cannot check in
 * (PLAN.md decision 9), so "your stay begins tomorrow" would send a guest to a
 * desk that must turn them away.
 */
class StayReminderService
{
    public function __construct(
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * @return array{checkin: int, checkout: int, skipped: int}
     */
    public function send(?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $sent = ['checkin' => 0, 'checkout' => 0, 'skipped' => 0];

        $arriving = BookingRequest::query()
            ->with('requester')
            ->where('status', RequestStatus::ALLOTTED->value)
            ->whereDate('check_in_date', $today->copy()->addDay())
            ->get();

        foreach ($arriving as $request) {
            if ($this->alreadySent($request, NotificationEvent::STAY_CHECKIN_REMINDER, null)) {
                $sent['skipped']++;

                continue;
            }

            if ($this->dispatch(NotificationEvent::STAY_CHECKIN_REMINDER, $request) > 0) {
                $sent['checkin']++;
            }
        }

        $departing = BookingRequest::query()
            ->with('requester')
            ->whereIn('status', [RequestStatus::CHECKED_IN->value, RequestStatus::EXTENSION_REQUESTED->value])
            ->whereDate('check_out_date', $today)
            ->get();

        foreach ($departing as $request) {
            if ($this->alreadySent($request, NotificationEvent::STAY_CHECKOUT_REMINDER, $today)) {
                $sent['skipped']++;

                continue;
            }

            if ($this->dispatch(NotificationEvent::STAY_CHECKOUT_REMINDER, $request) > 0) {
                $sent['checkout']++;
            }
        }

        return $sent;
    }

    private function dispatch(NotificationEvent $event, BookingRequest $request): int
    {
        $rooms = Allotment::query()
            ->where('booking_request_id', $request->id)
            ->occupying()
            ->with('room')
            ->get()
            ->pluck('room.room_number')
            ->sort()
            ->implode(', ');

        return $this->notifications->dispatch($event, $request, ['rooms' => $rooms]);
    }

    /**
     * $since null = ever; otherwise only reminders created on or after that day,
     * which is what lets an extended stay get a reminder for its new date.
     */
    private function alreadySent(BookingRequest $request, NotificationEvent $event, ?Carbon $since): bool
    {
        return Notification::query()
            ->where('booking_request_id', $request->id)
            ->where('event_key', $event->value)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->exists();
    }
}
