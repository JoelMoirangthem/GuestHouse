@extends('layouts.app')

@section('title', 'Allotment Queue')
@section('heading', 'Awaiting Allotment')

@section('content')

    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([
            ['label' => 'With me', 'value' => $queue->total(), 'hint' => 'approved, need rooms'],
            ['label' => 'With managers', 'value' => $counts['pending_manager'], 'hint' => 'first-level review'],
            ['label' => 'With ADG', 'value' => $counts['pending_adg'], 'hint' => 'second-level'],
            ['label' => 'With applicants', 'value' => $counts['more_info'], 'hint' => 'information requested'],
        ] as $tile)
            <div class="gh-card px-4 py-3.5">
                <p class="gh-eyebrow">{{ $tile['label'] }}</p>
                <p class="gh-metric-value mt-1 text-navy-900">{{ $tile['value'] }}</p>
                <p class="mt-0.5 text-xs text-[--color-ink-faint]">{{ $tile['hint'] }}</p>
            </div>
        @endforeach
    </div>

    @if ($queue->isEmpty())
        <div class="gh-card p-10 text-center">
            <div class="mx-auto mb-4 flex h-11 w-11 items-center justify-center rounded-full border border-[--color-line] bg-[--color-surface-sunken] text-[--color-ink-muted]">
                <x-icon name="building" class="h-5 w-5" />
            </div>
            <h2 class="font-serif text-lg text-navy-900">Nothing awaiting allotment</h2>
            <p class="mx-auto mt-1.5 max-w-sm text-sm text-[--color-ink-muted]">
                Requests appear here once the Manager has approved them.
            </p>
        </div>
    @else
        <div class="gh-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="gh-table">
                    <caption class="sr-only">Approved requests awaiting room allotment, soonest arrival first</caption>
                    <thead>
                        <tr>
                            <th scope="col">Request No.</th>
                            <th scope="col">Applicant</th>
                            <th scope="col">Purpose</th>
                            <th scope="col">Stay</th>
                            <th scope="col" class="text-right">Persons</th>
                            <th scope="col" class="text-right">Rooms</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($queue as $r)
                            <tr>
                                <td class="font-medium text-[--color-ink]">{{ $r->request_no }}</td>
                                <td class="text-[--color-ink-soft]">
                                    {{ $r->requester->name }}
                                    <span class="block text-xs text-[--color-ink-faint]">{{ $r->requester->department }}</span>
                                </td>
                                <td class="text-[--color-ink-soft]">{{ $r->purpose->label() }}</td>
                                <td class="whitespace-nowrap text-[--color-ink-soft]">
                                    {{ $r->check_in_date->format('d/m/Y') }}
                                    <span class="text-[--color-ink-faint]">&rarr;</span>
                                    {{ $r->check_out_date->format('d/m/Y') }}
                                    <span class="block text-xs text-[--color-ink-faint]">
                                        {{ $r->check_in_date->diffForHumans(['parts' => 1]) }}
                                    </span>
                                </td>
                                <td class="text-right text-[--color-ink-soft]">{{ $r->total_members }}</td>
                                <td class="text-right text-[--color-ink-soft]">{{ $r->rooms_needed }}</td>
                                <td><x-status :status="$r->status" /></td>
                                <td class="text-right">
                                    <a href="{{ route('admin.availability.show', $r) }}" class="gh-btn gh-btn-primary px-3 py-1.5 text-xs">
                                        Check Availability
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
@endsection
