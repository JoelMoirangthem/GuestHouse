<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\DTOs\NotificationPayload;
use App\Domain\Contracts\NotificationChannel;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Email delivery through Laravel's mailer, whatever transport is configured.
 *
 * Works today with MAIL_MAILER=log. Pointing it at the Gmail API transport, a
 * departmental SMTP relay, or SES is a config change — this class does not care,
 * which is the whole reason the channel abstraction exists.
 *
 * CRITICALLY, send() never throws. A mail server outage must not roll back an
 * approval, so the failure is recorded on the notification row and the workflow
 * proceeds. The administrator sees failed notifications on the dashboard.
 */
class EmailChannel implements NotificationChannel
{
    public function key(): string
    {
        return 'EMAIL';
    }

    public function isEnabled(): bool
    {
        return (bool) config('gh.notify.email', true);
    }

    public function send(NotificationPayload $payload): bool
    {
        $notification = $payload->notificationId !== null
            ? Notification::find($payload->notificationId)
            : null;

        try {
            Mail::html($payload->bodyHtml, function ($message) use ($payload) {
                $message->to($payload->recipient->email, $payload->recipient->name)
                    ->subject($payload->subject);
            });

            $this->markSent($notification);

            return true;
        } catch (\Throwable $e) {
            // Recorded, not raised. See the class docblock.
            Log::warning('Notification email failed', [
                'event' => $payload->event->value,
                'recipient_id' => $payload->recipient->id,
                'error' => $e->getMessage(),
            ]);

            $this->markFailed($notification, $e->getMessage());

            return false;
        }
    }

    private function markSent(?Notification $n): void
    {
        if ($n === null) {
            return;
        }

        $n->status = 'SENT';
        $n->sent_at = now();
        $n->attempts = $n->attempts + 1;
        $n->error = null;
        $n->save();
    }

    private function markFailed(?Notification $n, string $error): void
    {
        if ($n === null) {
            return;
        }

        $n->status = 'FAILED';
        $n->attempts = $n->attempts + 1;
        // Truncated: a stack-trace-length error in a text column is unreadable in
        // the admin list anyway.
        $n->error = mb_substr($error, 0, 500);
        $n->save();
    }
}
