@extends('layouts.app')

@section('title', 'Pending Review')
@section('heading', 'Pending Review')

@section('content')

    {{-- Auto-refresh: the review queue is server-rendered, so new bookings would
         otherwise only appear on a manual reload. This polls by reloading the
         page every 15 seconds while the tab is visible, so a booking a user just
         submitted shows up on its own. Pauses when the tab is hidden to avoid
         needless requests. --}}
    <div x-data="{
            timer: null,
            start() { this.timer = setInterval(() => { if (! document.hidden) window.location.reload(); }, 15000); },
            stop() { if (this.timer) clearInterval(this.timer); }
         }"
         x-init="start()"
         @visibilitychange.document="document.hidden ? stop() : start()"
         aria-hidden="true"></div>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([
            ['label' => 'With me', 'value' => $queue->total(), 'hint' => 'awaiting your decision'],
            ['label' => 'With applicant', 'value' => $waiting->count(), 'hint' => 'information requested'],
            ['label' => 'With ADG', 'value' => $counts['pending_adg'], 'hint' => 'forwarded'],
            ['label' => 'Awaiting rooms', 'value' => $counts['awaiting_allotment'], 'hint' => 'approved'],
        ] as $tile)
            <div class="gh-card px-4 py-3.5">
                <p class="gh-eyebrow">{{ $tile['label'] }}</p>
                <p class="gh-metric-value mt-1 text-navy-900">{{ $tile['value'] }}</p>
                <p class="mt-0.5 text-xs text-[--color-ink-faint]">{{ $tile['hint'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- Primary queue --}}
    @if ($queue->isEmpty())
        <div class="gh-card p-10 text-center">
            <div class="mx-auto mb-4 flex h-11 w-11 items-center justify-center rounded-full border border-[--color-line] bg-[--color-surface-sunken] text-[--color-ink-muted]">
                <x-icon name="inbox" class="h-5 w-5" />
            </div>
            <h2 class="font-serif text-lg text-navy-900">Nothing awaiting review</h2>
            <p class="mx-auto mt-1.5 max-w-sm text-sm text-[--color-ink-muted]">
                Booking requests will appear here when submitted.
            </p>
        </div>
    @else
        <div class="gh-card overflow-hidden">
            <div class="border-b border-[--color-line] px-5 py-3.5">
                <h2 class="gh-eyebrow">Awaiting your decision</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="gh-table">
                    <thead>
                        <tr>
                            <th scope="col">Request No.</th>
                            <th scope="col">Guest</th>
                            <th scope="col">Booking</th>
                            <th scope="col">Stay</th>
                            <th scope="col" class="text-right">Persons</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($queue as $r)
                            <tr>
                                <td class="font-medium text-[--color-ink]">{{ $r->request_no }}</td>
                                <td class="text-[--color-ink-soft]">
                                    {{ $r->guestName() }}
                                </td>
                                <td class="text-[--color-ink-soft]">{{ $r->bookingSummary() }}</td>
                                <td class="whitespace-nowrap text-[--color-ink-soft]">
                                    {{ $r->check_in_date->format('d/m/Y') }}
                                    <span class="text-[--color-ink-faint]">&rarr;</span>
                                    {{ $r->check_out_date->format('d/m/Y') }}
                                </td>
                                <td class="text-right text-[--color-ink-soft]">{{ $r->total_members }}</td>
                                <td class="text-right">
                                    <a href="{{ route('manager.requests.show', $r) }}" class="gh-btn gh-btn-secondary px-3 py-1.5 text-xs">
                                        Review
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if ($queue->hasPages())
            <div class="mt-5">{{ $queue->links() }}</div>
        @endif
    @endif

    {{-- Requests parked with the applicant. Shown separately so they do not
         clutter the action queue, but visible so nothing is silently forgotten. --}}
    @if ($waiting->isNotEmpty())
        <div class="gh-card mt-6 overflow-hidden">
            <div class="border-b border-[--color-line] px-5 py-3.5">
                <h2 class="gh-eyebrow">Waiting on the applicant</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="gh-table">
                    <thead>
                        <tr>
                            <th scope="col">Request No.</th>
                            <th scope="col">Guest</th>
                            <th scope="col">Information requested</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($waiting as $r)
                            <tr>
                                <td class="font-medium text-[--color-ink]">{{ $r->request_no }}</td>
                                <td class="text-[--color-ink-soft]">
                                    {{ $r->guestName() }}
                                </td>
                                <td class="text-[--color-ink-soft]">
                                    {{ $r->more_info_at?->format('d/m/Y') }}
                                    <span class="block max-w-xs truncate text-xs text-[--color-ink-faint]">{{ $r->more_info_note }}</span>
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('manager.requests.show', $r) }}"
                                       class="text-sm font-medium text-navy-700 hover:underline">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Decision history: everything this Manager has approved or rejected.
         A request leaves the queue above the moment it is decided and lands
         here, with the decision and where the request stands now. --}}
    <section id="history" class="gh-card mt-6 overflow-hidden" aria-labelledby="history-title">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[--color-line] px-5 py-3.5">
            <h2 id="history-title" class="gh-eyebrow">My decisions</h2>

            <nav class="flex gap-1.5" aria-label="Filter decisions">
                @foreach (['all' => 'All', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $key => $label)
                    <a href="{{ route('manager.requests.index', $key === 'all' ? [] : ['decision' => $key]) }}#history"
                       @if ($historyFilter === $key) aria-current="page" @endif
                       class="rounded-full border px-3 py-1 text-xs font-medium transition-colors
                              {{ $historyFilter === $key
                                  ? 'border-navy-800 bg-navy-800 text-white'
                                  : 'border-[--color-line-strong] text-[--color-ink-soft] hover:bg-navy-50' }}">
                        {{ $label }} <span class="ml-0.5 opacity-75">{{ $historyCounts[$key] }}</span>
                    </a>
                @endforeach
            </nav>
        </div>

        @if ($history->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-[--color-ink-muted]">
                @if ($historyFilter === 'all')
                    Requests you approve or reject will appear here.
                @else
                    No {{ $historyFilter }} requests yet.
                @endif
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="gh-table">
                    <thead>
                        <tr>
                            <th scope="col">Request No.</th>
                            <th scope="col">Guest</th>
                            <th scope="col">Stay</th>
                            <th scope="col">Your decision</th>
                            <th scope="col">Decided on</th>
                            <th scope="col">Current status</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $r)
                            @php
                                $wasRejected = $r->status === \App\Domain\Enums\RequestStatus::REJECTED_MANAGER;
                            @endphp
                            <tr>
                                <td class="font-medium text-[--color-ink]">{{ $r->request_no }}</td>
                                <td class="text-[--color-ink-soft]">
                                    {{ $r->guestName() }}
                                    <span class="block text-xs text-[--color-ink-faint]">{{ $r->bookingSummary() }}</span>
                                </td>
                                <td class="whitespace-nowrap text-[--color-ink-soft]">
                                    {{ $r->check_in_date->format('d/m/Y') }}
                                    <span class="text-[--color-ink-faint]">&rarr;</span>
                                    {{ $r->check_out_date->format('d/m/Y') }}
                                </td>
                                <td>
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold
                                                 {{ $wasRejected ? 'bg-red-50 text-red-800 ring-1 ring-red-200' : 'bg-green-50 text-green-800 ring-1 ring-green-200' }}">
                                        {{ $wasRejected ? 'Rejected' : 'Approved' }}
                                    </span>
                                    @if ($r->manager_remarks)
                                        <span class="mt-1 block max-w-xs truncate text-xs text-[--color-ink-faint]" title="{{ $r->manager_remarks }}">{{ $r->manager_remarks }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-[--color-ink-soft]">{{ $r->manager_acted_at->format('d/m/Y H:i') }}</td>
                                <td><x-status :status="$r->status" /></td>
                                <td class="text-right">
                                    <a href="{{ route('manager.requests.show', $r) }}"
                                       class="text-sm font-medium text-navy-700 hover:underline">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($history->hasPages())
                <div class="border-t border-[--color-line] px-5 py-3">{{ $history->links() }}</div>
            @endif
        @endif
    </section>
@endsection
