<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Services\NotificationDispatcher;
use App\Application\Services\SettingsRegistry;
use App\Domain\Enums\NotificationEvent;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Models\AuditLog;
use App\Models\BookingRequest;
use App\Models\EmailTemplate;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 9 admin screens: settings, notification templates, audit log viewer.
 */
class AdminConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private EmailTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(EmailTemplateSeeder::class);
        $this->admin = User::factory()->role(RoleSlug::ADMIN)->create();
        $this->template = EmailTemplate::where('event_key', NotificationEvent::ROOMS_ALLOTTED->value)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function settingsPayload(array $overrides = []): array
    {
        return [
            'institution' => 'NADT, RC, DTRTI', 'building' => 'Pragya Bhawan', 'city' => 'Lucknow, Uttar Pradesh',
            'pin' => '226002', 'id_proof_retention_months' => 12,
            'notify_email' => '1', 'notify_sms' => '0', 'notify_in_app' => '1',
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function templatePayload(array $overrides = []): array
    {
        return [
            'subject' => 'Rooms ready — {{request_no}}',
            'body_html' => '<p>Dear {{user_name}}, room(s) {{rooms}} are allotted.</p>',
            'body_text' => 'Dear {{user_name}}, room(s) {{rooms}} are allotted.',
            'sms_text' => 'Rooms allotted. Ref {{request_no}}.',
            'is_active' => '1',
            ...$overrides,
        ];
    }

    // ============================================================= access

    #[Test]
    public function only_the_administrator_reaches_these_screens(): void
    {
        foreach ([RoleSlug::USER, RoleSlug::MANAGER, RoleSlug::ADG] as $slug) {
            $u = User::factory()->role($slug)->create();
            $this->actingAs($u)->get(route('admin.settings.edit'))->assertForbidden();
            $this->actingAs($u)->put(route('admin.settings.update'), $this->settingsPayload(['pin' => '110001']))->assertForbidden();
            $this->actingAs($u)->get(route('admin.email-templates.index'))->assertForbidden();
            $this->actingAs($u)->put(route('admin.email-templates.update', $this->template), $this->templatePayload())->assertForbidden();
            $this->actingAs($u)->get(route('admin.audit-logs.index'))->assertForbidden();
        }

        $this->assertSame('226002', config('gh.pin'));
        $this->assertStringNotContainsString('Rooms ready', $this->template->fresh()->subject);
    }

    #[Test]
    public function every_screen_renders_for_the_admin(): void
    {
        $this->actingAs($this->admin);
        $this->get(route('admin.settings.edit'))->assertOk()->assertSee('Institution name');
        $this->get(route('admin.email-templates.index'))->assertOk()->assertSee('rooms.allotted');
        $this->get(route('admin.email-templates.edit', $this->template))->assertOk()->assertSee('{{request_no}}', false)->assertSee('sandbox', false);
        $this->get(route('admin.audit-logs.index'))->assertOk();
    }

    // =========================================================== settings

    #[Test]
    public function a_saved_setting_overrides_the_default_and_survives_a_reboot(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), $this->settingsPayload(['building' => 'Pragya Bhawan Annexe', 'id_proof_retention_months' => 18]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Pragya Bhawan Annexe', config('gh.building'));
        $this->assertSame(18, config('gh.id_proof_retention_months'));

        // Simulate the next request's boot: reset config to .env defaults, reapply.
        config(['gh.building' => 'Pragya Bhawan', 'gh.id_proof_retention_months' => 12]);
        SettingsRegistry::applyToConfig();
        $this->assertSame('Pragya Bhawan Annexe', config('gh.building'));
        $this->assertSame(18, config('gh.id_proof_retention_months'));

        $log = AuditLog::where('action', 'SETTINGS_UPDATED')->firstOrFail();
        $this->assertEqualsCanonicalizing(['building', 'id_proof_retention_months'], array_keys($log->metadata));
    }

    #[Test]
    public function switching_a_channel_off_stops_that_channel(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), $this->settingsPayload(['notify_email' => '0']))
            ->assertSessionHasNoErrors();

        $manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $employee = User::factory()->role(RoleSlug::USER)->reportingTo($manager)->create();
        $r = BookingRequest::factory()->for_($employee)->status(RequestStatus::ALLOTTED)->create();

        app(NotificationDispatcher::class)->dispatch(NotificationEvent::ROOMS_ALLOTTED, $r);

        $this->assertSame(['IN_APP'], Notification::where('booking_request_id', $r->id)->pluck('channel')->unique()->values()->all());
    }

    #[Test]
    public function settings_are_validated(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), $this->settingsPayload(['pin' => '2260', 'id_proof_retention_months' => 0, 'institution' => '']))
            ->assertSessionHasErrors(['pin', 'id_proof_retention_months', 'institution']);

        $this->assertSame(0, Setting::count());
    }

    #[Test]
    public function the_mail_credential_cannot_be_reached_from_the_settings_screen(): void
    {
        Setting::put('google_refresh_token', 'super-secret-token', 'SECRET', 'mail');

        $html = $this->actingAs($this->admin)->get(route('admin.settings.edit'))->getContent();
        $this->assertStringNotContainsString('google_refresh_token', $html);
        $this->assertStringNotContainsString('super-secret-token', $html);

        $this->put(route('admin.settings.update'), $this->settingsPayload(['google_refresh_token' => 'attacker']))
            ->assertSessionHasNoErrors();
        $this->assertSame('super-secret-token', Setting::get('google_refresh_token'));
    }

    // ========================================================== templates

    #[Test]
    public function an_edited_template_is_used_for_the_next_notification(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.email-templates.update', $this->template), $this->templatePayload())
            ->assertSessionHasNoErrors();

        $manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $employee = User::factory()->role(RoleSlug::USER)->reportingTo($manager)->create();
        $r = BookingRequest::factory()->for_($employee)->status(RequestStatus::ALLOTTED)->create();
        app(NotificationDispatcher::class)->dispatch(NotificationEvent::ROOMS_ALLOTTED, $r, ['rooms' => '204']);

        $email = Notification::where('booking_request_id', $r->id)->where('channel', 'EMAIL')->firstOrFail();
        $this->assertStringContainsString("Dear {$employee->name}, room(s) 204 are allotted.", $email->body);

        $log = AuditLog::where('action', 'TEMPLATE_UPDATED')->firstOrFail();
        $this->assertSame('rooms.allotted', $log->metadata['event_key']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function forbiddenContent(): array
    {
        return [
            'unknown placeholder' => ['body_html', '<p>{{aadhaar_number}} {{password}}</p>'],
            'identity reference' => ['body_text', 'Your Aadhaar is on file.'],
            'document link' => ['body_html', '<a href="/documents/5">your ID</a>'],
            '12-digit number' => ['sms_text', 'Ref 1234 5678 9012'],
            'script tag' => ['body_html', '<p>Hi</p><script>alert(1)</script>'],
            'event handler' => ['body_html', '<img src="x" onerror="alert(1)">'],
            'javascript url' => ['body_html', '<a href="javascript:alert(1)">x</a>'],
            'sms too long' => ['sms_text', str_repeat('a', 321)],
        ];
    }

    #[Test]
    #[DataProvider('forbiddenContent')]
    public function unsafe_template_content_is_refused(string $field, string $value): void
    {
        $before = $this->template->fresh()->toArray();

        $this->actingAs($this->admin)
            ->put(route('admin.email-templates.update', $this->template), $this->templatePayload([$field => $value]))
            ->assertSessionHasErrors($field);

        $this->assertSame($before['body_html'], $this->template->fresh()->body_html);
        $this->assertSame(0, AuditLog::where('action', 'TEMPLATE_UPDATED')->count());
    }

    #[Test]
    public function templates_cannot_be_created_or_deleted(): void
    {
        $this->actingAs($this->admin)->post('/admin/email-templates', $this->templatePayload())->assertStatus(405);
        $this->actingAs($this->admin)->delete(route('admin.email-templates.update', $this->template))->assertStatus(405);
        $this->assertSame(count(NotificationEvent::cases()), EmailTemplate::count());
    }

    // ========================================================== audit log

    #[Test]
    public function the_audit_log_lists_and_filters_entries(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->settingsPayload(['pin' => '110001']));
        $this->actingAs($this->admin)->put(route('admin.email-templates.update', $this->template), $this->templatePayload());

        $this->get(route('admin.audit-logs.index'))->assertOk()
            ->assertSee('Settings changed')->assertSee('Notification template edited');

        $this->get(route('admin.audit-logs.index', ['action' => 'SETTINGS_UPDATED']))->assertOk()
            ->assertSee('Settings changed')->assertDontSee('Notification template edited');

        $this->get(route('admin.audit-logs.index', ['from' => '2026-12-01', 'to' => '2026-11-01']))->assertSessionHasErrors('to');
    }

    #[Test]
    public function the_audit_log_has_no_write_route(): void
    {
        $writes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'audit'))
            ->flatMap(fn ($r) => $r->methods())
            ->reject(fn ($m) => in_array($m, ['GET', 'HEAD'], true));

        $this->assertSame([], $writes->values()->all());
    }

    #[Test]
    public function metadata_is_escaped_when_displayed(): void
    {
        app(\App\Application\Services\AuditLogger::class)
            ->record($this->admin, 'TEST_ENTRY', $this->admin, remarks: '<script>alert(1)</script>', metadata: ['x' => '<b>bold</b>']);

        $html = $this->actingAs($this->admin)->get(route('admin.audit-logs.index'))->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
