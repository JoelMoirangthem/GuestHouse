<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Services\ReportService;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Domain\Enums\VisitPurpose;
use App\Models\Allotment;
use App\Models\BookingRequest;
use App\Models\Feedback;
use App\Models\Notification;
use App\Models\RequestOccupant;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dashboards and the seven reports — REPORTS.md. The Phase 8 gate:
 * "seeded data reproduces correct counts; exports open cleanly".
 *
 * The fixture is small and hand-counted so every expected figure below can be
 * checked with a pencil. October 2026 has 31 days.
 *
 *   Rooms:  STD × 4 in service + 1 under maintenance, VIP × 1   → 5 in service
 *   R1  e1  SELF      CHECKED_OUT     S1 05→08 (3 nights) ₹3000 realised
 *   R2  e1  TRAINING  EARLY_CHECKOUT  S2 10→15 but left on the 12th (2 nights) ₹2000 realised
 *   R3  e2  GUEST     ALLOTTED        S2 12→14 (2 nights) ₹3000 projected — the room R2 vacated
 *                                     V1 12→14 CANCELLED (must be ignored everywhere)
 *   R4  e2  SELF      REJECTED_MANAGER
 *   R5  e1  SELF      DRAFT           (never counted)
 *   R6  e2  SELF      CANCELLED, submitted in September (outside the period)
 *   R7  e1  SELF      PENDING_MANAGER
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-10-01';

    private const TO = '2026-10-31';

    private User $admin;

    private User $adg;

    private User $managerA;

    private User $managerB;

    private User $e1;

    private User $e2;

    /** @var array<string, BookingRequest> */
    private array $r = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow('2026-10-15 12:00:00');

        $this->admin = User::factory()->role(RoleSlug::ADMIN)->create(['name' => 'Report Admin']);
        $this->adg = User::factory()->role(RoleSlug::ADG)->create();
        $this->managerA = User::factory()->role(RoleSlug::MANAGER)->create();
        $this->managerB = User::factory()->role(RoleSlug::MANAGER)->create();
        $this->e1 = User::factory()->role(RoleSlug::USER)->reportingTo($this->managerA)->create(['name' => 'Anita E1', 'department' => 'Training']);
        $this->e2 = User::factory()->role(RoleSlug::USER)->reportingTo($this->managerB)->create(['name' => 'Bharat E2', 'department' => 'Audit']);

        $std = RoomType::factory()->create(['code' => 'STD', 'name' => 'Standard AC', 'sort_order' => 1]);
        $vip = RoomType::factory()->vip()->create(['code' => 'VIP', 'sort_order' => 2]);
        $s1 = Room::factory()->ofType($std)->number('101')->create();
        $s2 = Room::factory()->ofType($std)->number('102')->create();
        Room::factory()->ofType($std)->number('103')->create();
        Room::factory()->ofType($std)->number('104')->create();
        Room::factory()->ofType($std)->number('105')->maintenance()->create();
        $v1 = Room::factory()->ofType($vip)->number('201')->create();

        $req = fn (User $u, RequestStatus $s, VisitPurpose $p, ?string $submitted, string $in, string $out, array $extra = []) => BookingRequest::factory()
            ->for_($u)->status($s)->purpose($p)->dates($in, $out)
            ->create(['submitted_at' => $submitted, ...$extra]);

        $approved = ['manager_acted_at' => '2026-10-02 10:00:00', 'adg_acted_at' => '2026-10-02 12:00:00'];

        $this->r['R1'] = $req($this->e1, RequestStatus::CHECKED_OUT, VisitPurpose::SELF, '2026-10-02 09:00:00', '2026-10-05', '2026-10-08', $approved);
        $this->r['R2'] = $req($this->e1, RequestStatus::EARLY_CHECKOUT, VisitPurpose::TRAINING, '2026-10-03 09:00:00', '2026-10-10', '2026-10-15', $approved);
        $this->r['R3'] = $req($this->e2, RequestStatus::ALLOTTED, VisitPurpose::GUEST, '2026-10-05 09:00:00', '2026-10-12', '2026-10-14', $approved);
        $this->r['R4'] = $req($this->e2, RequestStatus::REJECTED_MANAGER, VisitPurpose::SELF, '2026-10-06 09:00:00', '2026-10-20', '2026-10-21', ['manager_acted_at' => '2026-10-07 09:00:00']);
        $this->r['R5'] = $req($this->e1, RequestStatus::DRAFT, VisitPurpose::SELF, null, '2026-10-25', '2026-10-26');
        $this->r['R6'] = $req($this->e2, RequestStatus::CANCELLED, VisitPurpose::SELF, '2026-09-20 09:00:00', '2026-10-01', '2026-10-02');
        $this->r['R7'] = $req($this->e1, RequestStatus::PENDING_MANAGER, VisitPurpose::SELF, '2026-10-07 09:00:00', '2026-10-28', '2026-10-30', ['manager_id' => $this->managerA->id]);

        $allot = fn (BookingRequest $r, Room $room, string $in, string $out, string $status, string $rate, string $total, array $extra = []) => Allotment::factory()
            ->forRoom($room)->dates($in, $out)
            ->create(['booking_request_id' => $r->id, 'status' => $status, 'rate_per_night' => $rate, 'total_amount' => $total, ...$extra]);

        $allot($this->r['R1'], $s1, '2026-10-05', '2026-10-08', 'CHECKED_OUT', '1000.00', '3000.00', [
            'allotted_at' => '2026-10-03 10:00:00', 'actual_check_in_at' => '2026-10-05 12:00:00', 'actual_check_out_at' => '2026-10-08 10:00:00']);
        $allot($this->r['R2'], $s2, '2026-10-10', '2026-10-15', 'EARLY_CHECKOUT', '1000.00', '2000.00', [
            'allotted_at' => '2026-10-04 10:00:00', 'actual_check_in_at' => '2026-10-10 12:00:00', 'actual_check_out_at' => '2026-10-12 09:00:00']);
        $allot($this->r['R3'], $s2, '2026-10-12', '2026-10-14', 'ALLOTTED', '1500.00', '3000.00', ['allotted_at' => '2026-10-06 10:00:00']);
        $allot($this->r['R3'], $v1, '2026-10-12', '2026-10-14', 'CANCELLED', '5000.00', '10000.00', ['allotted_at' => '2026-10-06 10:00:00']);

        $fb = new Feedback(['rating_cleanliness' => 4, 'rating_staff' => 5, 'rating_facilities' => 3, 'rating_overall' => 4,
            'comments' => '=HYPERLINK("http://evil.example","click")']);
        $fb->booking_request_id = $this->r['R1']->id;
        $fb->user_id = $this->e1->id;
        $fb->submitted_at = '2026-10-09 10:00:00';
        $fb->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function build(string $type, ?User $as = null)
    {
        return app(ReportService::class)->build($type, self::FROM, self::TO, $as ?? $this->admin);
    }

    // ============================================================ dashboard

    #[Test]
    public function the_dashboard_tiles_match_the_fixture(): void
    {
        Notification::create(['user_id' => $this->e1->id, 'event_key' => 'rooms.allotted', 'channel' => 'EMAIL',
            'title' => 't', 'body' => 'b', 'status' => 'FAILED']);

        $tiles = app(ReportService::class)->dashboardTiles(self::FROM, self::TO);

        $this->assertSame([
            'total' => 5,                // R1 R2 R3 R4 R7; not the draft, not September's R6
            'pending_manager' => 1,      // R7
            'pending_adg' => 0,
            'awaiting_allotment' => 0,
            'rooms_allotted' => 3,       // rooms, not requests; the cancelled V1 row is excluded
            'failed_notifications' => 1,
        ], $tiles);
    }

    #[Test]
    public function daily_occupancy_does_not_double_count_a_room_vacated_early(): void
    {
        $byDay = collect(app(ReportService::class)->occupancyByDay(self::FROM, self::TO))->keyBy('date');

        $this->assertCount(31, $byDay);
        $this->assertSame(1, $byDay['2026-10-05']['occupied']);
        $this->assertSame(0, $byDay['2026-10-08']['occupied'], 'Checkout day is free (half-open interval).');
        $this->assertSame(1, $byDay['2026-10-11']['occupied']);
        // R2 left on the 12th and R3 took the same room: one room, not two.
        $this->assertSame(1, $byDay['2026-10-12']['occupied']);
        $this->assertSame(20.0, $byDay['2026-10-12']['pct']);   // 1 of 5 rooms in service
        $this->assertSame(0, $byDay['2026-10-14']['occupied']);
    }

    #[Test]
    public function the_dashboard_renders_and_pages_by_month(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('October 2026')
            ->assertSee('Rooms allotted')
            ->assertSee('Standard AC');

        $this->get(route('dashboard', ['month' => '2026-09']))->assertOk()->assertSee('September 2026');
        $this->get(route('dashboard', ['month' => 'not-a-month']))->assertSessionHasErrors('month');
    }

    // ============================================================== reports

    #[Test]
    public function the_occupancy_report_uses_rooms_in_service_as_the_base(): void
    {
        $rep = $this->build('occupancy');
        $std = collect($rep->rows)->firstWhere('type', 'Standard AC');

        $this->assertSame(4, $std['rooms']);                 // maintenance room excluded
        $this->assertSame(124, $std['nights_available']);    // 4 × 31
        $this->assertSame(7, $std['nights_occupied']);       // 3 + 2 + 2, not 3 + 5 + 2
        $this->assertSame(5.6, $std['pct']);
        $this->assertSame(155, $rep->summary['Room-nights available']);
        $this->assertSame('4.5%', $rep->summary['Occupancy']);
    }

    #[Test]
    public function the_booking_report_lists_submitted_requests_only(): void
    {
        $nos = collect($this->build('booking')->rows)->pluck('request_no')->all();

        $this->assertEqualsCanonicalizing(
            [$this->r['R1']->request_no, $this->r['R2']->request_no, $this->r['R3']->request_no, $this->r['R4']->request_no, $this->r['R7']->request_no],
            $nos,
        );

        $r3 = collect($this->build('booking')->rows)->firstWhere('request_no', $this->r['R3']->request_no);
        $this->assertSame(1, $r3['rooms'], 'The cancelled V1 row is not an allotted room.');
        $this->assertStringStartsWith('Approved', $r3['adg_decision']);

        $r4 = collect($this->build('booking')->rows)->firstWhere('request_no', $this->r['R4']->request_no);
        $this->assertStringStartsWith('Rejected', $r4['manager_decision']);
    }

    #[Test]
    public function a_manager_sees_only_their_reportees_in_team_scoped_reports(): void
    {
        $a = collect($this->build('booking', $this->managerA)->rows)->pluck('request_no')->sort()->values()->all();
        $b = collect($this->build('booking', $this->managerB)->rows)->pluck('request_no')->sort()->values()->all();

        $this->assertSame(collect([$this->r['R1'], $this->r['R2'], $this->r['R7']])->pluck('request_no')->sort()->values()->all(), $a);
        $this->assertSame(collect([$this->r['R3'], $this->r['R4']])->pluck('request_no')->sort()->values()->all(), $b);

        $this->assertCount(2, $this->build('allotment', $this->managerA)->rows);   // R1, R2
        $this->assertSame(['Anita E1'], collect($this->build('user-wise', $this->managerA)->rows)->pluck('user')->all());
        $this->assertSame('Your reportees only', $this->build('booking', $this->managerA)->scopeLabel);

        // The ADG sees everything.
        $this->assertCount(5, $this->build('booking', $this->adg)->rows);
        $this->assertNull($this->build('booking', $this->adg)->scopeLabel);
    }

    #[Test]
    public function the_allotment_report_includes_released_rows_so_it_reconciles_with_the_desk(): void
    {
        $rows = collect($this->build('allotment')->rows);

        $this->assertCount(4, $rows);
        $this->assertSame(1, $rows->where('status', 'Cancelled')->count());
    }

    #[Test]
    public function the_user_wise_report_totals_room_nights_and_amounts(): void
    {
        $rows = collect($this->build('user-wise')->rows)->keyBy('user');

        $this->assertSame(['submitted' => 3, 'approved' => 2, 'rejected' => 0, 'room_nights' => 5, 'amount' => '5000.00'],
            collect($rows['Anita E1'])->only(['submitted', 'approved', 'rejected', 'room_nights', 'amount'])->all());
        $this->assertSame(['submitted' => 2, 'approved' => 1, 'rejected' => 1, 'room_nights' => 2, 'amount' => '3000.00'],
            collect($rows['Bharat E2'])->only(['submitted', 'approved', 'rejected', 'room_nights', 'amount'])->all());
    }

    #[Test]
    public function the_purpose_wise_report_computes_approval_rate_over_decided_requests(): void
    {
        $rows = collect($this->build('purpose-wise')->rows)->keyBy('purpose');

        // SELF: R1 approved, R4 rejected, R7 undecided → 1 of 2 decided.
        $this->assertSame(3, $rows[VisitPurpose::SELF->label()]['requests']);
        $this->assertSame(50.0, $rows[VisitPurpose::SELF->label()]['approval_rate']);
        $this->assertSame('3000.00', $rows[VisitPurpose::SELF->label()]['revenue']);

        $this->assertSame(100.0, $rows[VisitPurpose::TRAINING->label()]['approval_rate']);
        $this->assertSame(2, $rows[VisitPurpose::TRAINING->label()]['room_nights']);
        $this->assertSame('0.00', $rows[VisitPurpose::GUEST->label()]['revenue'], 'An open stay is not realised revenue.');
    }

    #[Test]
    public function revenue_keeps_realised_and_projected_apart(): void
    {
        $rep = $this->build('revenue');

        $this->assertSame('₹5000.00', $rep->summary['Realised']);    // R1 3000 + R2 2000 (early, recharged)
        $this->assertSame('₹3000.00', $rep->summary['Projected']);   // R3; the cancelled ₹10,000 never appears
        $std = collect($rep->rows)->firstWhere('type', 'Standard AC');
        $this->assertSame(2, $std['stays']);
        $this->assertSame(1, $std['upcoming']);
    }

    #[Test]
    public function the_feedback_report_computes_response_rate_and_averages(): void
    {
        $rep = $this->build('feedback');

        $this->assertSame(2, $rep->summary['Completed stays']);   // R1, R2
        $this->assertSame(1, $rep->summary['Responses']);
        $this->assertSame('50%', $rep->summary['Response rate']);
        $this->assertSame('4.00', $rep->summary['Avg. overall']);
    }

    // =============================================================== access

    #[Test]
    public function an_employee_cannot_reach_any_report(): void
    {
        $this->actingAs($this->e1)->get(route('reports.index'))->assertForbidden();

        foreach (array_keys(ReportService::TYPES) as $type) {
            $this->actingAs($this->e1)->get(route('reports.show', $type))->assertForbidden();
            $this->actingAs($this->e1)->get(route('reports.export', [$type, 'xlsx']))->assertForbidden();
        }
    }

    #[Test]
    public function revenue_is_admin_only_on_screen_and_in_every_export(): void
    {
        foreach ([$this->managerA, $this->adg] as $user) {
            $this->actingAs($user)->get(route('reports.show', 'revenue'))->assertForbidden();
            $this->actingAs($user)->get(route('reports.export', ['revenue', 'xlsx']))->assertForbidden();
            $this->actingAs($user)->get(route('reports.export', ['revenue', 'pdf']))->assertForbidden();
            $this->actingAs($user)->get(route('reports.index'))->assertOk()->assertDontSee('Revenue Report');
        }

        $this->actingAs($this->admin)->get(route('reports.show', 'revenue'))->assertOk()->assertSee('₹5000.00', false);
    }

    #[Test]
    public function every_report_renders_for_every_role_allowed_to_see_it(): void
    {
        foreach ([$this->managerA, $this->adg, $this->admin] as $user) {
            foreach (array_keys(app(ReportService::class)->availableFor($user)) as $type) {
                $this->actingAs($user)
                    ->get(route('reports.show', ['type' => $type, 'from' => self::FROM, 'to' => self::TO]))
                    ->assertOk();
            }
        }
    }

    #[Test]
    public function an_unknown_report_type_or_format_is_not_found(): void
    {
        $this->actingAs($this->admin)->get('/reports/salaries')->assertNotFound();
        $this->actingAs($this->admin)->get('/reports/booking/export/csv')->assertNotFound();
    }

    #[Test]
    public function the_period_is_validated(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('reports.show', ['type' => 'booking', 'from' => '2026-10-31', 'to' => '2026-10-01']))
            ->assertSessionHasErrors('to');
        $this->get(route('reports.show', ['type' => 'booking', 'from' => '31/10/2026']))
            ->assertSessionHasErrors('from');
        $this->get(route('reports.show', ['type' => 'occupancy', 'from' => '2020-01-01', 'to' => '2026-10-01']))
            ->assertStatus(422);
    }

    // ============================================================== exports

    #[Test]
    public function the_xlsx_export_opens_and_carries_the_required_header(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('reports.export', ['type' => 'booking', 'format' => 'xlsx', 'from' => self::FROM, 'to' => self::TO]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $sheet = $this->openXlsx($response->getContent())->getActiveSheet();
        $cells = collect($sheet->toArray())->flatten()->filter()->map(fn ($v) => (string) $v);

        $this->assertSame('Booking Report', $sheet->getCell('A1')->getValue());
        $this->assertTrue($cells->contains('Generated by'));
        $this->assertTrue($cells->contains('Report Admin'));
        $this->assertTrue($cells->contains('01/10/2026 to 31/10/2026'));
        $this->assertTrue($cells->contains(config('gh.institution').', '.config('gh.city')));
        $this->assertTrue($cells->contains($this->r['R7']->request_no));
    }

    #[Test]
    public function free_text_cannot_become_a_live_formula_in_the_xlsx(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('reports.export', ['type' => 'feedback', 'format' => 'xlsx', 'from' => self::FROM, 'to' => self::TO]))
            ->assertOk();

        $sheet = $this->openXlsx($response->getContent())->getActiveSheet();
        $found = false;

        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if (str_starts_with((string) $cell->getValue(), '=HYPERLINK')) {
                    $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), 'A comment was written as a formula.');
                    $found = true;
                }
                $this->assertNotSame(DataType::TYPE_FORMULA, $cell->getDataType());
            }
        }

        $this->assertTrue($found, 'The comment should appear in the export as literal text.');
    }

    #[Test]
    public function the_pdf_export_is_a_real_pdf(): void
    {
        foreach (['booking', 'occupancy', 'revenue'] as $type) {
            $response = $this->actingAs($this->admin)
                ->get(route('reports.export', ['type' => $type, 'format' => 'pdf', 'from' => self::FROM, 'to' => self::TO]))
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');

            $this->assertStringStartsWith('%PDF-', $response->getContent());
        }
    }

    #[Test]
    public function no_identity_number_appears_in_any_report_or_export(): void
    {
        // PiiMaskingTest, export half: reports carry no identity columns at all.
        $occupant = RequestOccupant::factory()->create(['booking_request_id' => $this->r['R1']->id, 'name' => 'Occupant One', 'id_proof_type' => 'AADHAAR']);
        $occupant->setIdProofNumber('9876 5432 1098');
        $occupant->save();

        $this->actingAs($this->admin);
        foreach (array_keys(ReportService::TYPES) as $type) {
            $q = ['type' => $type, 'from' => self::FROM, 'to' => self::TO];
            $html = $this->get(route('reports.show', $q))->getContent();
            $xlsx = $this->openXlsx($this->get(route('reports.export', [...$q, 'format' => 'xlsx']))->getContent());
            $text = json_encode($xlsx->getActiveSheet()->toArray());

            foreach (['987654321098', '9876 5432 1098'] as $needle) {
                $this->assertStringNotContainsString($needle, $html, "{$type} screen leaks {$needle}");
                $this->assertStringNotContainsString($needle, $text, "{$type} xlsx leaks {$needle}");
            }
        }
    }

    private function openXlsx(string $bytes): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'rpt').'.xlsx';
        file_put_contents($path, $bytes);

        try {
            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }
}
