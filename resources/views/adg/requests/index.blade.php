@extends('layouts.app')

@section('title', 'Pending Approval')
@section('heading', 'Pending ADG Approval')

@section('content')

    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([
            ['label' => 'With me', 'value' => $queue->total(), 'hint' => 'awaiting your approval'],
            ['label' => 'With managers', 'value' => $counts['pending_manager'], 'hint' => 'first-level review'],
            ['label' => 'With applicants', 'value' => $counts['more_info'], 'hint' => 'information requested'],
            ['label' => 'Awaiting rooms', 'value' => $counts['awaiting_allotment'], 'hint' => 'approved by you'],
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
                <x-icon name="check-badge" class="h-5 w-5" />
            </div>
            <h2 class="font-serif text-lg text-navy-900">Nothing awaiting approval</h2>
            <p class="mx-auto mt-1.5 max-w-sm text-sm text-[--color-ink-muted]">
                Requests appear here once a Manager has approved them.
            </p>
        </div>
    @else
        <div class="gh-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="gh-table">
                    <caption class="sr-only">Requests approved by a Manager and awaiting ADG approval</caption>
                    <thead>
                        <tr>
                            <th scope="col">Request No.</th>
                            <th scope="col">Applicant</th>
                            <th scope="col">Purpose</th>
                            <th scope="col">Stay</th>
                            <th scope="col" class="text-right">Persons</th>
                            <th scope="col">Approved by</th>
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
                                    <span class="block text-xs text-[--color-ink-faint]">{{ $r->nights }} {{ Str::plural('night', $r->nights) }}</span>
                                </td>
                                <td class="text-right text-[--color-ink-soft]">{{ $r->total_members }}</td>
                                <td class="text-[--color-ink-soft]">
                                    {{ $r->manager->name ?? '—' }}
                                    <span class="block text-xs text-[--color-ink-faint]">{{ $r->manager_acted_at?->format('d/m/Y') }}</span>
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('adg.requests.show', $r) }}" class="gh-btn gh-btn-secondary px-3 py-1.5 text-xs">
                                        Open
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
