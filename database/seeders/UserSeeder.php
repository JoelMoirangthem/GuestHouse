<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * One account per role, plus three employees who report to the Manager so the
 * "team" scope in REPORTS.md section 6 has something to resolve.
 *
 * Passwords are development credentials only. SECURITY.md section 3 forbids
 * self-registration, so in production an administrator provisions accounts and
 * these seeded logins must be removed or rotated before deployment.
 */
class UserSeeder extends Seeder
{
    public const PASSWORD = 'Password@123';

    public function run(): void
    {
        $roles = Role::pluck('id', 'slug');

        $admin = $this->make('GH-ADM-001', 'Suresh Iyer', 'admin@nadt.gov.in',
            '9810000001', RoleSlug::ADMIN, $roles, 'Guest House Administrator', 'Administration');

        $adg = $this->make('GH-ADG-001', 'Dr. Anjali Verma', 'adg@nadt.gov.in',
            '9810000002', RoleSlug::ADG, $roles, 'Additional Director General', 'Office of the ADG');

        $manager = $this->make('GH-MGR-001', 'Rakesh Menon', 'manager@nadt.gov.in',
            '9810000003', RoleSlug::MANAGER, $roles, 'Administrative Officer', 'Administration');

        // The Manager reports to the ADG; the ADG and Admin have no manager above
        // them in this workflow.
        $manager->reporting_manager_id = $adg->id;
        $manager->save();

        $employees = [
            ['GH-EMP-001', 'Rajesh Kumar', 'rajesh.kumar@nadt.gov.in', '9876543210', 'Income Tax Officer', 'Assessment'],
            ['GH-EMP-002', 'Priya Sharma', 'priya.sharma@nadt.gov.in', '9876543211', 'Inspector', 'Investigation'],
            ['GH-EMP-003', 'Amit Deshpande', 'amit.deshpande@nadt.gov.in', '9876543212', 'Tax Assistant', 'Administration'],
        ];

        foreach ($employees as [$code, $name, $email, $mobile, $designation, $dept]) {
            $user = $this->make($code, $name, $email, $mobile, RoleSlug::USER, $roles, $designation, $dept);
            $user->reporting_manager_id = $manager->id;
            $user->save();
        }

        unset($admin);
    }

    /**
     * @param  Collection<string, int>  $roles
     */
    private function make(
        string $code,
        string $name,
        string $email,
        string $mobile,
        RoleSlug $role,
        $roles,
        string $designation,
        string $department,
    ): User {
        $user = User::withTrashed()->firstOrNew(['email' => $email]);

        $user->employee_code = $code;
        $user->name = $name;
        $user->mobile = $mobile;
        $user->designation = $designation;
        $user->department = $department;
        $user->password = Hash::make(self::PASSWORD);
        $user->email_verified_at = now();
        $user->is_active = true;

        // role_id is not mass-assignable by design, so it is set explicitly here.
        $user->role_id = $roles[$role->value];

        $user->save();

        return $user;
    }
}
