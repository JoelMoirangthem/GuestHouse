<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\DTOs\NotificationPayload;
use App\Domain\Contracts\NotificationChannel;
use App\Models\Notification;

/**
 * In-app delivery. Marks the already-persisted row as SENT so the bell picks it
 * up on its next poll.
 *
 * This channel cannot fail in any interesting way — there is no network involved —
 * which is exactly why it is the one channel that must always be enabled. If
 * email and SMS both fail, the recipient still learns of the decision when they
 * next open the system.
 */
class InAppChannel implements NotificationChannel
{
    public function key(): string
    {
        return 'IN_APP';
    }

    public function isEnabled(): bool
    {
        return (bool) config('gh.notify.in_app', true);
    }

    public function send(NotificationPayload $payload): bool
    {
        if ($payload->notificationId === null) {
            return false;
        }

        $notification = Notification::find($payload->notificationId);

        if ($notification === null) {
            return false;
        }

        $notification->status = 'SENT';
        $notification->sent_at = now();
        $notification->attempts = $notification->attempts + 1;
        $notification->save();

        return true;
    }
}
