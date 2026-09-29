<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Services\AllotmentService;
use App\Application\Services\StayService;
use App\Domain\Contracts\PdfGenerator;
use App\Domain\Contracts\QrGenerator;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Models\Allotment;
use App\Models\BookingRequest;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tariff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Allotment letter PDF with check-in QR — SCREENS.md 3.5, ROUTES.md.
 */
class AllotmentLetterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $employee;

    private RoomType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->role(RoleSlug::ADMIN)->create();
        $this->manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $this->employee = User::factory()->role(RoleSlug::USER)->reportingTo($this->manager)->create();

        $this->type = RoomType::factory()->create(['default_capacity' => 2, 'name' => 'Deluxe AC']);
        Tariff::factory()->create(['room_type_id' => $this->type->id, 'amount_per_night' => '1500.00']);
    }

    /** Captures the HTML handed to the PDF renderer, so content can be asserted. */
    private function spyPdf(): object
    {
        $spy = new class implements PdfGenerator
        {
            public string $html = '';

            public function fromView(string $view, array $data = [], string $paper = 'a4', string $orientation = 'portrait'): string
            {
                $this->html = view($view, $data)->render();

                return '%PDF-spy';
            }
        };

        $this->app->instance(PdfGenerator::class, $spy);

        return $spy;
    }

    private function request(int $members = 1, int $rooms = 1): BookingRequest
    {
        $request = BookingRequest::factory()
            ->for_($this->employee)
            ->status(RequestStatus::PENDING_ALLOTMENT)
            ->members($members)
            ->dates(today()->format('Y-m-d'), today()->addDays(2)->format('Y-m-d'))
            ->create();

        $request->occupants()->create(['name' => 'Primary Guest', 'is_primary' => true]);

        if ($rooms === 0) {
            return $request;
        }

        $ids = collect(range(1, $rooms))
            ->map(fn ($i) => Room::factory()->ofType($this->type)->number('30'.$i)->create()->id)
            ->all();

        return app(AllotmentService::class)->allot($request, $this->admin, $ids);
    }

    // ------------------------------------------------------------ real render

    #[Test]
    public function the_real_renderer_produces_a_valid_pdf(): void
    {
        $request = $this->request();

        $response = $this->actingAs($this->employee)->get(route('my.requests.letter', $request));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('%%EOF', substr($response->getContent(), -64));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    // ------------------------------------------------------------ content

    #[Test]
    public function the_letter_lists_every_room_and_the_total(): void
    {
        $spy = $this->spyPdf();
        $request = $this->request(members: 4, rooms: 2);
        $this->assertSame(RequestStatus::ALLOTTED, $request->status);

        $this->actingAs($this->employee)->get(route('my.requests.letter', $request))->assertOk();

        $this->assertStringContainsString($request->request_no, $spy->html);
        $this->assertStringContainsString('301', $spy->html);
        $this->assertStringContainsString('302', $spy->html);
        $this->assertStringContainsString('Deluxe AC', $spy->html);
        // 2 rooms x 2 nights x 1500
        $this->assertStringContainsString('6,000.00', $spy->html);
    }

    #[Test]
    public function the_qr_code_encodes_a_token_the_desk_accepts(): void
    {
        $spy = $this->spyPdf();
        $request = $this->request();
        $token = Allotment::where('booking_request_id', $request->id)->value('qr_token');

        $this->actingAs($this->employee)->get(route('my.requests.letter', $request))->assertOk();

        $expected = app(QrGenerator::class)->dataUri($token, 180);
        $this->assertStringContainsString($expected, $spy->html, 'The letter must embed the QR for this allotment token.');

        // And that token genuinely checks the party in at the desk.
        $this->actingAs($this->admin)
            ->post(route('admin.stays.qr'), ['token' => $token])
            ->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::CHECKED_IN, $request->fresh()->status);
    }

    #[Test]
    public function the_letter_never_prints_identity_numbers(): void
    {
        $spy = $this->spyPdf();
        $request = $this->request();
        $occupant = $request->occupants()->first();
        $occupant->setIdProofNumber('987654321012');
        $occupant->save();

        $this->actingAs($this->employee)->get(route('my.requests.letter', $request))->assertOk();

        $this->assertStringNotContainsString('987654321012', $spy->html);
        $this->assertStringNotContainsString('9012', $spy->html);
    }

    #[Test]
    public function the_qr_generator_produces_distinct_svg_per_token(): void
    {
        $qr = app(QrGenerator::class);

        $a = $qr->svg(str_repeat('a', 40));
        $b = $qr->svg(str_repeat('b', 40));

        $this->assertStringContainsString('<svg', $a);
        $this->assertNotSame($a, $b);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $qr->dataUri('x'));
    }

    // ------------------------------------------------------------ state rules

    #[Test]
    public function no_letter_before_every_room_is_allotted(): void
    {
        $pending = $this->request(rooms: 0);
        $this->actingAs($this->employee)->get(route('my.requests.letter', $pending))->assertStatus(409);

        $partial = $this->request(members: 6, rooms: 1);   // needs 3
        $this->assertSame(RequestStatus::PARTIALLY_ALLOTTED, $partial->status);
        $this->actingAs($this->employee)->get(route('my.requests.letter', $partial))->assertStatus(409);
    }

    #[Test]
    public function the_letter_remains_available_during_the_stay(): void
    {
        $this->spyPdf();
        $request = app(StayService::class)->checkIn($this->request(), $this->admin);

        $this->actingAs($this->employee)->get(route('my.requests.letter', $request))->assertOk();
    }

    #[Test]
    public function no_letter_after_checkout(): void
    {
        $request = app(StayService::class)->checkIn($this->request(), $this->admin);
        $request = app(StayService::class)->checkOut($request, $this->admin);

        $this->actingAs($this->employee)->get(route('my.requests.letter', $request))->assertStatus(409);
    }

    // ------------------------------------------------------------ access

    #[Test]
    public function only_the_applicant_may_download_their_letter(): void
    {
        $this->spyPdf();
        $request = $this->request();

        // The manager may view the request, but not take home its QR credential.
        $this->actingAs($this->manager)->get(route('my.requests.letter', $request))->assertForbidden();
        $this->actingAs(User::factory()->role(RoleSlug::USER)->create())
            ->get(route('my.requests.letter', $request))->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->get(route('my.requests.letter', $request))->assertRedirect(route('login'));
    }

    #[Test]
    public function the_admin_letter_route_is_admin_only(): void
    {
        $this->spyPdf();
        $request = $this->request();
        $allotment = Allotment::where('booking_request_id', $request->id)->firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.allotments.letter', $allotment))->assertOk();

        foreach ([$this->employee, $this->manager, User::factory()->role(RoleSlug::ADG)->create()] as $user) {
            $this->actingAs($user)->get(route('admin.allotments.letter', $allotment))->assertForbidden();
        }
    }

    #[Test]
    public function a_released_room_has_no_letter(): void
    {
        $request = $this->request(members: 4, rooms: 2);
        $allotment = Allotment::where('booking_request_id', $request->id)->orderBy('id')->firstOrFail();

        app(AllotmentService::class)->release($allotment, $this->admin, 'Guest count reduced');

        $this->actingAs($this->admin)->get(route('admin.allotments.letter', $allotment->fresh()))->assertStatus(409);
    }

    #[Test]
    public function the_download_buttons_appear_only_when_a_letter_exists(): void
    {
        $request = $this->request();

        $this->actingAs($this->employee)->get(route('my.requests.show', $request))
            ->assertSee(route('my.requests.letter', $request));

        $this->actingAs($this->admin)->get(route('admin.allotments.show', $request))
            ->assertSee('Print allotment letter');

        $pending = $this->request(rooms: 0);
        $this->actingAs($this->employee)->get(route('my.requests.show', $pending))
            ->assertDontSee(route('my.requests.letter', $pending));
    }
}
