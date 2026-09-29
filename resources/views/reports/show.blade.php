@extends('layouts.app')

@section('title', $report->title)
@section('heading', $report->title)

@section('content')
    @include('admin._errors')

    @php $q = ['from' => $report->from, 'to' => $report->to]; @endphp

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" action="{{ route('reports.show', $report->type) }}" class="flex flex-wrap items-end gap-3">
            <div>
                <label for="from" class="gh-label">From</label>
                <input id="from" name="from" type="date" value="{{ $report->from }}" class="gh-input">
            </div>
            <div>
                <label for="to" class="gh-label">To</label>
                <input id="to" name="to" type="date" value="{{ $report->to }}" class="gh-input">
            </div>
            <button type="submit" class="gh-btn gh-btn-secondary">Apply</button>
        </form>

        <div class="flex gap-2">
            <a href="{{ route('reports.export', ['type' => $report->type, 'format' => 'xlsx', ...$q]) }}" class="gh-btn gh-btn-secondary">Export Excel</a>
            <a href="{{ route('reports.export', ['type' => $report->type, 'format' => 'pdf', ...$q]) }}" class="gh-btn gh-btn-secondary">Export PDF</a>
        </div>
    </div>

    <nav class="mb-5 flex flex-wrap gap-1.5" aria-label="Other reports">
        @foreach ($types as $type => $title)
            <a href="{{ route('reports.show', ['type' => $type, ...$q]) }}"
               class="rounded-full border px-3 py-1 text-xs {{ $type === $report->type ? 'border-navy-700 bg-navy-50 font-semibold text-navy-900' : 'border-[--color-line] text-[--color-ink-muted] hover:bg-[--color-surface-sunken]' }}"
               @if ($type === $report->type) aria-current="page" @endif>{{ $title }}</a>
        @endforeach
    </nav>

    @if ($report->scopeLabel)
        <div class="gh-alert gh-alert-info mb-5" role="note"><span>{{ $report->scopeLabel }}.</span></div>
    @endif

    @if ($report->summary)
        <dl class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($report->summary as $label => $value)
                <div class="gh-card px-4 py-3">
                    <dt class="gh-eyebrow">{{ $label }}</dt>
                    <dd class="mt-1 text-lg font-semibold text-[--color-ink]">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    @endif

    <div class="gh-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="gh-table">
                <caption class="sr-only">{{ $report->title }}, {{ $report->from }} to {{ $report->to }}</caption>
                <thead>
                    <tr>
                        @foreach ($report->columns as $key => $label)
                            <th scope="col" @class(['text-right' => in_array($key, $report->numeric, true)])>{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report->rows as $row)
                        <tr>
                            @foreach ($report->columns as $key => $label)
                                <td @class(['text-right tabular-nums' => in_array($key, $report->numeric, true), 'text-[--color-ink-soft]'])>{{ $row[$key] ?? '—' }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($report->columns) }}" class="py-8 text-center text-[--color-ink-muted]">No records in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($report->notes)
            <div class="gh-hairline-t space-y-1 bg-[--color-surface-sunken] px-5 py-3">
                @foreach ($report->notes as $note)
                    <p class="text-xs text-[--color-ink-muted]">{{ $note }}</p>
                @endforeach
            </div>
        @endif
    </div>
@endsection
