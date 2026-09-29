<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\RoleSlug;
use App\Infrastructure\Mail\GoogleTokenStore;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Gmail OAuth connect / revoke lifecycle.
 *
 * No test here talks to Google: every outbound call is faked, and the fake is
 * set to refuse anything unexpected so a stray real request fails loudly.
 */
class GoogleMailConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const REVOKE_URI = 'https://oauth2.googleapis.com/revoke';

    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        config([
            'services.google.revoke_uri' => self::REVOKE_URI,
            'services.google.token_uri' => self::TOKEN_URI,
            'services.google.userinfo_uri' => 'https://www.googleapis.com/oauth2/v3/userinfo',
            'services.google.client_id' => 'test-client',
            'services.google.client_secret' => 'test-secret',
        ]);

        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        return User::factory()->role(RoleSlug::ADMIN)->create();
    }

    private function tokens(): GoogleTokenStore
    {
        return app(GoogleTokenStore::class);
    }

    // ------------------------------------------------------------------ revoke

    #[Test]
    public function disconnect_revokes_the_grant_at_google_and_forgets_it_locally(): void
    {
        $this->tokens()->store('refresh-abc', 'sender@example.org');
        Cache::put('google.gmail.access_token', 'cached-access', 600);

        Http::fake([self::REVOKE_URI => Http::response('', 200)]);

        $this->actingAs($this->admin())
            ->from(route('admin.mail.google'))
            ->post(route('admin.mail.google.disconnect'))
            ->assertRedirect(route('admin.mail.google'))
            ->assertSessionHas('success', 'Gmail disconnected.');

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::REVOKE_URI
            && $r['token'] === 'refresh-abc');

        $this->assertFalse($this->tokens()->hasRefreshToken());
        $this->assertNull($this->tokens()->senderAddress());
        $this->assertNull(Cache::get('google.gmail.access_token'));
    }

    #[Test]
    public function disconnect_still_forgets_the_token_when_google_is_unreachable(): void
    {
        $this->tokens()->store('refresh-abc', 'sender@example.org');

        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->actingAs($this->admin())
            ->from(route('admin.mail.google'))
            ->post(route('admin.mail.google.disconnect'))
            ->assertRedirect(route('admin.mail.google'))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'could not confirm'));

        $this->assertFalse($this->tokens()->hasRefreshToken());
    }

    #[Test]
    public function disconnect_still_forgets_the_token_when_google_rejects_the_revoke(): void
    {
        $this->tokens()->store('refresh-abc', 'sender@example.org');

        Http::fake([self::REVOKE_URI => Http::response(['error' => 'invalid_token'], 400)]);

        $this->actingAs($this->admin())
            ->from(route('admin.mail.google'))
            ->post(route('admin.mail.google.disconnect'))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'could not confirm'));

        $this->assertFalse($this->tokens()->hasRefreshToken());
    }

    #[Test]
    public function disconnect_without_a_token_does_not_call_google(): void
    {
        Http::fake();

        $this->actingAs($this->admin())
            ->from(route('admin.mail.google'))
            ->post(route('admin.mail.google.disconnect'))
            ->assertSessionHas('success');

        Http::assertNothingSent();
    }

    #[Test]
    public function only_an_administrator_may_disconnect(): void
    {
        $this->tokens()->store('refresh-abc', 'sender@example.org');
        Http::fake();

        foreach ([RoleSlug::USER, RoleSlug::MANAGER, RoleSlug::ADG] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->post(route('admin.mail.google.disconnect'))
                ->assertForbidden();
        }

        Http::assertNothingSent();
        $this->assertTrue($this->tokens()->hasRefreshToken());
    }

    #[Test]
    public function disconnect_requires_a_signed_in_user(): void
    {
        $this->post(route('admin.mail.google.disconnect'))->assertRedirect(route('login'));
    }

    #[Test]
    public function a_revoked_store_refuses_to_mint_access_tokens(): void
    {
        $this->tokens()->store('refresh-abc', 'sender@example.org');
        $this->tokens()->revoke();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gmail is not connected');

        $this->tokens()->accessToken();
    }

    #[Test]
    public function an_invalid_grant_from_google_is_reported_plainly(): void
    {
        $this->tokens()->store('refresh-abc', 'sender@example.org');

        Http::fake([self::TOKEN_URI => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Token has been expired or revoked.',
        ], 400)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Token has been expired or revoked.');

        $this->tokens()->accessToken();
    }

    // ------------------------------------------------------------------ storage

    #[Test]
    public function the_refresh_token_is_encrypted_at_rest(): void
    {
        $this->tokens()->store('refresh-abc', 'sender@example.org');

        $raw = Setting::where('key', 'google.gmail.refresh_token')->value('value');

        $this->assertNotSame('refresh-abc', $raw);
        $this->assertStringNotContainsString('refresh-abc', (string) $raw);
        $this->assertSame('refresh-abc', $this->tokens()->refreshToken());
    }

    #[Test]
    public function access_tokens_are_cached_between_sends(): void
    {
        $this->tokens()->store('refresh-abc', 'sender@example.org');

        Http::fake([self::TOKEN_URI => Http::response([
            'access_token' => 'access-1',
            'expires_in' => 3600,
        ])]);

        $this->assertSame('access-1', $this->tokens()->accessToken());
        $this->assertSame('access-1', $this->tokens()->accessToken());

        Http::assertSentCount(1);
    }

    // ------------------------------------------------------------------ connect

    #[Test]
    public function the_callback_rejects_a_mismatched_state(): void
    {
        Http::fake();

        $this->actingAs($this->admin())
            ->withSession(['google_oauth_state' => 'expected-state'])
            ->get(route('oauth.google.callback', ['state' => 'forged', 'code' => 'x']))
            ->assertRedirect(route('admin.mail.google'))
            ->assertSessionHasErrors('google');

        Http::assertNothingSent();
        $this->assertFalse($this->tokens()->hasRefreshToken());
    }

    #[Test]
    public function the_callback_stores_the_refresh_token_on_success(): void
    {
        Http::fake([
            self::TOKEN_URI => Http::response([
                'access_token' => 'access-1',
                'refresh_token' => 'refresh-new',
                'expires_in' => 3600,
            ]),
            'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response(['email' => 'sender@example.org']),
        ]);

        $this->actingAs($this->admin())
            ->withSession(['google_oauth_state' => 'good-state'])
            ->get(route('oauth.google.callback', ['state' => 'good-state', 'code' => 'auth-code']))
            ->assertRedirect(route('admin.mail.google'))
            ->assertSessionHas('success');

        $this->assertSame('refresh-new', $this->tokens()->refreshToken());
        $this->assertSame('sender@example.org', $this->tokens()->senderAddress());
    }

    #[Test]
    public function the_callback_explains_a_missing_refresh_token(): void
    {
        Http::fake([self::TOKEN_URI => Http::response(['access_token' => 'access-1'])]);

        $this->actingAs($this->admin())
            ->withSession(['google_oauth_state' => 'good-state'])
            ->get(route('oauth.google.callback', ['state' => 'good-state', 'code' => 'auth-code']))
            ->assertSessionHasErrors('google');

        $this->assertFalse($this->tokens()->hasRefreshToken());
    }
}
