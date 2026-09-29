<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\RoleSlug;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SECURITY.md section 3 — authentication hardening.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        RateLimiter::clear('');
    }

    #[Test]
    public function the_login_screen_renders(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Sign in')
            ->assertSee('Guest House Booking System');
    }

    #[Test]
    public function a_user_can_sign_in_with_valid_credentials(): void
    {
        $user = User::factory()->role(RoleSlug::USER)->create([
            'email' => 'employee@nadt.gov.in',
        ]);

        $this->post(route('login.attempt'), [
            'email' => 'employee@nadt.gov.in',
            'password' => 'Password@123',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function signing_in_records_the_last_login_timestamp(): void
    {
        $user = User::factory()->role(RoleSlug::USER)->create([
            'email' => 'stamp@nadt.gov.in',
            'last_login_at' => null,
        ]);

        $this->post(route('login.attempt'), [
            'email' => 'stamp@nadt.gov.in',
            'password' => 'Password@123',
        ]);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    #[Test]
    public function a_wrong_password_is_rejected(): void
    {
        User::factory()->role(RoleSlug::USER)->create(['email' => 'employee@nadt.gov.in']);

        $this->post(route('login.attempt'), [
            'email' => 'employee@nadt.gov.in',
            'password' => 'WrongPassword1!',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function a_deactivated_account_cannot_sign_in(): void
    {
        User::factory()->role(RoleSlug::USER)->inactive()->create([
            'email' => 'dormant@nadt.gov.in',
        ]);

        $this->post(route('login.attempt'), [
            'email' => 'dormant@nadt.gov.in',
            'password' => 'Password@123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function failure_messages_do_not_reveal_whether_an_account_exists(): void
    {
        User::factory()->role(RoleSlug::USER)->create(['email' => 'real@nadt.gov.in']);

        $known = $this->post(route('login.attempt'), [
            'email' => 'real@nadt.gov.in',
            'password' => 'WrongPassword1!',
        ]);

        $unknown = $this->post(route('login.attempt'), [
            'email' => 'ghost@nadt.gov.in',
            'password' => 'WrongPassword1!',
        ]);

        // Identical wording for both cases; otherwise the response is an oracle
        // for which government email addresses are registered.
        $this->assertSame(
            $known->getSession()->get('errors')->first('email'),
            $unknown->getSession()->get('errors')->first('email'),
        );
    }

    #[Test]
    public function login_is_throttled_after_five_failed_attempts(): void
    {
        User::factory()->role(RoleSlug::USER)->create(['email' => 'target@nadt.gov.in']);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.attempt'), [
                'email' => 'target@nadt.gov.in',
                'password' => 'WrongPassword1!',
            ]);
        }

        $response = $this->post(route('login.attempt'), [
            'email' => 'target@nadt.gov.in',
            'password' => 'Password@123',  // correct, but must still be refused
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Too many sign-in attempts',
            (string) $response->getSession()->get('errors')->first('email'),
        );
        $this->assertGuest();
    }

    #[Test]
    public function a_user_can_sign_out(): void
    {
        $user = User::factory()->role(RoleSlug::USER)->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function forgot_password_returns_the_same_response_for_known_and_unknown_addresses(): void
    {
        User::factory()->role(RoleSlug::USER)->create(['email' => 'known@nadt.gov.in']);

        $known = $this->post(route('password.email'), ['email' => 'known@nadt.gov.in']);
        $unknown = $this->post(route('password.email'), ['email' => 'nobody@nadt.gov.in']);

        $known->assertSessionHas('status');
        $unknown->assertSessionHas('status');

        $this->assertSame(
            $known->getSession()->get('status'),
            $unknown->getSession()->get('status'),
        );
    }

    #[Test]
    public function a_known_address_is_actually_sent_a_reset_link(): void
    {
        // Guards the User model's notification wiring: the model uses
        // RoutesNotifications rather than Notifiable, and notify() must survive.
        Notification::fake();

        $user = User::factory()->role(RoleSlug::USER)->create(['email' => 'known@nadt.gov.in']);

        $this->post(route('password.email'), ['email' => 'known@nadt.gov.in']);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    #[Test]
    public function security_headers_are_present_on_responses(): void
    {
        $this->get(route('login'))
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    #[Test]
    public function an_authenticated_user_visiting_login_is_sent_home(): void
    {
        $user = User::factory()->role(RoleSlug::ADMIN)->create();

        $this->actingAs($user)
            ->get(route('login'))
            ->assertRedirect();
    }
}
