<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoomStatus;
use App\Models\Allotment;
use App\Models\BookingRequest;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\StayExtension;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Whole-platform walkthrough, driven through HTTP exactly as a browser would.
 *
 * Uses the real DatabaseSeeder (seeded accounts, rooms, templates) rather than
 * factories, so it also proves the shipped seed data is usable end to end.
 *
 * At every workflow stage it renders every GET route as every role and fails on
 * any 5xx. That catches the class of bug the unit tests cannot: a view that
 * breaks only when a request is in a particular state.
 */
class PlatformWalkthroughTest extends TestCase
{
    use RefreshDatabase;

    /** GET routes that are not rendered pages or leave the application. */
    private const SKIP = [
        'oauth.google.callback',   // needs a live Google round trip
        'oauth.google.redirect',   // redirects away to Google
    ];

    /** @var array<string, User> */
    private array $as = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::preventStrayRequests();

        $this->seed(DatabaseSeeder::class);

        $this->as = [
            'user' => User::where('email', 'rajesh.kumar@nadt.gov.in')->firstOrFail(),
            'manager' => User::where('email', 'manager@nadt.gov.in')->firstOrFail(),
            'adg' => User::where('email', 'adg@nadt.gov.in')->firstOrFail(),
            'admin' => User::where('email', 'admin@nadt.gov.in')->firstOrFail(),
        ];
    }

    #[Test]
    public function the_seeded_accounts_can_sign_in_through_the_login_form(): void
    {
        foreach ($this->as as $role => $user) {
            $this->post(route('login.attempt'), [
                'email' => $user->email,
                'password' => UserSeeder::PASSWORD,
            ])->assertRedirect();

            $this->assertAuthenticatedAs($user);

            $this->post(route('logout'))->assertRedirect();
            $this->assertGuest();
        }
    }

    #[Test]
    public function a_request_travels_the_full_workflow_and_every_screen_renders_at_every_stage(): void
    {
        $this->crawlEverything('empty system');

        // ---- 1. Employee submits (T1)
        $this->actingAs($this->as['user'])
            ->post(route('my.requests.store'), [
                'purpose' => 'SELF',
                'check_in_date' => today()->format('Y-m-d'),
                'check_out_date' => today()->addDays(2)->format('Y-m-d'),
                'total_members' => 3,
                'contact_mobile' => '9876543210',
                'contact_email' => 'rajesh.kumar@nadt.gov.in',
                'remarks' => 'Official visit',
                'occupants' => [
                    ['name' => 'Rajesh Kumar', 'age' => 40, 'gender' => 'M', 'id_proof_type' => 'AADHAAR', 'id_proof_number' => '123412341234'],
                    ['name' => 'Sunita Kumar', 'age' => 38, 'gender' => 'F', 'relation' => 'Spouse'],
                    ['name' => 'Aarav Kumar', 'age' => 10, 'gender' => 'M', 'relation' => 'Son'],
                ],
                'documents' => [UploadedFile::fake()->create('aadhaar.pdf', 120, 'application/pdf')],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $request = BookingRequest::latest('id')->firstOrFail();
        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->status);
        $this->assertSame($this->as['manager']->id, $request->manager_id);
        $this->assertSame(2, $request->rooms_needed, '3 members at 2 per room');
        $this->crawlEverything('PENDING_MANAGER', $request);

        // ---- 2. Manager asks for more information (T4), employee resubmits (T5)
        $this->actingAs($this->as['manager'])
            ->post(route('manager.requests.moreInfo', $request), ['remarks' => 'Please confirm the purpose.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::MORE_INFO_MANAGER, $request->fresh()->status);
        $this->crawlEverything('MORE_INFO_MANAGER', $request);

        $this->actingAs($this->as['user'])
            ->post(route('my.requests.resubmit', $request))
            ->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::PENDING_MANAGER, $request->fresh()->status);

        // ---- 3. Manager approves (T2) — the only approval; goes straight to allotment
        $this->actingAs($this->as['manager'])
            ->post(route('manager.requests.approve', $request), ['remarks' => 'Approved'])
            ->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::PENDING_ALLOTMENT, $request->fresh()->status);
        $this->crawlEverything('PENDING_ALLOTMENT', $request);

        // The ADG stage has been removed; its more-info route never existed.
        $this->actingAs($this->as['adg'])
            ->post('/adg/requests/'.$request->id.'/more-info', ['remarks' => 'x'])
            ->assertStatus(404);

        // ---- 4. Admin allots one room, then the second (T9 then T11)
        $rooms = Room::where('status', RoomStatus::ACTIVE->value)->orderBy('id')->limit(2)->pluck('id')->all();
        $this->assertCount(2, $rooms, 'The seeder must provide at least two active rooms.');

        $this->actingAs($this->as['admin'])
            ->post(route('admin.allotments.store', $request), ['room_ids' => [$rooms[0]]])
            ->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::PARTIALLY_ALLOTTED, $request->fresh()->status);
        $this->crawlEverything('PARTIALLY_ALLOTTED', $request);

        // Decision 9: a partial allotment cannot check in.
        $this->actingAs($this->as['admin'])
            ->post(route('admin.stays.checkIn', $request))
            ->assertSessionHasErrors('stay');

        $this->actingAs($this->as['admin'])
            ->post(route('admin.allotments.store', $request), ['room_ids' => [$rooms[1]]])
            ->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::ALLOTTED, $request->fresh()->status);
        $this->crawlEverything('ALLOTTED', $request);

        // ---- 6. Check in (T12)
        $this->actingAs($this->as['admin'])
            ->post(route('admin.stays.checkIn', $request))
            ->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::CHECKED_IN, $request->fresh()->status);
        $this->crawlEverything('CHECKED_IN', $request);

        // ---- 7. Employee asks for an extension (T16), admin approves (T17)
        $this->actingAs($this->as['user'])
            ->post(route('my.requests.extension', $request), [
                'requested_check_out_date' => today()->addDays(3)->format('Y-m-d'),
                'reason' => 'Meeting extended',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::EXTENSION_REQUESTED, $request->fresh()->status);
        $this->crawlEverything('EXTENSION_REQUESTED', $request);

        $extension = StayExtension::where('booking_request_id', $request->id)->latest('id')->firstOrFail();
        $this->actingAs($this->as['admin'])
            ->post(route('admin.extensions.approve', $extension))
            ->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::CHECKED_IN, $request->fresh()->status);
        $this->assertSame(today()->addDays(3)->format('Y-m-d'), $request->fresh()->check_out_date->format('Y-m-d'));

        // ---- 8. Check out (T15 early, because the extended date is in the future)
        $this->actingAs($this->as['admin'])
            ->post(route('admin.stays.checkOut', $request))
            ->assertSessionHasNoErrors();
        $this->assertContains($request->fresh()->status, [RequestStatus::CHECKED_OUT, RequestStatus::EARLY_CHECKOUT]);
        $this->assertSame(0, Allotment::where('booking_request_id', $request->id)
            ->whereIn('status', ['ALLOTTED', 'CHECKED_IN'])->count(), 'Rooms must be released.');
        $this->crawlEverything('CHECKED_OUT', $request);

        // ---- 9. Feedback — the last step of the workflow (TESTING.md HappyPathWorkflowTest)
        $this->actingAs($this->as['user'])
            ->post(route('my.requests.feedback.store', $request), [
                'rating_cleanliness' => 5, 'rating_staff' => 4, 'rating_facilities' => 4, 'rating_overall' => 5,
                'comments' => 'Walkthrough feedback',
            ])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($request->fresh()->feedback);
        $this->crawlEverything('FEEDBACK_GIVEN', $request);

        // Every stakeholder heard about it in-app.
        foreach (['user', 'manager', 'adg'] as $role) {
            $this->assertGreaterThan(0, $this->as[$role]->notifications()->count(), "{$role} received no notifications");
        }
    }

    #[Test]
    public function a_rejected_and_a_cancelled_request_still_render_everywhere(): void
    {
        $rejected = $this->submitted();
        $this->actingAs($this->as['manager'])
            ->post(route('manager.requests.reject', $rejected), ['remarks' => 'Not eligible'])
            ->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::REJECTED_MANAGER, $rejected->fresh()->status);
        $this->crawlEverything('REJECTED_MANAGER', $rejected);

        $cancelled = $this->submitted();
        $this->actingAs($this->as['user'])
            ->post(route('my.requests.cancel', $cancelled), ['cancel_reason' => 'Plans changed'])
            ->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::CANCELLED, $cancelled->fresh()->status);
        $this->crawlEverything('CANCELLED', $cancelled);

        $noRoom = $this->submitted();
        $this->actingAs($this->as['manager'])->post(route('manager.requests.approve', $noRoom));
        $this->actingAs($this->as['adg'])->post(route('adg.requests.approve', $noRoom));
        $this->actingAs($this->as['admin'])
            ->post(route('admin.allotments.noRoom', $noRoom), ['reason' => 'Fully booked for the conference'])
            ->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::NO_ROOM_AVAILABLE, $noRoom->fresh()->status);
        $this->crawlEverything('NO_ROOM_AVAILABLE', $noRoom);
    }

    // ------------------------------------------------------------------ helpers

    private function submitted(): BookingRequest
    {
        $this->actingAs($this->as['user'])->post(route('my.requests.store'), [
            'purpose' => 'SELF',
            'check_in_date' => today()->addDays(5)->format('Y-m-d'),
            'check_out_date' => today()->addDays(6)->format('Y-m-d'),
            'total_members' => 1,
            'contact_mobile' => '9876543210',
            'contact_email' => 'rajesh.kumar@nadt.gov.in',
            'occupants' => [['name' => 'Rajesh Kumar']],
            'documents' => [UploadedFile::fake()->create('id.pdf', 50, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        return BookingRequest::latest('id')->firstOrFail();
    }

    /**
     * Render every GET route as every role (and as a guest) and fail on any 5xx.
     */
    private function crawlEverything(string $stage, ?BookingRequest $request = null): void
    {
        $params = [
            'bookingRequest' => $request?->id ?? 999999,
            'document' => $request?->documents()->value('id') ?? 999999,
            'allotment' => $request ? Allotment::where('booking_request_id', $request->id)->value('id') ?? 999999 : 999999,
            'token' => 'dummy-reset-token',
            // Admin masters — real seeded rows, so the edit screens are rendered
            // against production-shaped data at every stage.
            'user' => User::query()->value('id'),
            'room' => Room::query()->value('id'),
            'roomType' => RoomType::query()->value('id'),
            // Reports: one real type and both export formats, as every role.
            'type' => 'booking',
            'format' => 'pdf',
            'emailTemplate' => \App\Models\EmailTemplate::query()->value('id'),
            'holiday' => \App\Models\Holiday::query()->value('id'),
        ];

        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $r) => in_array('GET', $r->methods(), true))
            ->filter(fn (Route $r) => $r->getName() !== null && ! in_array($r->getName(), self::SKIP, true))
            ->filter(fn (Route $r) => ! str_starts_with($r->uri(), '_') && ! str_starts_with($r->uri(), 'storage'));

        $failures = [];

        foreach ([null, ...array_keys($this->as)] as $role) {
            foreach ($routes as $route) {
                $url = route($route->getName(), array_intersect_key($params, array_flip($route->parameterNames())));

                $this->app['auth']->forgetGuards();
                $response = $role ? $this->actingAs($this->as[$role])->get($url) : $this->get($url);

                if ($response->getStatusCode() >= 500) {
                    $message = $response->exception?->getMessage() ?? substr((string) $response->getContent(), 0, 300);
                    $failures[] = sprintf('[%s] %s as %s -> %d: %s', $stage, $url, $role ?? 'guest', $response->getStatusCode(), $message);
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }
}
