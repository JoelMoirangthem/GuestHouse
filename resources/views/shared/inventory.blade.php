@extends('layouts.app')

@section('title', 'Room Inventory')
@section('heading', 'Room Inventory')

@section('content')
    {{-- No date picker, by design: management sees tonight's position only.
         Date-range availability is the Administration's alone (Core Rule 2). --}}
    <p class="mb-5 text-sm text-[--color-ink-muted]">
        Position for tonight, as of {{ $asOf->format('d/m/Y, g:i a') }}. For management view only.
    </p>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([
            ['label' => 'Total rooms', 'value' => $totals['total']],
            ['label' => 'Available', 'value' => $totals['available']],
            ['label' => 'Booked', 'value' => $totals['booked']],
            ['label' => 'Blocked', 'value' => $totals['blocked']],
        ] as $tile)
            <div class="gh-card px-4 py-3.5">
                <p class="gh-eyebrow">{{ $tile['label'] }}</p>
                <p class="gh-metric-value mt-1 text-navy-900">{{ $tile['value'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="gh-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="gh-table">
                <caption class="sr-only">Room inventory by type for tonight</caption>
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
                            <td class="text-right">{{ $row['total'] }}</td>
                            <td class="text-right">{{ $row['available'] }}</td>
                            <td class="text-right">{{ $row['booked'] }}</td>
                            <td class="text-right">{{ $row['blocked'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="gh-hairline-t bg-[--color-surface-sunken] px-5 py-3">
            <p class="text-xs text-[--color-ink-muted]">Room allotment is at the discretion of the authority.</p>
        </div>
    </div>
@endsection
