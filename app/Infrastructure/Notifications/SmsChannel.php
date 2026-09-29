<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\DTOs\NotificationPayload;
use App\Domain\Contracts\NotificationChannel;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;

/**
 * SMS delivery.
 *
 * DISABLED. NOTIFY_SMS_ENABLED is false because no gateway has been chosen — an
 * Indian government deployment would typically use NIC's SMS gateway or a
 * DLT-registered commercial provider, and the sender ID has to be registered
 * before anything can be sent.
 *
 * The class exists in full so that enabling SMS later is a config flag plus the
 * gateway call in deliver(). None of the 19 event wirings change, and the
 * notification rows are already being written with channel SMS where the event
 * calls for it — so there is a record of what *would* have been sent.
 */
class SmsChannel implements NotificationChannel
{
    public function key(): string
    {
        return 'SMS';
    }

    public function isEnabled(): bool
    {
        return (bool) config('gh.notify.sms', false);
    }

    public function send(NotificationPayload $payload): bool
    {
        $notification = $payload->notificationId !== null
            ? Notification::find($payload->notificationId)
            : null;

        $mobile = $payload->recipient->mobile;

        if (blank($mobile)) {
            $this->mark($notification, 'FAILED', 'No mobile number on the recipient record.');

            return false;
        }

        try {
            $this->deliver($mobile, $payload->smsText ?? $payload->bodyText);

            $this->mark($notification, 'SENT');

            return true;
        } catch (\Throwable $e) {
            Log::warning('Notification SMS failed', [
                'event' => $payload->event->value,
                'recipient_id' => $payload->recipient->id,
                'error' => $e->getMessage(),
            ]);

            $this->mark($notification, 'FAILED', $e->getMessage());

            return false;
        }
    }

    /**
     * The gateway call. Deliberately left as the single point to implement.
     *
     * Two things will matter when a gateway is chosen: the body must stay within
     * 320 characters (two segments), and the template has to be pre-registered
     * under India's DLT rules or the carrier will drop it.
     */
    private function deliver(string $mobile, string $text): void
    {
        throw new \RuntimeException(
            'No SMS gateway is configured. Set NOTIFY_SMS_ENABLED=true and implement '
            .'SmsChannel::deliver() against the chosen provider.'
        );
    }

    private function mark(?Notification $n, string $status, ?string $error = null): void
    {
        if ($n === null) {
            return;
        }

        $n->status = $status;
        $n->attempts = $n->attempts + 1;
        $n->error = $error === null ? null : mb_substr($error, 0, 500);

        if ($status === 'SENT') {
            $n->sent_at = now();
        }

        $n->save();
    }
}
