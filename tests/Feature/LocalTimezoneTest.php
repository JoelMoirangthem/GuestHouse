<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Services\AllotmentService;
use App\Application\Services\StayService;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Models\BookingRequest;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The guest house runs on Lucknow time. "Today" must mean today in India, not
 * today in UTC — otherwise, for five and a half hours after every Indian
 * midnight, the system believes it is still yesterday.
 */
class LocalTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_application_runs_on_indian_standard_time(): void
    {
        $this->assertSame('Asia/Kolkata', config('app.timezone'));
        $this->assertSame('Asia/Kolkata', date_default_timezone_get());
    }

    #[Test]
    public function a_guest_arriving_just_after_indian_midnight_can_check_in(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // 01:30 IST on 27 September is still 26 September in UTC.
        Carbon::setTestNow(Carbon::parse('2026-09-26 20:00:00', 'UTC'));

        $admin = User::factory()->role(RoleSlug::ADMIN)->create();
        $manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $employee = User::factory()->role(RoleSlug::USER)->reportingTo($manager)->create();
        $type = RoomType::factory()->create(['default_capacity' => 2]);

        $request = BookingRequest::factory()
            ->for_($employee)
            ->status(RequestStatus::PENDING_ALLOTMENT)
            ->dates('2026-09-27', '2026-09-29')
            ->create();

        $room = Room::factory()->ofType($type)->create();
        $request = app(AllotmentService::class)->allot($request, $admin, [$room->id]);

        $request = app(StayService::class)->checkIn($request, $admin);

        $this->assertSame(RequestStatus::CHECKED_IN, $request->status);
    }

    #[Test]
    public function yesterday_in_india_is_not_accepted_as_a_check_in_date(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-26 20:00:00', 'UTC')); // 27 Sep, 01:30 IST

        $manager = User::factory()->role(RoleSlug::MANAGER)->create();
        $employee = User::factory()->role(RoleSlug::USER)->reportingTo($manager)->create();

        $this->actingAs($employee)
            ->post(route('my.requests.store'), [
                'purpose' => 'SELF',
                'check_in_date' => '2026-09-26',
                'check_out_date' => '2026-09-28',
                'total_members' => 1,
                'contact_mobile' => '9876543210',
                'contact_email' => 'guest@example.org',
                'occupants' => [['name' => 'Guest']],
                'documents' => [UploadedFile::fake()->create('id.pdf', 10, 'application/pdf')],
            ])
            ->assertSessionHasErrors('check_in_date');
    }
}
