<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

use App\Application\DTOs\NotificationPayload;

/**
 * A delivery channel — NOTIFICATIONS.md section 1.
 *
 * This is one of the few genuinely swappable abstractions in the application
 * (PLAN.md decision 5). Adding WhatsApp later means one new class and one line in
 * a provider; none of the 19 event wirings change. That is the Open/Closed
 * principle earning its keep rather than being cited decoratively.
 */
interface NotificationChannel
{
    /** 'EMAIL' | 'SMS' | 'IN_APP' — matches the notifications.channel column. */
    public function key(): string;

    /**
     * Attempt delivery. MUST NOT throw: a channel failure is recorded against the
     * notification row and never allowed to interrupt a workflow transition. An
     * approval must not roll back because a mail server was down.
     */
    public function send(NotificationPayload $payload): bool;

    /**
     * Whether this channel is switched on. Read from config so an administrator
     * can disable SMS without a deploy.
     */
    public function isEnabled(): bool;
}
