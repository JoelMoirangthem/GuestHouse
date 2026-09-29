@extends('layouts.app')

@section('title', 'Holidays ' . $year)
@section('heading', 'Holiday calendar')

@section('content')
    @include('admin._errors')

    <div class="mb-5 flex items-center gap-2">
        <a href="{{ route('admin.holidays.index', ['year' => $year - 1]) }}" class="gh-btn gh-btn-secondary px-2.5" aria-label="Previous year">&larr;</a>
        <h2 class="min-w-[4rem] text-center text-sm font-semibold text-[--color-ink]">{{ $year }}</h2>
        <a href="{{ route('admin.holidays.index', ['year' => $year + 1]) }}" class="gh-btn gh-btn-secondary px-2.5" aria-label="Next year">&rarr;</a>
    </div>

    @php $h = $editing; @endphp
    <form method="POST" action="{{ $h ? route('admin.holidays.update', $h) : route('admin.holidays.store') }}" class="gh-card mb-6 p-5" novalidate>
        @csrf
        @if ($h) @method('PUT') @endif
        <h2 class="gh-eyebrow mb-4">{{ $h ? 'Edit ' . $h->name : 'Add a holiday' }}</h2>
        <div class="grid items-end gap-4 sm:grid-cols-[1fr_2fr_1fr_auto]">
            <div>
                <label for="date" class="gh-label gh-required">Date</label>
                <input id="date" name="date" type="date" required value="{{ old('date', $h?->date->format('Y-m-d')) }}"
                       class="gh-input" @error('date') aria-invalid="true" @enderror>
            </div>
            <div>
                <label for="name" class="gh-label gh-required">Name</label>
                <input id="name" name="name" type="text" required maxlength="120" value="{{ old('name', $h?->name) }}"
                       class="gh-input" @error('name') aria-invalid="true" @enderror>
            </div>
            <div>
                <label for="type" class="gh-label gh-required">Type</label>
                <select id="type" name="type" class="gh-select">
                    @foreach ($types as $key => $label)
                        <option value="{{ $key }}" @selected(old('type', $h?->type ?? 'PUBLIC') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2">
                @if ($h)<a href="{{ route('admin.holidays.index', ['year' => $year]) }}" class="gh-btn gh-btn-secondary">Cancel</a>@endif
                <button type="submit" class="gh-btn gh-btn-primary">{{ $h ? 'Save' : 'Add' }}</button>
            </div>
        </div>
    </form>

    <div class="gh-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="gh-table">
                <caption class="sr-only">Holidays in {{ $year }}</caption>
                <thead><tr><th scope="col">Date</th><th scope="col">Day</th><th scope="col">Name</th><th scope="col">Type</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($holidays as $row)
                        <tr>
                            <td class="font-medium text-[--color-ink]">{{ $row->date->format('d/m/Y') }}</td>
                            <td class="text-[--color-ink-soft]">{{ $row->date->format('l') }}</td>
                            <td class="text-[--color-ink-soft]">{{ $row->name }}</td>
                            <td class="text-[--color-ink-soft]">{{ $row->typeLabel() }}</td>
                            <td class="whitespace-nowrap text-right">
                                <a href="{{ route('admin.holidays.index', ['year' => $year, 'edit' => $row->id]) }}" class="gh-btn gh-btn-ghost text-[0.8125rem]">Edit<span class="sr-only"> {{ $row->name }}</span></a>
                                <form method="POST" action="{{ route('admin.holidays.destroy', $row) }}" class="inline" x-data
                                      @submit="if (! confirm('Remove {{ addslashes($row->name) }} from the calendar?')) $event.preventDefault()">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="gh-btn gh-btn-ghost text-[0.8125rem] text-[--color-danger]">Remove<span class="sr-only"> {{ $row->name }}</span></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-[--color-ink-muted]">No holidays recorded for {{ $year }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
