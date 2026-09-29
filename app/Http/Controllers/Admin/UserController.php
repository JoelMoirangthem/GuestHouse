<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Services\UserAdminService;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Account provisioning — ROUTES.md `/admin/users`.
 *
 * No destroy action: accounts are deactivated, never deleted, because requests,
 * approvals and audit rows all reference the user and must keep resolving.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserAdminService $users,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $roleFilter = (string) $request->query('role', '');

        return view('admin.users.index', [
            'users' => User::query()
                ->with(['role', 'reportingManager'])
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('employee_code', 'like', "%{$search}%")))
                ->when($roleFilter !== '', fn ($q) => $q->whereHas('role', fn ($r) => $r->where('slug', $roleFilter)))
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->paginate(25)
                ->withQueryString(),
            'roles' => Role::orderBy('sort_order')->get(),
            'search' => $search,
            'roleFilter' => $roleFilter,
        ]);
    }

    public function create(): View
    {
        return view('admin.users.form', [
            'user' => new User(['is_active' => true]),
            'roles' => Role::where('is_active', true)->orderBy('sort_order')->get(),
            'managers' => $this->users->managerOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(null));

        try {
            $user = $this->users->create($data, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput($request->except('password', 'password_confirmation'))
                ->withErrors(['user' => $e->getMessage()]);
        }

        return redirect()->route('admin.users.index')->with('success', "Account created for {$user->name}.");
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', [
            'user' => $user->load('role'),
            'roles' => Role::where('is_active', true)->orderBy('sort_order')->get(),
            'managers' => $this->users->managerOptions($user),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate($this->rules($user));

        try {
            $this->users->update($user, $data, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput($request->except('password', 'password_confirmation'))
                ->withErrors(['user' => $e->getMessage()]);
        }

        return redirect()->route('admin.users.index')->with('success', "{$user->name} updated.");
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?User $user): array
    {
        // uncompromised() is deliberately omitted: it calls an external breach
        // API, and this system is intended for a closed government network.
        $password = PasswordRule::min(10)->mixedCase()->numbers()->symbols();

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:150', Rule::unique('users', 'email')->ignore($user?->id)],
            'employee_code' => ['nullable', 'string', 'max:30', Rule::unique('users', 'employee_code')->ignore($user?->id)],
            'mobile' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'designation' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'reporting_manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'password' => $user === null
                ? ['required', 'confirmed', $password]
                : ['nullable', 'confirmed', $password],
        ];
    }
}
