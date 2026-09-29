@extends('layouts.app')

@section('title', 'Room Inventory')
@section('heading', 'Room Inventory')

@section('content')

    <form method="GET" action="{{ route('admin.inventory') }}" class="gh-card mb-6 p-5">
        <h2 class="gh-eyebrow mb-4">Inventory for a date range</h2>
        <div class="grid items-end gap-4 sm:grid-cols-[1fr_1fr_auto]">
            <div>
                <label for="from" class="gh-label">From</label>
                <input id="from" name="from" type="date" value="{{ $from }}" class="gh-input">
            </div>
            <div>
                <label for="to" class="gh-label">To</label>
                <input id="to" name="to" type="date" value="{{ $to }}" class="gh-input">
            </div>
            <button type="submit" class="gh-btn gh-btn-primary">Apply</button>
        </div>
    </form>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([
            ['label' => 'Total rooms', 'value' => $totals['total'], 'tone' => 'text-navy-900'],
            ['label' => 'Available', 'value' => $totals['available'], 'tone' => 'text-[--color-success]'],
            ['label' => 'Booked', 'value' => $totals['booked'], 'tone' => 'text-[--color-info]'],
            ['label' => 'Blocked', 'value' => $totals['blocked'], 'tone' => 'text-[--color-danger]'],
        ] as $tile)
            <div class="gh-card px-4 py-3.5">
                <p class="gh-eyebrow">{{ $tile['label'] }}</p>
                <p class="gh-metric-value mt-1 {{ $tile['tone'] }}">{{ $tile['value'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="gh-card overflow-hidden">
        <div class="border-b border-[--color-line] px-5 py-3.5">
            <h2 class="gh-eyebrow">By room type</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="gh-table">
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
                    @foreach ($summary as $row)
                        <tr>
                            <td class="text-[--color-ink-muted]">{{ $row['category'] === 'VIP' ? 'VIP' : 'Normal' }}</td>
                            <td class="font-medium text-[--color-ink]">{{ $row['name'] }}</td>
                            <td class="text-right text-[--color-ink-soft]">{{ $row['total'] }}</td>
                            <td class="text-right font-semibold text-[--color-success]">{{ $row['available'] }}</td>
                            <td class="text-right text-[--color-ink-soft]">{{ $row['booked'] }}</td>
                            <td class="text-right text-[--color-ink-soft]">{{ $row['blocked'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="gh-hairline-t bg-[--color-surface-sunken] px-5 py-3">
            <p class="text-xs text-[--color-ink-muted]">
                Availability is for management view only. Room allotment is at the discretion
                of the authority. Available + Booked + Blocked always equals Total.
            </p>
        </div>
    </div>

    {{-- Blocked rooms, so an administrator can see why capacity is reduced --}}
    @if ($blockedRooms->isNotEmpty())
        <div class="gh-card mt-6 overflow-hidden">
            <div class="border-b border-[--color-line] px-5 py-3.5">
                <h2 class="gh-eyebrow">Rooms out of service ({{ $blockedRooms->count() }})</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="gh-table">
                    <thead>
                        <tr>
                            <th scope="col">Room</th>
                            <th scope="col">Type</th>
                            <th scope="col">State</th>
                            <th scope="col">Period</th>
                            <th scope="col">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($blockedRooms as $room)
                            <tr>
                                <td class="font-medium text-[--color-ink]">{{ $room->room_number }}</td>
                                <td class="text-[--color-ink-soft]">{{ $room->roomType->name }}</td>
                                <td>
                                    <span class="gh-status {{ $room->status->badgeClasses() }}">{{ $room->status->label() }}</span>
                                </td>
                                <td class="text-[--color-ink-soft]">
                                    @if ($room->blocked_from)
                                        {{ $room->blocked_from->format('d/m/Y') }} &rarr;
                                        {{ $room->blocked_to?->format('d/m/Y') ?? 'open-ended' }}
                                    @else
                                        &mdash;
                                    @endif
                                </td>
                                <td class="text-[--color-ink-soft]">{{ $room->block_reason ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
