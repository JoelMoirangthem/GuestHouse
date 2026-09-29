<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Message;

/**
 * Sends mail through the Gmail API instead of SMTP.
 *
 * Implemented as a Symfony transport rather than as a bespoke "send email"
 * service, because that way every existing Laravel Mailable, notification and
 * password-reset link keeps working untouched — the delivery mechanism is
 * swapped underneath them.
 *
 * Talks to the REST endpoint directly with Laravel's HTTP client rather than
 * pulling in google/apiclient, which would add a large dependency tree to do one
 * POST.
 */
class GmailApiTransport extends AbstractTransport
{
    private const ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    public function __construct(
        private readonly GoogleTokenStore $tokens,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        if (! $original instanceof Message) {
            throw new RuntimeException('The Gmail transport can only send MIME messages.');
        }

        // Gmail expects the complete RFC 2822 message, base64url encoded.
        $raw = $this->base64Url($original->toString());

        $response = Http::withToken($this->tokens->accessToken())
            ->timeout(30)
            ->post(self::ENDPOINT, ['raw' => $raw]);

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();
            $status = $response->status();

            // 403 here almost always means the Gmail API is not enabled on the
            // project, or the granted scope does not include gmail.send.
            $hint = $status === 403
                ? ' Check that the Gmail API is enabled on the Google Cloud project and that the gmail.send scope was granted.'
                : '';

            throw new RuntimeException("Gmail API rejected the message (HTTP {$status}): {$error}.{$hint}");
        }
    }

    /**
     * URL-safe base64 without padding, as the Gmail API requires.
     */
    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public function __toString(): string
    {
        return 'gmail-api';
    }
}
