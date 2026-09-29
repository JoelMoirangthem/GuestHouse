@extends('layouts.app')

@section('title', 'Front Desk')
@section('heading', 'Front Desk')

@section('content')

    @error('stay')
        <div class="gh-alert gh-alert-danger mb-6" role="alert"><span>{{ $message }}</span></div>
    @enderror

    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([
            ['label' => 'Arrivals due', 'value' => $arrivals->count(), 'hint' => 'today or overdue'],
            ['label' => 'In residence', 'value' => $inResidence->count(), 'hint' => 'currently staying'],
            ['label' => 'Departures due', 'value' => $departures->count(), 'hint' => 'today or overdue'],
            ['label' => 'Extensions', 'value' => $extensions->count(), 'hint' => 'awaiting decision'],
        ] as $tile)
            <div class="gh-card px-4 py-3.5">
                <p class="gh-eyebrow">{{ $tile['label'] }}</p>
                <p class="gh-metric-value mt-1 text-navy-900">{{ $tile['value'] }}</p>
                <p class="mt-0.5 text-xs text-[--color-ink-faint]">{{ $tile['hint'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- Extensions first: they are the only item here with a deadline the guest
         is waiting on. --}}
    @if ($extensions->isNotEmpty())
        <div class="gh-card mb-6 overflow-hidden">
            <div class="border-b border-[--color-line] px-5 py-3.5">
                <h2 class="gh-eyebrow">Extension requests awaiting a decision</h2>
            </div>

            <div class="divide-y divide-[--color-line]">
                @foreach ($extensions as $ext)
                    <div class="p-5" x-data="{ deny: false }">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="font-medium text-[--color-ink]">
                                    {{ $ext->bookingRequest->request_no }}
                                    <span class="font-normal text-[--color-ink-muted]">&middot; {{ $ext->bookingRequest->requester->name }}</span>
                                </p>
                                <p class="mt-1 text-sm text-[--color-ink-soft]">
                                    Room {{ $ext->allotment->room->room_number }} &middot;
                                    {{ $ext->previous_check_out_date->format('d/m/Y') }}
                                    <span class="text-[--color-ink-faint]">&rarr;</span>
                                    {{ $ext->requested_check_out_date->format('d/m/Y') }}
                                    <span class="text-[--color-ink-muted]">
                                        (+{{ $ext->additionalNights() }} {{ Str::plural('night', $ext->additionalNights()) }})
                                    </span>
                                </p>
                                @if ($ext->reason)
                                    <p class="mt-2 rounded-lg border border-[--color-line] bg-[--color-surface-sunken] px-3 py-2 text-sm text-[--color-ink-soft]">
                                        {{ $ext->reason }}
                                    </p>
                                @endif
                            </div>

                            <div class="flex shrink-0 gap-2" x-show="! deny">
                                <form method="POST" action="{{ route('admin.extensions.approve', $ext) }}">
                                    @csrf
                                    <button type="submit" class="gh-btn gh-btn-approve">Approve</button>
                                </form>
                                <button type="button" @click="deny = true" class="gh-btn gh-btn-reject">Deny</button>
                            </div>
                        </div>

                        <form method="POST" action="{{ route('admin.extensions.deny', $ext) }}"
                              x-show="deny" x-cloak class="mt-4 max-w-md">
                            @csrf
                            <label for="deny-{{ $ext->id }}" class="gh-label gh-required">Reason for denial</label>
                            <textarea id="deny-{{ $ext->id }}" name="reason" rows="2" required minlength="5" class="gh-textarea"></textarea>
                            <div class="mt-2 flex gap-2">
                                <button type="button" @click="deny = false" class="gh-btn gh-btn-secondary">Back</button>
                                <button type="submit" class="gh-btn gh-btn-reject">Confirm Deny</button>
                            </div>
                        </form>

                        <p class="mt-3 text-xs text-[--color-ink-faint]">
                            Approving re-verifies that the room is free for the additional nights.
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">

        {{-- Arrivals --}}
        <div class="gh-card overflow-hidden">
            <div class="border-b border-[--color-line] px-5 py-3.5">
                <h2 class="gh-eyebrow">Arrivals due</h2>
            </div>

            @forelse ($arrivals as $r)
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[--color-line] px-5 py-3.5 last:border-b-0">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-[--color-ink]">{{ $r->request_no }}</p>
                        <p class="text-xs text-[--color-ink-muted]">
                            {{ $r->requester->name }} &middot; {{ $r->total_members }} {{ Str::plural('person', $r->total_members) }}
                            &middot; due {{ $r->check_in_date->format('d/m/Y') }}
                        </p>
                    </div>
                    <form method="POST" action="{{ route('admin.stays.checkIn', $r) }}">
                        @csrf
                        <button type="submit" class="gh-btn gh-btn-approve px-3 py-1.5 text-xs">Check in</button>
                    </form>
                </div>
            @empty
                <p class="px-5 py-8 text-center text-sm text-[--color-ink-muted]">No arrivals due.</p>
            @endforelse
        </div>

        {{-- Departures --}}
        <div class="gh-card overflow-hidden">
            <div class="border-b border-[--color-line] px-5 py-3.5">
                <h2 class="gh-eyebrow">Departures due</h2>
            </div>

            @forelse ($departures as $r)
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[--color-line] px-5 py-3.5 last:border-b-0">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-[--color-ink]">{{ $r->request_no }}</p>
                        <p class="text-xs text-[--color-ink-muted]">
                            {{ $r->requester->name }} &middot; due {{ $r->check_out_date->format('d/m/Y') }}
                        </p>
                    </div>
                    <form method="POST" action="{{ route('admin.stays.checkOut', $r) }}">
                        @csrf
                        <button type="submit" class="gh-btn gh-btn-secondary px-3 py-1.5 text-xs">Check out</button>
                    </form>
                </div>
            @empty
                <p class="px-5 py-8 text-center text-sm text-[--color-ink-muted]">No departures due.</p>
            @endforelse
        </div>

        {{-- In residence --}}
        <div class="gh-card overflow-hidden lg:col-span-2">
            <div class="border-b border-[--color-line] px-5 py-3.5">
                <h2 class="gh-eyebrow">In residence</h2>
            </div>

            @if ($inResidence->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-[--color-ink-muted]">Nobody is currently staying.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="gh-table">
                        <thead>
                            <tr>
                                <th scope="col">Request No.</th>
                                <th scope="col">Guest</th>
                                <th scope="col">Departs</th>
                                <th scope="col">Status</th>
                                <th scope="col"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($inResidence as $r)
                                <tr>
                                    <td class="font-medium text-[--color-ink]">{{ $r->request_no }}</td>
                                    <td class="text-[--color-ink-soft]">{{ $r->requester->name }}</td>
                                    <td class="text-[--color-ink-soft]">
                                        {{ $r->check_out_date->format('d/m/Y') }}
                                        @if ($r->check_out_date->isToday())
                                            <span class="block text-xs font-medium text-[--color-pending]">today</span>
                                        @endif
                                    </td>
                                    <td><x-status :status="$r->status" /></td>
                                    <td class="text-right">
                                        <form method="POST" action="{{ route('admin.stays.checkOut', $r) }}">
                                            @csrf
                                            <button type="submit" class="text-sm font-medium text-navy-700 hover:underline">
                                                Check out
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Upcoming --}}
        @if ($upcoming->isNotEmpty())
            <div class="gh-card overflow-hidden lg:col-span-2">
                <div class="border-b border-[--color-line] px-5 py-3.5">
                    <h2 class="gh-eyebrow">Upcoming arrivals</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="gh-table">
                        <thead>
                            <tr>
                                <th scope="col">Request No.</th>
                                <th scope="col">Guest</th>
                                <th scope="col">Arrives</th>
                                <th scope="col" class="text-right">Persons</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($upcoming as $r)
                                <tr>
                                    <td class="font-medium text-[--color-ink]">{{ $r->request_no }}</td>
                                    <td class="text-[--color-ink-soft]">{{ $r->requester->name }}</td>
                                    <td class="text-[--color-ink-soft]">
                                        {{ $r->check_in_date->format('d/m/Y') }}
                                        <span class="block text-xs text-[--color-ink-faint]">
                                            {{ $r->check_in_date->diffForHumans(['parts' => 1]) }}
                                        </span>
                                    </td>
                                    <td class="text-right text-[--color-ink-soft]">{{ $r->total_members }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
@endsection
