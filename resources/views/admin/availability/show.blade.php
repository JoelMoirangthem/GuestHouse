@extends('layouts.app')

@section('title', 'Check Availability')
@section('heading', 'Check Availability')

@section('content')
    {{-- Screen 3.3. Reachable only when the request has cleared both approval
         stages — the controller aborts 409 otherwise. --}}

    <div class="mb-6 gh-card p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="gh-eyebrow">Request</p>
                <p class="mt-1 font-serif text-xl text-navy-900">{{ $request->request_no }}</p>
                <p class="mt-1 text-sm text-[--color-ink-muted]">
                    {{ $request->requester->name }} &middot; {{ $request->purpose->label() }}
                    &middot; {{ $request->total_members }} {{ Str::plural('person', $request->total_members) }}
                </p>
            </div>
            <div class="text-right">
                <p class="gh-eyebrow">Rooms estimated</p>
                <p class="gh-metric-value mt-1 text-navy-900">{{ $request->rooms_needed }}</p>
                <p class="text-xs text-[--color-ink-faint]">you may allot more or fewer</p>
            </div>
        </div>
    </div>

    {{-- Date range search --}}
    <form method="GET" action="{{ route('admin.availability.show', $request) }}" class="gh-card mb-6 p-5">
        <h2 class="gh-eyebrow mb-4">Check room availability</h2>

        <div class="grid items-end gap-4 sm:grid-cols-[1fr_1fr_auto]">
            <div>
                <label for="from" class="gh-label">From date</label>
                <input id="from" name="from" type="date" value="{{ $from }}" class="gh-input">
            </div>
            <div>
                <label for="to" class="gh-label">To date</label>
                <input id="to" name="to" type="date" value="{{ $to }}" class="gh-input">
            </div>
            <button type="submit" class="gh-btn gh-btn-primary">Search</button>
        </div>

        @if ($from !== $request->check_in_date->format('Y-m-d') || $to !== $request->check_out_date->format('Y-m-d'))
            <p class="gh-help mt-3">
                Showing a different window from the one requested
                ({{ $request->check_in_date->format('d/m/Y') }} &ndash; {{ $request->check_out_date->format('d/m/Y') }}).
            </p>
        @endif
    </form>

    {{-- Summary --}}
    <div class="gh-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[--color-line] px-5 py-3.5">
            <h2 class="gh-eyebrow">Availability summary (Admin view)</h2>
            <p class="text-xs text-[--color-ink-muted]">
                {{ \Illuminate\Support\Carbon::parse($from)->format('d/m/Y') }}
                &rarr;
                {{ \Illuminate\Support\Carbon::parse($to)->format('d/m/Y') }}
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="gh-table">
                <caption class="sr-only">Rooms by type for the selected date range</caption>
                <thead>
                    <tr>
                        <th scope="col">Category</th>
                        <th scope="col">Room type</th>
                        <th scope="col" class="text-right">Total</th>
                        <th scope="col" class="text-right">Available</th>
                        <th scope="col" class="text-right">Booked</th>
                        <th scope="col" class="text-right">Blocked</th>
                    </tr>
                </thead>
                <tbody>
                    @php $lastCategory = null; @endphp
                    @foreach ($summary as $row)
                        <tr>
                            <td class="text-[--color-ink-muted]">
                                @if ($row['category'] !== $lastCategory)
                                    <span class="font-medium text-[--color-ink-soft]">
                                        {{ $row['category'] === 'VIP' ? 'VIP Rooms' : 'Normal Rooms' }}
                                    </span>
                                    @php $lastCategory = $row['category']; @endphp
                                @endif
                            </td>
                            <td class="font-medium text-[--color-ink]">
                                {{ $row['name'] }}@if ($row['category'] === 'VIP') <span class="text-[--color-ink-muted]">(VIP)</span>@endif
                                <span class="block text-xs text-[--color-ink-faint]">
                                    sleeps {{ $row['capacity'] }}
                                </span>
                            </td>
                            <td class="text-right text-[--color-ink-soft]">{{ $row['total'] }}</td>
                            <td class="text-right">
                                <span class="font-semibold {{ $row['available'] > 0 ? 'text-[--color-success]' : 'text-[--color-danger]' }}">
                                    {{ $row['available'] }}
                                </span>
                            </td>
                            <td class="text-right text-[--color-ink-soft]">{{ $row['booked'] }}</td>
                            <td class="text-right text-[--color-ink-soft]">{{ $row['blocked'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-[--color-line-strong] bg-[--color-surface-sunken]">
                        <td colspan="2" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-[--color-ink-muted]">All types</td>
                        <td class="px-4 py-2.5 text-right font-semibold">{{ $totals['total'] }}</td>
                        <td class="px-4 py-2.5 text-right font-semibold {{ $totals['available'] > 0 ? 'text-[--color-success]' : 'text-[--color-danger]' }}">{{ $totals['available'] }}</td>
                        <td class="px-4 py-2.5 text-right font-semibold">{{ $totals['booked'] }}</td>
                        <td class="px-4 py-2.5 text-right font-semibold">{{ $totals['blocked'] }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        {{-- Verbatim intent from the infographic. --}}
        <div class="gh-hairline-t bg-[--color-surface-sunken] px-5 py-3">
            <p class="flex items-center gap-2 text-xs text-[--color-ink-muted]">
                <x-icon name="lock" class="h-3.5 w-3.5 shrink-0" />
                Availability is checked by the Administration only. Room allotment is at the
                discretion of the authority.
            </p>
        </div>
    </div>

    {{-- Next steps --}}
    <div class="mt-6 flex flex-wrap items-center justify-end gap-3" x-data="{ noRoom: false }">
        <div x-show="! noRoom" class="flex flex-wrap items-center gap-3">
            @if ($totals['available'] > 0)
                <a href="{{ route('admin.allotments.create', $request) }}" class="gh-btn gh-btn-primary">
                    Proceed to Allotment
                </a>
            @endif
            <button type="button" @click="noRoom = true" class="gh-btn gh-btn-reject">
                Mark No Room Available
            </button>
        </div>

        <form method="POST" action="{{ route('admin.allotments.noRoom', $request) }}"
              x-show="noRoom" x-cloak class="gh-card w-full max-w-md p-5">
            @csrf
            <label for="reason" class="gh-label gh-required">Reason</label>
            <textarea id="reason" name="reason" rows="3" required minlength="5" class="gh-textarea"
                      placeholder="The applicant and the ADG will both see this."></textarea>
            <p class="gh-help">
                The request is closed as unfulfilled. It can be re-checked later if
                something frees up.
            </p>
            <div class="mt-3 grid grid-cols-2 gap-2">
                <button type="button" @click="noRoom = false" class="gh-btn gh-btn-secondary">Back</button>
                <button type="submit" class="gh-btn gh-btn-reject">Confirm</button>
            </div>
        </form>
    </div>
@endsection
