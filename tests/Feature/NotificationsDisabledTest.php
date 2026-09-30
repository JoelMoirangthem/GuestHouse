<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Services\NotificationDispatcher;
use App\Domain\Enums\NotificationEvent;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Models\BookingRequest;
use App\Models\Notification;
use App\Models\RequestDocument;
use App\Models\RequestOccupant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * With GH_NOTIFICATIONS_ENABLED off (the default outside tests), the system
 * sends no emails, writes no bell entries and shows no bell.
 */
class NotificationsDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['gh.notifications_enabled' => false]);
        Mail::fake();
    }

    #[Test]
    public function submitting_and_approving_a_booking_sends_nothing(): void
    {
        $manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $employee = User::factory()->role(RoleSlug::USER)->reportingTo($manager)->create();

        $request = BookingRequest::factory()->for_($employee)->pendingManager($manager)->create();
        RequestOccupant::factory()->primary()->create(['booking_request_id' => $request->id]);
        RequestDocument::factory()->create(['booking_request_id' => $request->id, 'uploaded_by' => $employee->id]);

        $this->approveWithRooms($manager, $request)->assertSessionHasNoErrors();

        // The workflow still works...
        $this->assertSame(RequestStatus::ALLOTTED, $request->fresh()->status);

        // ...silently.
        $this->assertSame(0, Notification::count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    #[Test]
    public function the_dispatcher_does_nothing_when_switched_off(): void
    {
        $user = User::factory()->role(RoleSlug::USER)->create();

        $sent = app(NotificationDispatcher::class)->dispatch(NotificationEvent::ROOMS_ALLOTTED, null, [], $user);

        $this->assertSame(0, $sent);
        $this->assertSame(0, Notification::count());
    }

    #[Test]
    public function the_bell_is_hidden(): void
    {
        $user = User::factory()->role(RoleSlug::USER)->create();

        $this->actingAs($user)->get(route('my.requests.index'))
            ->assertOk()
            ->assertDontSee(route('notifications.feed'), false);
    }
}
