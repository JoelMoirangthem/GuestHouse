@extends('layouts.app')

@section('title', 'Dashboard')
@section('heading', 'Dashboard')

@section('content')
    @php
        $prev = $month->copy()->subMonth()->format('Y-m');
        $next = $month->copy()->addMonth()->format('Y-m');
        $maxPct = max(100, collect($occupancy)->max('pct') ?? 0);
        $avgPct = count($occupancy) ? round(collect($occupancy)->avg('pct'), 1) : 0;
        $typeTotal = max(1, collect($byType)->sum('count'));
    @endphp

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <nav class="flex items-center gap-2" aria-label="Choose month">
            <a href="{{ route('dashboard', ['month' => $prev]) }}" class="gh-btn gh-btn-secondary px-2.5" aria-label="Previous month">&larr;</a>
            <h2 class="min-w-[9rem] text-center text-sm font-semibold text-[--color-ink]">{{ $month->format('F Y') }}</h2>
            <a href="{{ route('dashboard', ['month' => $next]) }}" class="gh-btn gh-btn-secondary px-2.5" aria-label="Next month">&rarr;</a>
        </nav>
    </div>

    {{-- Tiles. Queue tiles are current counts; the rest are for the month. --}}
    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
        @foreach ([
            ['label' => 'Total requests', 'value' => $tiles['total'], 'hint' => 'Submitted this month', 'href' => null],
            ['label' => 'Pending (Manager)', 'value' => $tiles['pending_manager'], 'hint' => 'Waiting now, incl. more info', 'href' => null],
            ['label' => 'Pending (ADG)', 'value' => $tiles['pending_adg'], 'hint' => 'Waiting now', 'href' => null],
            ['label' => 'Awaiting availability', 'value' => $tiles['awaiting_allotment'], 'hint' => 'Your queue', 'href' => route('admin.allotments.index')],
            ['label' => 'Rooms allotted', 'value' => $tiles['rooms_allotted'], 'hint' => 'Rooms, not requests', 'href' => null],
        ] as $tile)
            <div class="gh-card px-4 py-3.5">
                <p class="gh-eyebrow">{{ $tile['label'] }}</p>
                <p class="gh-metric-value mt-1 text-navy-900">
                    @if ($tile['href'])
                        <a href="{{ $tile['href'] }}" class="hover:underline">{{ $tile['value'] }}</a>
                    @else
                        {{ $tile['value'] }}
                    @endif
                </p>
                <p class="mt-0.5 text-xs text-[--color-ink-muted]">{{ $tile['hint'] }}</p>
            </div>
        @endforeach
    </div>

    @if ($tiles['failed_notifications'] > 0)
        <div class="gh-alert gh-alert-warn mb-6" role="status">
            <span>{{ $tiles['failed_notifications'] }} {{ Str::plural('notification', $tiles['failed_notifications']) }} could not be delivered. Check the mail connection under Mail setup.</span>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Occupancy by day. Bars carry their value as a title and the
             chart has a text summary for screen readers. --}}
        <div class="gh-card p-5 lg:col-span-2">
            <div class="mb-4 flex items-baseline justify-between gap-3">
                <h2 class="gh-eyebrow">Occupancy overview</h2>
                <p class="text-xs text-[--color-ink-muted]">Month average <span class="font-semibold text-[--color-ink]">{{ $avgPct }}%</span></p>
            </div>
            <div class="flex h-44 items-end gap-[2px]" role="img"
                 aria-label="Daily occupancy for {{ $month->format('F Y') }}, averaging {{ $avgPct }} percent">
                @foreach ($occupancy as $day)
                    <div class="group relative flex h-full flex-1 items-end">
                        <div class="w-full rounded-t-sm {{ $day['date'] === now()->format('Y-m-d') ? 'bg-gold-500' : 'bg-navy-500' }}"
                             style="height: {{ max(1, $day['pct'] / $maxPct * 100) }}%"
                             title="{{ \Illuminate\Support\Carbon::parse($day['date'])->format('d M') }}: {{ $day['pct'] }}% ({{ $day['occupied'] }} rooms)"></div>
                    </div>
                @endforeach
            </div>
            <div class="mt-1.5 flex justify-between text-[0.625rem] text-[--color-ink-faint]" aria-hidden="true">
                <span>1</span><span>{{ intdiv(count($occupancy), 2) }}</span><span>{{ count($occupancy) }}</span>
            </div>
            <p class="mt-3 text-xs text-[--color-ink-muted]">
                Rooms occupied each night ÷ rooms in service.
            </p>
        </div>

        {{-- Room-type split --}}
        <div class="gh-card p-5">
            <h2 class="gh-eyebrow mb-4">Allotments by room type</h2>
            @forelse ($byType as $row)
                @php $share = round($row['count'] / $typeTotal * 100); @endphp
                <div class="mb-3">
                    <div class="mb-1 flex justify-between text-sm">
                        <span class="text-[--color-ink]">{{ $row['name'] }}</span>
                        <span class="text-[--color-ink-muted]">{{ $row['count'] }} &middot; {{ $share }}%</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-[--color-surface-sunken]" aria-hidden="true">
                        <div class="h-full rounded-full bg-navy-500" style="width: {{ $share }}%"></div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-[--color-ink-muted]">No rooms allotted this month.</p>
            @endforelse
        </div>
    </div>

    {{-- Next in the admin's queue --}}
    <div class="gh-card mt-6 overflow-hidden">
        <div class="flex items-center justify-between border-b border-[--color-line] px-5 py-3.5">
            <h2 class="gh-eyebrow">Next to allot</h2>
            <a href="{{ route('admin.allotments.index') }}" class="text-xs text-[--color-ink-muted] underline">Open queue</a>
        </div>
        @if ($queue->isEmpty())
            <p class="px-5 py-6 text-sm text-[--color-ink-muted]">Nothing is waiting for an availability check.</p>
        @else
            <div class="overflow-x-auto">
                <table class="gh-table">
                    <thead><tr><th scope="col">Request</th><th scope="col">Guest</th><th scope="col">Stay</th><th scope="col">Rooms needed</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                        @foreach ($queue as $r)
                            <tr>
                                <td><a href="{{ route('admin.allotments.create', $r) }}" class="font-medium text-navy-800 underline">{{ $r->request_no }}</a></td>
                                <td class="text-[--color-ink-soft]">{{ $r->guestName() }}</td>
                                <td class="text-[--color-ink-soft]">{{ $r->check_in_date->format('d/m/Y') }} &rarr; {{ $r->check_out_date->format('d/m/Y') }}</td>
                                <td class="text-[--color-ink-soft]">{{ $r->rooms_needed }}</td>
                                <td><x-status :status="$r->status" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
