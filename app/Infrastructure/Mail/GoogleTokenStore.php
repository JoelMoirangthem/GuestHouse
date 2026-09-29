<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Holds the Gmail OAuth refresh token and exchanges it for short-lived access
 * tokens.
 *
 * WHY A REFRESH TOKEN AND NOT A PASSWORD
 * Google's OAuth flow issues an access token valid for about an hour, plus a
 * refresh token that does not expire unless revoked. The refresh token is the
 * real credential, so it is stored encrypted in `settings` rather than in .env —
 * it is minted at runtime by an interactive consent, so it cannot be a deploy-time
 * environment value.
 *
 * Access tokens are cached until just before they expire, so a burst of
 * notifications does not trigger a token request per message.
 */
class GoogleTokenStore
{
    private const REFRESH_KEY = 'google.gmail.refresh_token';

    private const EMAIL_KEY = 'google.gmail.address';

    private const CACHE_KEY = 'google.gmail.access_token';

    public function hasRefreshToken(): bool
    {
        return $this->refreshToken() !== null;
    }

    public function refreshToken(): ?string
    {
        return Setting::get(self::REFRESH_KEY);
    }

    public function senderAddress(): ?string
    {
        return Setting::get(self::EMAIL_KEY);
    }

    public function store(string $refreshToken, string $email): void
    {
        Setting::put(
            self::REFRESH_KEY,
            $refreshToken,
            'SECRET',
            'mail',
            'Gmail API OAuth refresh token. Encrypted at rest.',
        );

        Setting::put(self::EMAIL_KEY, $email, 'STRING', 'mail', 'The Gmail account that sends system mail.');

        Cache::forget(self::CACHE_KEY);
    }

    public function revoke(): void
    {
        Setting::forget(self::REFRESH_KEY);
        Setting::forget(self::EMAIL_KEY);
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * A valid access token, refreshing only when the cached one has expired.
     *
     * @throws RuntimeException
     */
    public function accessToken(): string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $refresh = $this->refreshToken();

        if ($refresh === null) {
            throw new RuntimeException(
                'Gmail is not connected. Visit /admin/mail/google to authorise the sending account.'
            );
        }

        $response = Http::asForm()
            ->timeout(15)
            ->post(config('services.google.token_uri'), [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => $refresh,
                'grant_type' => 'refresh_token',
            ]);

        if ($response->failed()) {
            $error = $response->json('error_description') ?? $response->json('error') ?? 'unknown error';

            // invalid_grant here means the token was revoked or the consent was
            // withdrawn. Saying so plainly saves a long debugging session.
            throw new RuntimeException(
                "Gmail token refresh failed: {$error}. Re-authorise at /admin/mail/google."
            );
        }

        $token = (string) $response->json('access_token');
        $expiresIn = (int) ($response->json('expires_in') ?? 3600);

        // Expire the cache a minute early so a token never goes stale mid-send.
        Cache::put(self::CACHE_KEY, $token, max(60, $expiresIn - 60));

        return $token;
    }
}
