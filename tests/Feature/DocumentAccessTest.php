<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Services\DocumentStorageService;
use App\Domain\Enums\RoleSlug;
use App\Models\BookingRequest;
use App\Models\RequestDocument;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TESTING.md — DocumentAccessTest.
 *
 * The Phase 3 gate. A leaked Aadhaar scan is the worst realistic failure of this
 * system, so these assertions are the most important in the phase.
 */
class DocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $stranger;

    private BookingRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $this->owner = User::factory()->role(RoleSlug::USER)->reportingTo($manager)->create();
        $this->stranger = User::factory()->role(RoleSlug::USER)->reportingTo($manager)->create();

        $this->request = BookingRequest::factory()->for_($this->owner)->create();
    }

    private function storeRealDocument(): RequestDocument
    {
        Storage::fake('private');

        return app(DocumentStorageService::class)->store(
            $this->request,
            UploadedFile::fake()->create('Aadhaar Card.pdf', 120, 'application/pdf'),
            $this->owner,
        );
    }

    // ------------------------------------------------------------------ access

    #[Test]
    public function the_owner_can_view_their_own_identity_document(): void
    {
        $doc = $this->storeRealDocument();

        $this->actingAs($this->owner)
            ->get(route('documents.show', $doc))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    #[Test]
    public function an_unrelated_employee_is_refused(): void
    {
        $doc = $this->storeRealDocument();

        $this->actingAs($this->stranger)
            ->get(route('documents.show', $doc))
            ->assertForbidden();
    }

    #[Test]
    public function an_anonymous_visitor_is_refused(): void
    {
        $doc = $this->storeRealDocument();

        $this->get(route('documents.show', $doc))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function the_reporting_manager_the_adg_and_the_admin_may_view_it(): void
    {
        $doc = $this->storeRealDocument();

        // The manager the request routes to, plus both authorities, legitimately
        // need to see the proof in order to decide.
        $manager = $this->owner->reportingManager;
        $adg = User::factory()->role(RoleSlug::ADG)->create();
        $admin = User::factory()->role(RoleSlug::ADMIN)->create();

        foreach ([$manager, $adg, $admin] as $viewer) {
            $this->actingAs($viewer)
                ->get(route('documents.show', $doc))
                ->assertOk();
        }
    }

    #[Test]
    public function any_manager_may_view_it_because_one_manager_handles_every_booking(): void
    {
        $doc = $this->storeRealDocument();

        $otherManager = User::factory()->role(RoleSlug::MANAGER)->create();

        $this->actingAs($otherManager)
            ->get(route('documents.show', $doc))
            ->assertOk();
    }

    // ------------------------------------------------- no public URL exists

    #[Test]
    public function stored_documents_are_not_reachable_by_a_guessed_public_url(): void
    {
        $doc = $this->storeRealDocument();

        // The exact paths an attacker would try first.
        $candidates = [
            '/storage/'.$doc->stored_path,
            '/'.$doc->stored_path,
            '/storage/app/private/'.$doc->stored_path,
            '/public/storage/'.$doc->stored_path,
        ];

        foreach ($candidates as $url) {
            $response = $this->actingAs($this->owner)->get($url);

            $this->assertNotEquals(
                200,
                $response->getStatusCode(),
                "Document was served directly at {$url} — the private disk is leaking."
            );
        }
    }

    #[Test]
    public function the_private_disk_has_file_serving_disabled(): void
    {
        // If this flips to true, Laravel registers a route that streams files by
        // path and the policy check above becomes bypassable.
        $this->assertFalse(
            config('filesystems.disks.private.serve'),
            'The private disk must never serve files over HTTP.'
        );
    }

    #[Test]
    public function documents_live_outside_the_public_directory(): void
    {
        $root = config('filesystems.disks.private.root');

        $this->assertStringNotContainsString(
            DIRECTORY_SEPARATOR.'public',
            $root,
            'Identity documents must not be stored anywhere under public/.'
        );
    }

    #[Test]
    public function the_stored_path_is_hidden_from_serialisation(): void
    {
        $doc = $this->storeRealDocument();

        // Prevents the internal filesystem location leaking through an API
        // response or a debug page.
        $this->assertArrayNotHasKey('stored_path', $doc->toArray());
    }

    // ------------------------------------------------- upload hardening

    #[Test]
    public function the_stored_filename_is_a_uuid_and_not_the_users_filename(): void
    {
        $doc = $this->storeRealDocument();

        $this->assertStringNotContainsString('Aadhaar', $doc->stored_path);
        $this->assertMatchesRegularExpression(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf$/',
            $doc->stored_path,
        );
        // The human-readable name survives for display only.
        $this->assertSame('Aadhaar Card.pdf', $doc->original_filename);
    }

    #[Test]
    public function an_executable_disguised_as_a_pdf_is_rejected(): void
    {
        Storage::fake('private');

        // A real file on disk is required here. UploadedFile::fake() derives its
        // MIME type from the filename, so it would report "application/pdf" and
        // the test would pass without proving anything. Writing an actual file
        // makes getMimeType() run finfo content sniffing, which is the control
        // being tested.
        $tmp = tempnam(sys_get_temp_dir(), 'probe');
        $disguised = $tmp.'.pdf';
        file_put_contents($disguised, '<?php system($_GET["c"]); ?>');

        $file = new UploadedFile(
            path: $disguised,
            originalName: 'shell.pdf',
            mimeType: 'application/pdf',   // what the attacker claims
            error: null,
            test: true,
        );

        // Confirm the premise: content sniffing disagrees with the claimed type.
        $this->assertNotSame('application/pdf', $file->getMimeType());

        try {
            app(DocumentStorageService::class)->store($this->request, $file, $this->owner);
            $this->fail('A PHP script disguised as a PDF was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Unsupported file type', $e->getMessage());
        } finally {
            @unlink($tmp);
            @unlink($disguised);
        }
    }

    #[Test]
    public function a_sha256_checksum_is_recorded_and_verifiable(): void
    {
        $doc = $this->storeRealDocument();

        $this->assertSame(64, strlen($doc->sha256));
        $this->assertTrue(app(DocumentStorageService::class)->verifyIntegrity($doc));
    }

    #[Test]
    public function every_document_access_is_recorded(): void
    {
        $doc = $this->storeRealDocument();

        // An unrecorded Aadhaar access is unrecoverable information, so the
        // audit write is asserted rather than assumed (SECURITY.md section 7).
        $this->actingAs($this->owner)->get(route('documents.show', $doc))->assertOk();
        $this->actingAs($this->owner)->get(route('documents.show', $doc))->assertOk();

        $rows = \App\Models\AuditLog::where('action', 'DOCUMENT_VIEWED')->get();
        $this->assertCount(2, $rows, 'Each view writes its own row.');
        $this->assertSame($this->owner->id, $rows->first()->actor_id);
        $this->assertSame($doc->booking_request_id, $rows->first()->auditable_id);
        $this->assertSame($doc->id, $rows->first()->metadata['document_id']);

        // A refused viewer leaves no "viewed" row.
        $stranger = User::factory()->role(\App\Domain\Enums\RoleSlug::USER)->create();
        $this->actingAs($stranger)->get(route('documents.show', $doc))->assertForbidden();
        $this->assertSame(2, \App\Models\AuditLog::where('action', 'DOCUMENT_VIEWED')->count());
    }
}
