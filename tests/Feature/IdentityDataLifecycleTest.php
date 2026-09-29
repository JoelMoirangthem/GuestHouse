<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Models\Allotment;
use App\Models\AuditLog;
use App\Models\BookingRequest;
use App\Models\RequestDocument;
use App\Models\RequestOccupant;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SECURITY.md section 2 — the audited Aadhaar reveal and ID-proof retention.
 */
class IdentityDataLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const NUMBER = '987654321098';

    private User $admin;

    private User $manager;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow('2027-12-01 10:00:00');

        $this->admin = User::factory()->role(RoleSlug::ADMIN)->create();
        $this->manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $this->employee = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A request with one occupant holding a full number and one stored file. */
    private function withIdData(RequestStatus $status, array $attrs = []): BookingRequest
    {
        $r = BookingRequest::factory()->for_($this->employee)->status($status)->dates('2026-10-10', '2026-10-12')->create($attrs);
        $o = RequestOccupant::factory()->withAadhaar(self::NUMBER)->create(['booking_request_id' => $r->id]);
        $doc = RequestDocument::factory()->create(['booking_request_id' => $r->id, 'request_occupant_id' => $o->id, 'uploaded_by' => $this->employee->id]);
        Storage::disk('private')->put($doc->stored_path, '%PDF-1.4 fake');

        return $r;
    }

    private function checkedOut(string $when): BookingRequest
    {
        $r = $this->withIdData(RequestStatus::CHECKED_OUT);
        Allotment::factory()->forRoom(Room::factory()->create())->dates('2026-10-10', '2026-10-12')->create([
            'booking_request_id' => $r->id, 'status' => 'CHECKED_OUT',
            'actual_check_in_at' => '2026-10-10 12:00:00', 'actual_check_out_at' => $when,
        ]);

        return $r;
    }

    private function occupant(BookingRequest $r): RequestOccupant
    {
        return RequestOccupant::where('booking_request_id', $r->id)->firstOrFail();
    }

    // =============================================================== reveal

    #[Test]
    public function the_admin_can_reveal_a_number_with_a_reason_and_the_reveal_is_audited(): void
    {
        $r = $this->withIdData(RequestStatus::ALLOTTED);
        $o = $this->occupant($r);

        $this->actingAs($this->admin)->get(route('admin.allotments.show', $r))
            ->assertOk()->assertSee('XXXX XXXX 1098')->assertDontSee(self::NUMBER);

        $response = $this->post(route('admin.occupants.reveal', $o), ['reason' => 'Verifying identity at the desk'])
            ->assertOk()
            ->assertSee(self::NUMBER);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $log = AuditLog::where('action', 'ID_PROOF_REVEALED')->firstOrFail();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame('Verifying identity at the desk', $log->remarks);
        $this->assertStringNotContainsString(self::NUMBER, json_encode($log->getAttributes()), 'The number itself must never be audited.');

        // Nothing about the number lands in the session store.
        $this->assertFalse(DB::table('sessions')->where('payload', 'like', '%'.self::NUMBER.'%')->exists());
    }

    #[Test]
    public function a_reveal_without_a_reason_is_refused_and_not_audited(): void
    {
        $o = $this->occupant($this->withIdData(RequestStatus::ALLOTTED));

        $this->actingAs($this->admin)->post(route('admin.occupants.reveal', $o), ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertSame(0, AuditLog::where('action', 'ID_PROOF_REVEALED')->count());
    }

    #[Test]
    public function nobody_but_the_admin_can_reveal_not_even_the_owner(): void
    {
        $o = $this->occupant($this->withIdData(RequestStatus::ALLOTTED));

        foreach ([$this->employee, $this->manager, User::factory()->role(RoleSlug::ADG)->create()] as $u) {
            $this->actingAs($u)->post(route('admin.occupants.reveal', $o), ['reason' => 'curious about it'])
                ->assertForbidden();
        }
        $this->assertSame(0, AuditLog::where('action', 'ID_PROOF_REVEALED')->count());
    }

    #[Test]
    public function reveal_cannot_be_reached_by_get(): void
    {
        $o = $this->occupant($this->withIdData(RequestStatus::ALLOTTED));

        $this->actingAs($this->admin)->get('/admin/occupants/'.$o->id.'/reveal')->assertStatus(405);
    }

    // ============================================================ retention

    #[Test]
    public function data_past_the_window_is_purged_and_the_masked_form_survives(): void
    {
        $old = $this->checkedOut('2026-10-12 10:00:00');       // > 12 months before 2027-12-01
        $path = RequestDocument::where('booking_request_id', $old->id)->value('stored_path');

        $this->artisan('gh:purge-id-proofs')
            ->expectsOutputToContain('Purged 1 request(s): 1 file(s), 1 identity number(s)')
            ->assertSuccessful();

        Storage::disk('private')->assertMissing($path);
        $this->assertSame(0, RequestDocument::where('booking_request_id', $old->id)->count());

        $o = $this->occupant($old)->fresh();
        $this->assertNull($o->getRawOriginal('id_proof_number'));
        $this->assertSame('XXXX XXXX 1098', $o->maskedIdProof(), 'History screens still show the masked form.');

        $this->assertTrue(AuditLog::where('action', 'ID_PROOFS_PURGED')->where('auditable_id', $old->id)->exists());
        $this->assertSame(RequestStatus::CHECKED_OUT, $old->fresh()->status, 'The request itself is kept.');
    }

    #[Test]
    public function data_inside_the_window_is_kept(): void
    {
        $recent = $this->checkedOut('2027-01-15 10:00:00');   // < 12 months ago
        $active = $this->withIdData(RequestStatus::CHECKED_IN);
        $pending = $this->withIdData(RequestStatus::PENDING_MANAGER, ['updated_at' => '2025-01-01']);

        $this->artisan('gh:purge-id-proofs')->expectsOutputToContain('Purged 0 request(s)')->assertSuccessful();

        foreach ([$recent, $active, $pending] as $r) {
            $this->assertSame(1, RequestDocument::where('booking_request_id', $r->id)->count());
            $this->assertNotNull($this->occupant($r)->fresh()->getRawOriginal('id_proof_number'));
        }
    }

    #[Test]
    public function a_rejected_request_is_purged_on_the_same_window(): void
    {
        $rejected = $this->withIdData(RequestStatus::REJECTED_MANAGER);
        DB::table('booking_requests')->where('id', $rejected->id)->update(['updated_at' => '2026-06-01 10:00:00']);

        $this->artisan('gh:purge-id-proofs')->assertSuccessful();

        $this->assertSame(0, RequestDocument::where('booking_request_id', $rejected->id)->count());
    }

    #[Test]
    public function the_window_follows_the_setting(): void
    {
        $r = $this->checkedOut('2027-01-15 10:00:00');   // ~10.5 months ago
        config(['gh.id_proof_retention_months' => 6]);

        $this->artisan('gh:purge-id-proofs')->assertSuccessful();

        $this->assertSame(0, RequestDocument::where('booking_request_id', $r->id)->count());
    }

    #[Test]
    public function a_dry_run_deletes_nothing(): void
    {
        $old = $this->checkedOut('2026-10-12 10:00:00');

        $this->artisan('gh:purge-id-proofs', ['--dry-run' => true])
            ->expectsOutputToContain('Would purge 1 request(s)')
            ->assertSuccessful();

        $this->assertSame(1, RequestDocument::where('booking_request_id', $old->id)->count());
        $this->assertSame(0, AuditLog::where('action', 'ID_PROOFS_PURGED')->count());
    }

    #[Test]
    public function the_purge_is_idempotent_and_scheduled_nightly(): void
    {
        $this->checkedOut('2026-10-12 10:00:00');
        $this->artisan('gh:purge-id-proofs')->assertSuccessful();
        $this->artisan('gh:purge-id-proofs')->expectsOutputToContain('Purged 0 request(s)')->assertSuccessful();
        $this->assertSame(1, AuditLog::where('action', 'ID_PROOFS_PURGED')->count());

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'gh:purge-id-proofs'));
        $this->assertNotNull($event);
        $this->assertSame('0 2 * * *', $event->expression);
    }
}
