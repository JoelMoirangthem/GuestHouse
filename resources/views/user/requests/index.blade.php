@extends('layouts.app')

@section('title', 'My Requests')
@section('heading', 'My Requests')

@section('content')

    {{-- Summary strip --}}
    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([
            ['label' => 'Total', 'value' => $counts['total'], 'hint' => 'submitted'],
            ['label' => 'In Progress', 'value' => $counts['in_progress'], 'hint' => 'awaiting decision'],
            ['label' => 'Allotted', 'value' => $counts['allotted'], 'hint' => 'rooms held'],
            ['label' => 'Completed', 'value' => $counts['completed'], 'hint' => 'stays finished'],
        ] as $tile)
            <div class="gh-card px-4 py-3.5">
                <p class="gh-eyebrow">{{ $tile['label'] }}</p>
                <p class="gh-metric-value mt-1 text-navy-900">{{ $tile['value'] }}</p>
                <p class="mt-0.5 text-xs text-[--color-ink-faint]">{{ $tile['hint'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-[--color-ink-muted]">
            Requests you have raised for guest house accommodation.
        </p>

        <a href="{{ route('my.requests.create') }}" class="gh-btn gh-btn-primary">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z"/>
            </svg>
            New Request
        </a>
    </div>

    @if ($requests->isEmpty())
        <div class="gh-card p-10 text-center">
            <div class="mx-auto mb-4 flex h-11 w-11 items-center justify-center rounded-full border border-[--color-line] bg-[--color-surface-sunken] text-[--color-ink-muted]">
                <x-icon name="document" class="h-5 w-5" />
            </div>
            <h2 class="font-serif text-lg text-navy-900">No requests yet</h2>
            <p class="mx-auto mt-1.5 max-w-sm text-sm text-[--color-ink-muted]">
                Raise a request with your dates and purpose of visit. Room availability is
                confirmed by the Administration after approval.
            </p>
            <a href="{{ route('my.requests.create') }}" class="gh-btn gh-btn-primary mt-5">Raise a request</a>
        </div>
    @else
        <div class="gh-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="gh-table">
                    <caption class="sr-only">Your booking requests, most recent first</caption>
                    <thead>
                        <tr>
                            <th scope="col">Request No.</th>
                            <th scope="col">Purpose</th>
                            <th scope="col">Stay</th>
                            <th scope="col" class="text-right">Members</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($requests as $r)
                            <tr>
                                <td class="font-medium text-[--color-ink]">{{ $r->request_no }}</td>
                                <td class="text-[--color-ink-soft]">{{ $r->purpose->label() }}</td>
                                <td class="whitespace-nowrap text-[--color-ink-soft]">
                                    {{ $r->check_in_date->format('d/m/Y') }}
                                    <span class="text-[--color-ink-faint]">&rarr;</span>
                                    {{ $r->check_out_date->format('d/m/Y') }}
                                    <span class="block text-xs text-[--color-ink-faint]">
                                        {{ $r->nights }} {{ Str::plural('night', $r->nights) }}
                                    </span>
                                </td>
                                <td class="text-right text-[--color-ink-soft]">{{ $r->total_members }}</td>
                                <td><x-status :status="$r->status" /></td>
                                <td class="text-right">
                                    <a href="{{ route('my.requests.show', $r) }}"
                                       class="text-sm font-medium text-navy-700 underline decoration-navy-300 underline-offset-2 hover:text-navy-900">
                                        View
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if ($requests->hasPages())
            <div class="mt-5">{{ $requests->links() }}</div>
        @endif
    @endif
@endsection
