<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\RoleSlug;
use App\Models\AuditLog;
use App\Models\Holiday;
use App\Models\User;
use Database\Seeders\HolidaySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Holiday calendar — SCHEMA.md section 13, ROUTES.md `/admin/holidays`.
 */
class HolidayCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(RoleSlug::ADMIN)->create();
    }

    #[Test]
    public function the_admin_can_add_edit_and_remove_a_holiday_and_each_step_is_audited(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.holidays.store'), ['date' => '2026-11-08', 'name' => 'Deepavali', 'type' => 'PUBLIC'])
            ->assertRedirect(route('admin.holidays.index', ['year' => 2026]))
            ->assertSessionHasNoErrors();

        $h = Holiday::firstOrFail();
        $this->assertSame(2026, $h->year, 'year is derived from the date.');

        $this->get(route('admin.holidays.index', ['year' => 2026]))->assertOk()->assertSee('Deepavali')->assertSee('Sunday');
        $this->get(route('admin.holidays.index', ['year' => 2026, 'edit' => $h->id]))->assertOk()->assertSee('Edit Deepavali');

        $this->put(route('admin.holidays.update', $h), ['date' => '2027-01-01', 'name' => 'New Year', 'type' => 'RESTRICTED'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2027, $h->fresh()->year, 'Moving the date moves the year.');

        $this->delete(route('admin.holidays.destroy', $h))->assertSessionHasNoErrors();
        $this->assertSame(0, Holiday::count());

        $this->assertSame(['HOLIDAY_CREATED', 'HOLIDAY_UPDATED', 'HOLIDAY_DELETED'],
            AuditLog::where('auditable_type', $h->getMorphClass())->orderBy('id')->pluck('action')->all());
    }

    #[Test]
    public function two_holidays_cannot_share_a_date(): void
    {
        Holiday::create(['date' => '2026-10-02', 'name' => 'Gandhi Jayanti', 'type' => 'PUBLIC']);

        $this->actingAs($this->admin)
            ->post(route('admin.holidays.store'), ['date' => '2026-10-02', 'name' => 'Duplicate', 'type' => 'PUBLIC'])
            ->assertSessionHasErrors('date');
    }

    #[Test]
    public function input_is_validated(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.holidays.store'), ['date' => '02/10/2026', 'name' => '', 'type' => 'NATIONAL'])
            ->assertSessionHasErrors(['date', 'name', 'type']);

        $this->get(route('admin.holidays.index', ['year' => 1066]))->assertNotFound();
    }

    #[Test]
    public function only_the_administrator_can_manage_holidays(): void
    {
        $h = Holiday::create(['date' => '2026-12-25', 'name' => 'Christmas Day', 'type' => 'PUBLIC']);

        foreach ([RoleSlug::USER, RoleSlug::MANAGER, RoleSlug::ADG] as $slug) {
            $u = User::factory()->role($slug)->create();
            $this->actingAs($u)->get(route('admin.holidays.index'))->assertForbidden();
            $this->actingAs($u)->post(route('admin.holidays.store'), ['date' => '2026-12-31', 'name' => 'x', 'type' => 'LOCAL'])->assertForbidden();
            $this->actingAs($u)->delete(route('admin.holidays.destroy', $h))->assertForbidden();
        }

        $this->assertSame(1, Holiday::count());
    }

    #[Test]
    public function the_seeder_loads_the_fixed_national_holidays_idempotently(): void
    {
        $this->seed(HolidaySeeder::class);
        $this->seed(HolidaySeeder::class);

        $this->assertSame(4, Holiday::count());
        $this->assertTrue(Holiday::where('name', 'Republic Day')->where('year', (int) now()->format('Y'))->exists());
    }
}
