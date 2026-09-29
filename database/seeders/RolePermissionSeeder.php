<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\RoleSlug;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the four roles and their permission sets exactly as listed in
 * ROUTES.md "Permission slugs".
 *
 * Two absences are load-bearing and must never be "helpfully" added:
 *
 *  1. There is no `request.moreinfo.adg` permission. The ADG can approve or
 *     reject only (PLAN.md decision 5). This is one of three independent layers
 *     enforcing that rule; the other two are the missing route and the missing
 *     state transition.
 *
 *  2. `availability.check` is granted to `admin` alone. Core Rule 2 says users
 *     never see availability, and the ADG gets only current-state inventory
 *     counts. The Manager's single exception is `room.allot.review`: the room
 *     board for the request under review, for those dates only, while it is
 *     PENDING_MANAGER (PLAN.md decision 10).
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * slug => [name, group]
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PERMISSIONS = [
        // --- requests
        'request.create' => ['Create a booking request', 'Requests'],
        'request.resubmit' => ['Resubmit after More Info', 'Requests'],
        'request.cancel.own' => ['Cancel own request', 'Requests'],
        'request.view.own' => ['View own requests', 'Requests'],
        'request.view.team' => ['View reportees\' requests', 'Requests'],
        'request.view.all' => ['View all requests', 'Requests'],

        // --- approvals
        'request.approve.manager' => ['Approve as Manager', 'Approvals'],
        'request.reject.manager' => ['Reject as Manager', 'Approvals'],
        'request.moreinfo.manager' => ['Request more information', 'Approvals'],
        'request.approve.adg' => ['Approve as ADG', 'Approvals'],
        'request.reject.adg' => ['Reject as ADG', 'Approvals'],

        // --- availability & allotment (admin only)
        'availability.check' => ['Check room availability', 'Allotment'],
        'availability.recheck' => ['Re-check after no room available', 'Allotment'],
        'room.allot' => ['Allot rooms', 'Allotment'],
        'room.allot.review' => ['Select and hold rooms while approving as Manager', 'Allotment'],
        'room.release' => ['Release an allotted room', 'Allotment'],
        'room.block' => ['Block or unblock a room', 'Allotment'],
        'inventory.view' => ['View room inventory', 'Allotment'],

        // --- stay
        'stay.manage' => ['Check-in and check-out', 'Stay'],
        'extension.request' => ['Request a stay extension', 'Stay'],
        'extension.decide' => ['Approve or deny an extension', 'Stay'],
        'feedback.submit' => ['Submit feedback', 'Stay'],

        // --- administration
        'master.manage' => ['Manage rooms, types, tariffs, users', 'Administration'],
        'settings.manage' => ['Manage settings and templates', 'Administration'],
        'report.view.team' => ['View team reports', 'Reports'],
        'report.view.all' => ['View all reports', 'Reports'],
        'report.revenue' => ['View the revenue report', 'Reports'],
        'audit.view' => ['View audit logs', 'Administration'],
    ];

    /**
     * role slug => permission slugs
     *
     * @var array<string, array<int, string>>
     */
    private const ROLE_MAP = [
        'user' => [
            'request.create', 'request.resubmit', 'request.cancel.own',
            'request.view.own', 'extension.request', 'feedback.submit',
        ],
        'manager' => [
            'request.view.team', 'request.approve.manager', 'request.reject.manager',
            'request.moreinfo.manager', 'report.view.team', 'room.allot.review',
            'request.view.own', 'request.create', 'extension.request', 'feedback.submit',
        ],
        'adg' => [
            'request.view.all', 'request.approve.adg', 'request.reject.adg',
            'inventory.view', 'report.view.all',
            'request.view.own', 'request.create', 'extension.request', 'feedback.submit',
        ],
        'admin' => [
            'request.view.all', 'availability.check', 'availability.recheck',
            'room.allot', 'room.release', 'room.block', 'inventory.view',
            'stay.manage', 'extension.decide', 'master.manage', 'settings.manage',
            'report.view.all', 'report.revenue', 'audit.view',
        ],
    ];

    /**
     * @var array<string, array{0: string, 1: string, 2: int}>
     */
    private const ROLES = [
        'user' => ['User (Employee)', 'Submits booking requests and manages own stays.', 1],
        'manager' => ['Manager', 'First-level reviewer: approve, reject or request more information.', 2],
        'adg' => ['ADG / Approval Authority', 'Second-level approver: approve or reject only.', 3],
        'admin' => ['Administrator', 'Checks availability, allots rooms, manages masters and reports.', 4],
    ];

    public function run(): void
    {
        $permissions = [];

        foreach (self::PERMISSIONS as $slug => [$name, $group]) {
            $permissions[$slug] = Permission::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'group' => $group],
            );
        }

        foreach (self::ROLES as $slug => [$name, $description, $order]) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $description, 'sort_order' => $order, 'is_active' => true],
            );

            $ids = collect(self::ROLE_MAP[$slug])
                ->map(fn (string $p) => $permissions[$p]->id)
                ->all();

            $role->permissions()->sync($ids);
        }

        // Fail loudly if the workflow roles are missing, rather than letting a
        // half-seeded database produce confusing 403s later.
        foreach (RoleSlug::cases() as $case) {
            if (! Role::where('slug', $case->value)->exists()) {
                throw new \RuntimeException("Required role '{$case->value}' was not seeded.");
            }
        }
    }
}
