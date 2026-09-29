<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Domain\Enums\NotificationEvent;
use App\Models\BookingRequest;
use App\Models\User;

/**
 * Everything a channel needs to deliver one message to one recipient.
 *
 * Immutable, and deliberately free of Eloquent query logic: a channel receives
 * finished text and a recipient, never a model it has to interrogate.
 */
final class NotificationPayload
{
    /**
     * @param  array<string, string>  $tokens  rendered placeholder values
     */
    public function __construct(
        public readonly User $recipient,
        public readonly NotificationEvent $event,
        public readonly string $subject,
        public readonly string $bodyHtml,
        public readonly string $bodyText,
        public readonly ?string $smsText = null,
        public readonly ?string $actionUrl = null,
        public readonly ?BookingRequest $request = null,
        public readonly array $tokens = [],
        public readonly ?int $notificationId = null,
    ) {}

    /**
     * A copy carrying the persisted notification id, so a channel can update the
     * row it belongs to.
     */
    public function withNotificationId(int $id): self
    {
        return new self(
            $this->recipient,
            $this->event,
            $this->subject,
            $this->bodyHtml,
            $this->bodyText,
            $this->smsText,
            $this->actionUrl,
            $this->request,
            $this->tokens,
            $id,
        );
    }
}
