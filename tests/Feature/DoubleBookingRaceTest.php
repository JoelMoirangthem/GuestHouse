<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Models\Allotment;
use App\Models\BookingRequest;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tariff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DoubleBookingRaceTest — TESTING.md section 2, the Phase 5 gate:
 * "a concurrent double-booking test proves the lock holds".
 *
 * Unlike AllotmentTest, which calls the service twice in sequence, every attempt
 * here runs in a SEPARATE PHP PROCESS with its own MySQL connection, released
 * together from a file barrier. Nothing is shared but the database, exactly as
 * with two administrators at two desks.
 *
 * This class cannot use RefreshDatabase: that wraps each test in a transaction
 * the child processes could never see. It commits real rows instead and
 * truncates every table before and after, so no other test inherits its data.
 */
class DoubleBookingRaceTest extends TestCase
{
    private User $admin;

    private User $employee;

    private RoomType $type;

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The race test needs MySQL row locks; the configured driver is '.DB::connection()->getDriverName().'.');
        }

        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }

        $this->truncateAll();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->role(RoleSlug::ADMIN)->create();
        $manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $this->employee = User::factory()->role(RoleSlug::USER)->reportingTo($manager)->create();
        $this->type = RoomType::factory()->create(['default_capacity' => 2]);
        Tariff::factory()->create(['room_type_id' => $this->type->id, 'amount_per_night' => '1000.00']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->truncateAll();
        }

        parent::tearDown();
    }

    // ================================================================= tests

    #[Test]
    public function four_administrators_racing_for_one_room_produce_exactly_one_allotment(): void
    {
        $room = Room::factory()->ofType($this->type)->number('101')->create();

        // Four approved requests whose stays all include the night of the 21st,
        // with DIFFERENT start dates. The unique (room_id, occupies) index only
        // catches identical start dates, so three of these four collisions can be
        // stopped by nothing except the row lock and the re-check inside it.
        $requests = collect([
            ['2026-10-20', '2026-10-23'],
            ['2026-10-21', '2026-10-24'],
            ['2026-10-19', '2026-10-22'],
            ['2026-10-21', '2026-10-22'],
        ])->map(fn ($d) => $this->approvedRequest($d[0], $d[1]));

        $results = $this->race($requests->map(fn ($r) => [$r->id, $room->id])->all());

        $winners = array_filter($results, fn ($r) => $r['ok']);
        $losers = array_filter($results, fn ($r) => ! $r['ok']);

        $this->assertCount(1, $winners, 'Expected exactly one winner: '.json_encode($results));
        $this->assertCount(3, $losers);

        foreach ($losers as $l) {
            // A clean, user-facing refusal — not a deadlock, a lock timeout or a 500.
            $this->assertSame('RuntimeException', $l['class'], json_encode($l));
            $this->assertStringContainsString('just taken by another booking', $l['message']);
        }

        $this->assertSame(1, Allotment::where('room_id', $room->id)->occupying()->count());
        $this->assertSame(1, BookingRequest::where('status', RequestStatus::ALLOTTED->value)->count());
        $this->assertSame(3, BookingRequest::where('status', RequestStatus::PENDING_ALLOTMENT->value)->count(),
            'A losing request must be left untouched, ready to be allotted another room.');
    }

    #[Test]
    public function identical_dates_racing_are_also_refused_cleanly(): void
    {
        $room = Room::factory()->ofType($this->type)->number('102')->create();
        $a = $this->approvedRequest('2026-10-20', '2026-10-23');
        $b = $this->approvedRequest('2026-10-20', '2026-10-23');

        $results = $this->race([[$a->id, $room->id], [$b->id, $room->id]]);

        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['ok'])), json_encode($results));
        $this->assertSame(1, Allotment::where('room_id', $room->id)->occupying()->count());
    }

    #[Test]
    public function the_recheck_after_the_lock_sees_a_booking_committed_while_it_waited(): void
    {
        // Deterministic interleaving of layer 3. This process takes the room's
        // row lock first; the child starts, reads nothing yet, and blocks on the
        // lock. While it waits, this process commits a competing allotment. When
        // the lock is released the child must re-verify and refuse — trusting
        // any read made before the lock would double-book the room.
        $room = Room::factory()->ofType($this->type)->number('103')->create();
        $holder = $this->approvedRequest('2026-10-20', '2026-10-23');
        $waiter = $this->approvedRequest('2026-10-21', '2026-10-24');

        DB::beginTransaction();
        DB::table('rooms')->where('id', $room->id)->lockForUpdate()->first();

        [$proc, $pipes, $ready, $go] = $this->spawn($waiter->id, $room->id);
        $this->waitFor([$ready]);
        touch($go);
        usleep(1_500_000);   // the child is now inside allot(), blocked on the row lock

        Allotment::factory()->forRoom($room)->dates('2026-10-20', '2026-10-23')
            ->create(['booking_request_id' => $holder->id]);
        DB::commit();

        $result = $this->collect($proc, $pipes);

        $this->assertFalse($result['ok'], json_encode($result));
        $this->assertSame('RuntimeException', $result['class']);
        $this->assertStringContainsString('just taken by another booking', $result['message']);
        $this->assertSame(1, Allotment::where('room_id', $room->id)->occupying()->count());
        $this->assertSame(RequestStatus::PENDING_ALLOTMENT, $waiter->fresh()->status);
    }

    #[Test]
    public function racing_for_different_rooms_does_not_block_either_administrator(): void
    {
        // The lock must be per room, not a global mutex: two admins allotting
        // different rooms at the same moment must both succeed.
        $r1 = Room::factory()->ofType($this->type)->number('104')->create();
        $r2 = Room::factory()->ofType($this->type)->number('105')->create();
        $a = $this->approvedRequest('2026-10-20', '2026-10-23');
        $b = $this->approvedRequest('2026-10-20', '2026-10-23');

        $results = $this->race([[$a->id, $r1->id], [$b->id, $r2->id]]);

        $this->assertSame([true, true], array_column($results, 'ok'), json_encode($results));
        $this->assertSame(2, Allotment::occupying()->count());
    }

    // ============================================================= harness

    private function approvedRequest(string $in, string $out): BookingRequest
    {
        return BookingRequest::factory()->for_($this->employee)
            ->status(RequestStatus::PENDING_ALLOTMENT)->members(1)->dates($in, $out)->create();
    }

    /**
     * Start one child per attempt, wait until all have booted, then release them
     * together.
     *
     * @param  array<int, array{0: int, 1: int}>  $attempts  [requestId, roomId]
     * @return array<int, array{ok: bool, class: ?string, message: ?string}>
     */
    private function race(array $attempts): array
    {
        $go = $this->tempPath('go');
        $children = [];

        foreach ($attempts as [$requestId, $roomId]) {
            $children[] = $this->spawn($requestId, $roomId, $go);
        }

        $this->waitFor(array_column($children, 2));
        touch($go);

        return array_map(fn ($c) => $this->collect($c[0], $c[1]), $children);
    }

    /** @return array{0: resource, 1: array<int, resource>, 2: string, 3: string} */
    private function spawn(int $requestId, int $roomId, ?string $go = null): array
    {
        $ready = $this->tempPath('ready');
        $go ??= $this->tempPath('go');

        $proc = proc_open(
            [PHP_BINARY, base_path('tests/Support/race_allot.php'), (string) $requestId, (string) $this->admin->id,
                (string) $roomId, $ready, $go],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $this->childEnv(),
        );

        $this->assertIsResource($proc, 'Could not start a child PHP process.');

        return [$proc, $pipes, $ready, $go];
    }

    /** @return array{ok: bool, class: ?string, message: ?string} */
    private function collect($proc, array $pipes): array
    {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $decoded = json_decode(trim((string) $out), true);
        $this->assertIsArray($decoded, "Child produced no result.\nstdout: {$out}\nstderr: {$err}");

        return $decoded;
    }

    /** @param  array<int, string>  $files */
    private function waitFor(array $files): void
    {
        $deadline = microtime(true) + 60;

        while (array_filter($files, fn ($f) => ! file_exists($f)) !== []) {
            $this->assertLessThan($deadline, microtime(true), 'Child processes did not boot in time.');
            usleep(20_000);
        }
    }

    /**
     * The children must hit the same test database and must never send mail.
     *
     * @return array<string, string>
     */
    private function childEnv(): array
    {
        $db = config('database.connections.mysql');

        return array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'],
            'DB_PASSWORD' => (string) $db['password'],
            'DB_URL' => '',
            'MAIL_MAILER' => 'array',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'NOTIFY_SMS_ENABLED' => 'false',
        ]);
    }

    private function tempPath(string $label): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gh_race_'.$label.'_'.bin2hex(random_bytes(6));
        $this->tempFiles[] = $path;

        return $path;
    }

    private function truncateAll(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];
            if ($table !== 'migrations') {
                DB::table($table)->truncate();
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
