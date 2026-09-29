@extends('layouts.app')

@section('title', 'Users')
@section('heading', 'Users')

@section('content')
    @include('admin._errors')

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" action="{{ route('admin.users.index') }}" class="flex flex-wrap items-end gap-3" role="search">
            <div>
                <label for="q" class="gh-label">Search</label>
                <input id="q" name="q" type="search" value="{{ $search }}" class="gh-input" placeholder="Name, email or employee code">
            </div>
            <div>
                <label for="role" class="gh-label">Role</label>
                <select id="role" name="role" class="gh-select">
                    <option value="">All roles</option>
                    @foreach ($roles as $r)
                        <option value="{{ $r->slug }}" @selected($roleFilter === $r->slug)>{{ $r->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="gh-btn gh-btn-secondary">Filter</button>
        </form>

        <a href="{{ route('admin.users.create') }}" class="gh-btn gh-btn-primary">Add user</a>
    </div>

    <div class="gh-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="gh-table">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Employee code</th>
                        <th scope="col">Role</th>
                        <th scope="col">Reports to</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $u)
                        <tr>
                            <td>
                                <p class="font-medium text-[--color-ink]">{{ $u->name }}</p>
                                <p class="text-xs text-[--color-ink-muted]">{{ $u->email }}</p>
                            </td>
                            <td class="text-[--color-ink-soft]">{{ $u->employee_code ?? '—' }}</td>
                            <td class="text-[--color-ink-soft]">{{ $u->role?->name ?? '—' }}</td>
                            <td class="text-[--color-ink-soft]">{{ $u->reportingManager?->name ?? '—' }}</td>
                            <td>
                                @if ($u->is_active)
                                    <span class="gh-status bg-[--color-success-bg] border-green-200 text-[--color-success]">Active</span>
                                @else
                                    <span class="gh-status bg-[--color-danger-bg] border-red-200 text-[--color-danger]">Deactivated</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <a href="{{ route('admin.users.edit', $u) }}" class="gh-btn gh-btn-ghost text-[0.8125rem]">
                                    Edit<span class="sr-only"> {{ $u->name }}</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-[--color-ink-muted]">No users match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-5">{{ $users->links() }}</div>
@endsection
