<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Services\DocumentStorageService;
use App\Domain\Enums\RoleSlug;
use App\Models\AuditLog;
use App\Models\BookingRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SECURITY.md section 7 — the Phase 9 items not already covered by the
 * authorization, document and race suites.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'GPS-SECRET-LAT-26.8467';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        RateLimiter::clear('');
    }

    // ======================================================== error pages

    #[Test]
    public function a_server_error_page_leaks_nothing_with_debug_off(): void
    {
        config(['app.debug' => false]);
        Route::get('/_test/boom', fn () => throw new \RuntimeException('SQLSTATE secret-table /var/www/app/Secret.php'))->middleware('web');

        $html = $this->get('/_test/boom')->assertStatus(500)->getContent();

        $this->assertStringContainsString('Something went wrong on our side', $html);
        foreach (['SQLSTATE', 'secret-table', 'Secret.php', 'Stack trace', 'vendor', 'RuntimeException'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, "The 500 page leaks '{$leak}'.");
        }
    }

    #[Test]
    public function not_found_and_forbidden_pages_are_the_institutions_own(): void
    {
        config(['app.debug' => false]);

        $this->get('/no-such-page')->assertNotFound()->assertSee('Page not found')->assertDontSee('Symfony');

        $this->actingAs(User::factory()->role(RoleSlug::USER)->create())
            ->get(route('admin.users.index'))
            ->assertForbidden()
            ->assertSee('You do not have access to this page')
            ->assertSee('You do not have permission to access this area.');   // the app's own abort() message
    }

    #[Test]
    public function an_expired_form_shows_a_plain_explanation(): void
    {
        // Laravel skips CSRF verification under unit tests, so raise the exact
        // exception VerifyCsrfToken throws and let the real handler render it.
        // A JSON caller still gets the 419 page; browsers are redirected (below).
        config(['app.debug' => false]);
        Route::post('/_test/csrf', fn () => throw new \Illuminate\Session\TokenMismatchException('CSRF token mismatch.'))->middleware('web');

        $this->postJson('/_test/csrf')
            ->assertStatus(419);

        // No usable referer: the browser lands on sign-in, never on an error page.
        $this->post('/_test/csrf')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('session');
    }

    #[Test]
    public function an_expired_sign_in_form_returns_to_the_form_with_the_email_kept(): void
    {
        config(['app.debug' => false]);
        Route::post('/_test/csrf', fn () => throw new \Illuminate\Session\TokenMismatchException('CSRF token mismatch.'))->middleware('web');

        $this->withHeader('referer', route('login'))
            ->post('/_test/csrf', ['email' => 'manager@nadt.gov.in', 'password' => 'secret-value'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['session' => 'This page had been open for a while, so it was refreshed. Please submit again.'])
            ->assertSessionHasInput('email', 'manager@nadt.gov.in')
            ->assertSessionMissing('_old_input.password');

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('This page had been open for a while')
            ->assertSee('value="manager@nadt.gov.in"', false);
    }

    #[Test]
    public function an_idle_signed_in_page_goes_to_sign_in_and_returns_afterwards(): void
    {
        config(['app.debug' => false]);
        Route::post('/_test/csrf', fn () => throw new \Illuminate\Session\TokenMismatchException('CSRF token mismatch.'))->middleware('web');

        $this->withHeader('referer', route('home'))
            ->post('/_test/csrf')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('session')
            ->assertSessionHas('url.intended', route('home'));
    }

    #[Test]
    public function only_guest_pages_keep_their_form_token_fresh(): void
    {
        $this->get(route('login'))->assertSee('name="gh-csrf-refresh"', false);
        $this->get(route('public.booking'))->assertSee('name="gh-csrf-refresh"', false);

        // Signed in, the idle timeout must stay in force: no keep-alive.
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user)->get(route('public.booking'))->assertDontSee('name="gh-csrf-refresh"', false);
    }

    // ============================================================ headers

    #[Test]
    public function responses_carry_a_content_security_policy_and_hide_the_php_version(): void
    {
        $response = $this->get(route('login'));

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString("'unsafe-inline'", explode('style-src', $csp)[0], 'Inline scripts must not be allowed.');
        $this->assertFalse($response->headers->has('X-Powered-By'));
    }

    #[Test]
    public function hsts_is_sent_only_over_https(): void
    {
        $this->get(route('login'))->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    #[Test]
    public function no_page_contains_an_inline_script_the_policy_would_block(): void
    {
        foreach (glob(resource_path('views/**/*.blade.php')) ?: [] as $f) {
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', (string) file_get_contents($f), basename($f).' has an inline <script>.');
        }
        foreach (glob(resource_path('views/*/*/*.blade.php')) ?: [] as $f) {
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', (string) file_get_contents($f), basename($f).' has an inline <script>.');
        }
    }

    // ============================================================ EXIF

    private function storeUpload(UploadedFile $file): string
    {
        Storage::fake('private');
        $manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $owner = User::factory()->role(RoleSlug::USER)->reportingTo($manager)->create();
        $request = BookingRequest::factory()->for_($owner)->create();

        $doc = app(DocumentStorageService::class)->store($request, $file, $owner);
        $bytes = Storage::disk('private')->get($doc->stored_path);

        $this->assertSame(hash('sha256', $bytes), $doc->sha256, 'The checksum must describe the stored, cleaned file.');
        $this->assertSame(strlen($bytes), $doc->size_bytes);

        return $bytes;
    }

    #[Test]
    public function exif_metadata_is_stripped_from_uploaded_photos(): void
    {
        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();

        // Splice an APP1 "Exif" segment carrying the marker in after SOI (FFD8).
        $payload = "Exif\0\0".self::MARKER;
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
        $dirty = substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
        $this->assertStringContainsString(self::MARKER, $dirty);

        $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
        file_put_contents($path, $dirty);
        $stored = $this->storeUpload(new UploadedFile($path, 'phone-photo.jpg', 'image/jpeg', null, true));
        @unlink($path);

        $this->assertStringNotContainsString(self::MARKER, $stored);
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertNotFalse(@imagecreatefromstring($stored), 'The cleaned file must still be a valid image.');
    }

    #[Test]
    public function png_text_chunks_are_stripped_too(): void
    {
        $img = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();

        // Insert a tEXt chunk after the 8-byte signature + 25-byte IHDR chunk.
        $data = 'Comment'."\0".self::MARKER;
        $chunk = pack('N', strlen($data)).'tEXt'.$data.pack('N', crc32('tEXt'.$data));
        $dirty = substr($png, 0, 33).$chunk.substr($png, 33);

        $path = tempnam(sys_get_temp_dir(), 'png').'.png';
        file_put_contents($path, $dirty);
        $stored = $this->storeUpload(new UploadedFile($path, 'scan.png', 'image/png', null, true));
        @unlink($path);

        $this->assertStringNotContainsString(self::MARKER, $stored);
    }

    #[Test]
    public function a_decompression_bomb_is_refused_before_decoding(): void
    {
        // A tiny PNG whose header claims 30000 × 30000 pixels (3.6 GB decoded).
        $ihdr = pack('NNCCCCC', 30000, 30000, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr))
            .pack('N', 0).'IEND'.pack('N', crc32('IEND'));

        $path = tempnam(sys_get_temp_dir(), 'bomb').'.png';
        file_put_contents($path, $png);

        Storage::fake('private');
        $owner = User::factory()->role(RoleSlug::USER)->create();
        $request = BookingRequest::factory()->for_($owner)->create();

        try {
            app(DocumentStorageService::class)->store($request, new UploadedFile($path, 'bomb.png', 'image/png', null, true), $owner);
            $this->fail('A decompression bomb was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('too large', $e->getMessage());
        } finally {
            @unlink($path);
        }

        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    #[Test]
    public function a_pdf_is_stored_byte_for_byte(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
        $path = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        file_put_contents($path, $pdf);

        $this->assertSame($pdf, $this->storeUpload(new UploadedFile($path, 'id.pdf', 'application/pdf', null, true)));
        @unlink($path);
    }

    // ========================================================== sign-in audit

    #[Test]
    public function successful_and_failed_sign_ins_are_audited_without_the_password(): void
    {
        $user = User::factory()->role(RoleSlug::USER)->create(['email' => 'asha@example.gov.in']);

        $this->post(route('login.attempt'), ['email' => 'asha@example.gov.in', 'password' => 'Wrong#Pass123']);
        $this->post(route('login.attempt'), ['email' => 'nobody@example.gov.in', 'password' => 'Wrong#Pass456']);
        $this->post(route('login.attempt'), ['email' => 'asha@example.gov.in', 'password' => 'Password@123'])->assertRedirect();

        $failed = AuditLog::where('action', 'LOGIN_FAILED')->orderBy('id')->get();
        $this->assertCount(2, $failed);
        $this->assertSame($user->id, $failed[0]->auditable_id);
        $this->assertNull($failed[0]->actor_id, 'A failed attempt is not proof of who made it.');
        $this->assertSame('auth', $failed[1]->auditable_type, 'An unknown email has no account to attach to.');
        $this->assertSame('nobody@example.gov.in', $failed[1]->metadata['email']);

        $ok = AuditLog::where('action', 'LOGIN_SUCCEEDED')->firstOrFail();
        $this->assertSame($user->id, $ok->actor_id);
        $this->assertNotNull($ok->ip_address);

        $all = json_encode(AuditLog::all()->map->getAttributes());
        foreach (['Wrong#Pass123', 'Wrong#Pass456', 'Password@123'] as $pw) {
            $this->assertStringNotContainsString($pw, $all);
        }
    }

    // ========================================================= password reuse

    #[Test]
    public function a_reset_to_one_of_the_last_three_passwords_is_refused(): void
    {
        $user = User::factory()->role(RoleSlug::USER)->create(['password' => Hash::make('First#Pass2026')]);
        $user->forceFill(['password' => Hash::make('Second#Pass2026')])->save();
        $user->forceFill(['password' => Hash::make('Third#Pass2026')])->save();
        $user->forceFill(['password' => Hash::make('Fourth#Pass2026')])->save();

        $reset = function (string $pw) use ($user) {
            $token = Password::createToken($user);

            return $this->post(route('password.update'), [
                'token' => $token, 'email' => $user->email, 'password' => $pw, 'password_confirmation' => $pw,
            ]);
        };

        foreach (['Fourth#Pass2026', 'Third#Pass2026', 'Second#Pass2026'] as $recent) {
            $reset($recent)->assertSessionHasErrors('password');
        }
        $this->assertTrue(Hash::check('Fourth#Pass2026', $user->fresh()->password), 'A refused reset changes nothing.');

        // The fourth-most-recent has aged out of the rule.
        $reset('First#Pass2026')->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('First#Pass2026', $user->fresh()->password));

        $this->assertLessThanOrEqual(3, DB::table('password_histories')->where('user_id', $user->id)->count(), 'Only what the rule needs is kept.');
    }

    #[Test]
    public function an_admin_reset_to_a_recent_password_is_refused(): void
    {
        $admin = User::factory()->role(RoleSlug::ADMIN)->create();
        $manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $user = User::factory()->role(RoleSlug::USER)->reportingTo($manager)->create(['password' => Hash::make('Current#Pass2026')]);

        $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'name' => $user->name, 'email' => $user->email, 'employee_code' => $user->employee_code,
            'role_id' => Role::where('slug', 'user')->value('id'), 'reporting_manager_id' => $manager->id, 'is_active' => '1',
            'password' => 'Current#Pass2026', 'password_confirmation' => 'Current#Pass2026',
        ])->assertSessionHasErrors('user');
    }

    #[Test]
    public function the_reset_rule_makes_no_outbound_call(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $user = User::factory()->role(RoleSlug::USER)->create();
        $token = Password::createToken($user);

        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'Brand#New2026x', 'password_confirmation' => 'Brand#New2026x',
        ])->assertRedirect(route('login'));
    }

    // ============================================================= audit log

    #[Test]
    public function no_application_code_updates_or_deletes_audit_rows(): void
    {
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (preg_match("/table\\(\\s*'audit_logs'\\s*\\)[^;]*->(update|delete|truncate)\\(/s", $src)
                || preg_match('/AuditLog::[^;]*->(update|delete|forceDelete)\\(/s', $src)) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, 'SECURITY.md section 5: audit_logs is append-only.');
    }

    #[Test]
    public function forgot_password_is_throttled_after_three_requests_a_minute(): void
    {
        $user = User::factory()->role(RoleSlug::USER)->create();
        \Illuminate\Support\Facades\Notification::fake();

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
        }

        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasErrors('email');
        $this->assertStringContainsStringIgnoringCase('too many', session('errors')->first('email'));
    }

    #[Test]
    public function the_deployment_check_fails_on_a_development_configuration(): void
    {
        config(['app.debug' => true, 'session.secure' => false]);
        $this->artisan('gh:security-check')->expectsOutputToContain('FAIL APP_DEBUG is off')->assertFailed();
    }
}
