@extends('layouts.app')

@php $editing = $user->exists; @endphp

@section('title', $editing ? 'Edit user' : 'Add user')
@section('heading', $editing ? 'Edit user — ' . $user->name : 'Add user')

@section('content')
    @include('admin._errors')

    <form method="POST"
          action="{{ $editing ? route('admin.users.update', $user) : route('admin.users.store') }}"
          class="gh-card space-y-6 p-6" novalidate>
        @csrf
        @if ($editing) @method('PUT') @endif

        <fieldset>
            <legend class="gh-eyebrow mb-4">Identity</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="name" class="gh-label gh-required">Full name</label>
                    <input id="name" name="name" type="text" required maxlength="120" autocomplete="off"
                           value="{{ old('name', $user->name) }}" class="gh-input"
                           @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                    @error('name') <p id="name-error" class="gh-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="email" class="gh-label gh-required">Email</label>
                    <input id="email" name="email" type="email" required maxlength="150" autocomplete="off"
                           value="{{ old('email', $user->email) }}" class="gh-input"
                           @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email') <p id="email-error" class="gh-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="employee_code" class="gh-label">Employee code</label>
                    <input id="employee_code" name="employee_code" type="text" maxlength="30"
                           value="{{ old('employee_code', $user->employee_code) }}" class="gh-input"
                           @error('employee_code') aria-invalid="true" aria-describedby="employee_code-error" @enderror>
                    @error('employee_code') <p id="employee_code-error" class="gh-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="mobile" class="gh-label">Mobile</label>
                    <input id="mobile" name="mobile" type="tel" inputmode="numeric" maxlength="10"
                           value="{{ old('mobile', $user->mobile) }}" class="gh-input" aria-describedby="mobile-help"
                           @error('mobile') aria-invalid="true" @enderror>
                    <p id="mobile-help" class="gh-help">10-digit Indian mobile number. Used for SMS alerts.</p>
                    @error('mobile') <p class="gh-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="designation" class="gh-label">Designation</label>
                    <input id="designation" name="designation" type="text" maxlength="100"
                           value="{{ old('designation', $user->designation) }}" class="gh-input">
                </div>
                <div>
                    <label for="department" class="gh-label">Department</label>
                    <input id="department" name="department" type="text" maxlength="100"
                           value="{{ old('department', $user->department) }}" class="gh-input">
                </div>
            </div>
        </fieldset>

        <fieldset class="gh-hairline-t pt-6">
            <legend class="gh-eyebrow mb-4">Role and reporting</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="role_id" class="gh-label gh-required">Role</label>
                    <select id="role_id" name="role_id" required class="gh-select"
                            @error('role_id') aria-invalid="true" @enderror>
                        <option value="">Choose a role</option>
                        @foreach ($roles as $r)
                            <option value="{{ $r->id }}" @selected((int) old('role_id', $user->role_id) === $r->id)>{{ $r->name }}</option>
                        @endforeach
                    </select>
                    @error('role_id') <p class="gh-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="reporting_manager_id" class="gh-label">Reporting manager</label>
                    <select id="reporting_manager_id" name="reporting_manager_id" class="gh-select" aria-describedby="rm-help">
                        <option value="">None</option>
                        @foreach ($managers as $m)
                            <option value="{{ $m->id }}" @selected((int) old('reporting_manager_id', $user->reporting_manager_id) === $m->id)>
                                {{ $m->name }}{{ $m->designation ? ' — ' . $m->designation : '' }}
                            </option>
                        @endforeach
                    </select>
                    <p id="rm-help" class="gh-help">Required for employees: their requests are routed to this manager.</p>
                </div>
            </div>

            <input type="hidden" name="is_active" value="0">
            <label class="mt-4 flex items-center gap-2 text-sm text-[--color-ink-soft]">
                <input type="checkbox" name="is_active" value="1"
                       @checked((bool) old('is_active', $user->is_active ?? true))
                       class="h-4 w-4 rounded border-[--color-line-strong] text-navy-700 focus:ring-navy-500">
                Account is active
            </label>
            <p class="gh-help">Deactivating signs the user out immediately. Accounts are never deleted, so their history stays intact.</p>
        </fieldset>

        <fieldset class="gh-hairline-t pt-6">
            <legend class="gh-eyebrow mb-4">{{ $editing ? 'Reset password' : 'Initial password' }}</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="password" class="gh-label {{ $editing ? '' : 'gh-required' }}">Password</label>
                    <input id="password" name="password" type="password" autocomplete="new-password"
                           class="gh-input" aria-describedby="pw-help" @unless ($editing) required @endunless
                           @error('password') aria-invalid="true" @enderror>
                    @error('password') <p class="gh-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="password_confirmation" class="gh-label {{ $editing ? '' : 'gh-required' }}">Confirm password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"
                           class="gh-input" @unless ($editing) required @endunless>
                </div>
            </div>
            <p id="pw-help" class="gh-help">
                At least 10 characters with upper and lower case, a digit and a symbol.
                @if ($editing) Leave blank to keep the current password. Setting one signs the user out everywhere. @endif
            </p>
        </fieldset>

        <div class="gh-hairline-t flex justify-end gap-2 pt-5">
            <a href="{{ route('admin.users.index') }}" class="gh-btn gh-btn-secondary">Cancel</a>
            <button type="submit" class="gh-btn gh-btn-primary">{{ $editing ? 'Save changes' : 'Create account' }}</button>
        </div>
    </form>
@endsection
