@extends('layouts.app')

@section('title', 'Audit log')
@section('heading', 'Audit log')

@section('content')
    @include('admin._errors')

    <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="gh-card mb-5 p-5">
        <div class="grid items-end gap-3 sm:grid-cols-3 lg:grid-cols-[1fr_1fr_1fr_auto_auto_1fr_auto]">
            <div>
                <label for="action" class="gh-label">Action</label>
                <select id="action" name="action" class="gh-select">
                    <option value="">Any</option>
                    @foreach ($actions as $a)<option value="{{ $a }}" @selected(($filters['action'] ?? '') === $a)>{{ $a }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="actor" class="gh-label">By</label>
                <select id="actor" name="actor" class="gh-select">
                    <option value="">Anyone</option>
                    @foreach ($actors as $u)<option value="{{ $u->id }}" @selected((int) ($filters['actor'] ?? 0) === $u->id)>{{ $u->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="subject" class="gh-label">Record type</label>
                <select id="subject" name="subject" class="gh-select">
                    <option value="">Any</option>
                    @foreach ($subjects as $s)<option value="{{ $s }}" @selected(($filters['subject'] ?? '') === $s)>{{ class_basename($s) }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="from" class="gh-label">From</label>
                <input id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}" class="gh-input">
            </div>
            <div>
                <label for="to" class="gh-label">To</label>
                <input id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}" class="gh-input">
            </div>
            <div>
                <label for="q" class="gh-label">Remarks contain</label>
                <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" class="gh-input">
            </div>
            <button type="submit" class="gh-btn gh-btn-secondary">Filter</button>
        </div>
    </form>

    <div class="gh-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="gh-table">
                <caption class="sr-only">Audit entries, newest first</caption>
                <thead><tr><th scope="col">When</th><th scope="col">Action</th><th scope="col">Record</th><th scope="col">By</th><th scope="col">Status</th><th scope="col">Details</th></tr></thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="whitespace-nowrap text-[--color-ink-soft]">{{ $log->created_at->format('d/m/Y H:i:s') }}</td>
                            <td>
                                <p class="font-medium text-[--color-ink]">{{ $log->describe() }}</p>
                                <p class="font-mono text-[0.6875rem] text-[--color-ink-muted]">{{ $log->action }}</p>
                            </td>
                            <td class="whitespace-nowrap text-[--color-ink-soft]">{{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</td>
                            <td class="text-[--color-ink-soft]">
                                {{ $log->actor?->name ?? 'System' }}
                                @if ($log->actor_role)<span class="block text-xs text-[--color-ink-muted]">{{ $log->actor_role }}</span>@endif
                                @if ($log->ip_address)<span class="block font-mono text-[0.6875rem] text-[--color-ink-faint]">{{ $log->ip_address }}</span>@endif
                            </td>
                            <td class="whitespace-nowrap text-xs text-[--color-ink-soft]">
                                @if ($log->from_status || $log->to_status){{ $log->from_status ?? '—' }} &rarr; {{ $log->to_status ?? '—' }}@endif
                            </td>
                            <td class="max-w-md text-xs text-[--color-ink-soft]">
                                @if ($log->remarks)<p>{{ $log->remarks }}</p>@endif
                                @if ($log->metadata)
                                    <code class="block break-all text-[0.6875rem] text-[--color-ink-muted]">{{ json_encode($log->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-[--color-ink-muted]">No entries match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="gh-hairline-t bg-[--color-surface-sunken] px-5 py-3">
            <p class="text-xs text-[--color-ink-muted]">The audit log is append-only. Entries cannot be edited or deleted from any screen.</p>
        </div>
    </div>

    <div class="mt-5">{{ $logs->links() }}</div>
@endsection
