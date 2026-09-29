<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\RoleSlug;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TESTING.md — RoleIsolationMatrixTest.
 *
 * "This single test is the backbone of the authorization guarantee."
 *
 * For every role, assert it can reach its own area and is refused every other
 * role's area. Hiding a navigation link is not access control; these assertions
 * exercise the server-side middleware directly.
 */
class RoleIsolationMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(RoleSlug $slug): User
    {
        return User::factory()->role($slug)->create();
    }

    /**
     * Every privileged route, mapped to the single role permitted to reach it.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function privilegedRoutes(): array
    {
        return [
            'manager queue' => ['manager.requests.index', 'manager'],
            'adg queue' => ['adg.requests.index', 'adg'],
            'admin inventory' => ['admin.inventory', 'admin'],
            'admin dashboard' => ['dashboard', 'admin'],
        ];
    }

    #[Test]
    #[DataProvider('privilegedRoutes')]
    public function only_the_owning_role_may_reach_a_privileged_route(string $routeName, string $ownerSlug): void
    {
        foreach (RoleSlug::cases() as $case) {
            $user = $this->userWithRole($case);

            $response = $this->actingAs($user)->get(route($routeName));

            if ($case->value === $ownerSlug) {
                $response->assertOk();
            } else {
                $response->assertForbidden();
            }
        }
    }

    #[Test]
    public function every_role_can_reach_its_own_landing_page(): void
    {
        foreach (RoleSlug::cases() as $case) {
            $user = $this->userWithRole($case);

            $this->actingAs($user)
                ->get(route('home'))
                ->assertRedirect(route($case->homeRoute()));
        }
    }

    #[Test]
    public function all_four_roles_may_view_their_own_requests(): void
    {
        // "My Requests" is shared: a Manager or ADG is also an employee who may
        // need a room. Only the approval queues are role-exclusive.
        foreach (RoleSlug::cases() as $case) {
            $this->actingAs($this->userWithRole($case))
                ->get(route('my.requests.index'))
                ->assertOk();
        }
    }

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('manager.requests.index'))->assertRedirect(route('login'));
    }

    #[Test]
    public function a_deactivated_account_cannot_reach_a_role_area_even_with_a_live_session(): void
    {
        $user = User::factory()->role(RoleSlug::ADMIN)->inactive()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertForbidden();
    }
}
