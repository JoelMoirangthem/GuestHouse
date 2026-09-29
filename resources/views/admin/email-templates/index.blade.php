@extends('layouts.app')

@section('title', 'Notification templates')
@section('heading', 'Notification templates')

@section('content')
    <div class="gh-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="gh-table">
                <thead><tr><th scope="col">Event</th><th scope="col">Subject</th><th scope="col">Channels</th><th scope="col">State</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @foreach ($events as $row)
                        @php $t = $row['template']; $e = $row['event']; @endphp
                        <tr>
                            <td>
                                <p class="font-medium text-[--color-ink]">{{ $e->bellTitle() }}</p>
                                <p class="font-mono text-xs text-[--color-ink-muted]">{{ $e->value }}</p>
                            </td>
                            <td class="text-[--color-ink-soft]">{{ $t?->subject ?? '— using the built-in default —' }}</td>
                            <td class="text-xs text-[--color-ink-soft]">{{ implode(', ', $e->channels()) }}</td>
                            <td class="text-[--color-ink-soft]">{{ $t === null ? 'Missing' : ($t->is_active ? 'Active' : 'Off') }}</td>
                            <td class="text-right">
                                @if ($t)
                                    <a href="{{ route('admin.email-templates.edit', $t) }}" class="gh-btn gh-btn-ghost text-[0.8125rem]">Edit<span class="sr-only"> {{ $e->value }}</span></a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="gh-hairline-t bg-[--color-surface-sunken] px-5 py-3">
            <p class="text-xs text-[--color-ink-muted]">A template that is switched off falls back to the built-in wording, so the notification is still sent.</p>
        </div>
    </div>
@endsection
