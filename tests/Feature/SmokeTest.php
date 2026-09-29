<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Application smoke test.
 *
 * Replaces Laravel's stock ExampleTest. The root URL is the public
 * requisition form, which employees use without signing in.
 */
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_root_url_shows_the_portal_landing_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Welcome to')
            ->assertSee('NADT, RC')
            ->assertSee('Lucknow')
            ->assertSee(route('public.booking'), false)
            ->assertSee(route('login'), false)
            // Reception numbers as tap-to-call links.
            ->assertSee('href="tel:+919918143306"', false)
            ->assertSee('href="tel:+919415984377"', false)
            ->assertSee('href="tel:+918477862418"', false)
            // Location opens Google Maps in a new tab.
            ->assertSee('https://www.google.com/maps/search/?api=1&amp;query=Pragya%20Bhawan%20NADT%20RC%20Lucknow', false)
            ->assertSee('Get directions');
    }

    #[Test]
    public function the_requisition_form_is_public(): void
    {
        $this->get(route('public.booking'))
            ->assertOk()
            ->assertSee('Room &amp; Stay Details', false)
            ->assertSee('Submit Requisition');
    }

    #[Test]
    public function an_admin_signing_in_lands_on_the_admin_dashboard(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $admin = \App\Models\User::factory()->role(\App\Domain\Enums\RoleSlug::ADMIN)->create();

        $this->post(route('login.attempt'), ['email' => $admin->email, 'password' => 'Password@123'])
            ->assertRedirect(route('home'));

        $this->get(route('home'))->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
    }

    #[Test]
    public function the_sign_in_screen_is_publicly_reachable(): void
    {
        $this->get('/login')->assertOk();
    }

    #[Test]
    public function the_health_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }

    #[Test]
    public function there_is_no_public_self_registration_route(): void
    {
        // SECURITY.md section 3: accounts are provisioned by an administrator.
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }
}
