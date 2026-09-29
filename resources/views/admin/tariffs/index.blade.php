@extends('layouts.app')

@section('title', 'Tariffs')
@section('heading', 'Tariffs')

@section('content')
    @include('admin._errors')

    <form method="POST" action="{{ route('admin.tariffs.store') }}" class="gh-card mb-6 p-5" novalidate>
        @csrf
        <h2 class="gh-eyebrow mb-4">Add a rate revision</h2>
        <div class="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-[1.5fr_1fr_1fr_1fr_1fr_auto]">
            <div>
                <label for="room_type_id" class="gh-label gh-required">Room type</label>
                <select id="room_type_id" name="room_type_id" required class="gh-select">
                    @foreach ($types as $t)
                        <option value="{{ $t->id }}" @selected((int) old('room_type_id') === $t->id)>{{ $t->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="purpose" class="gh-label">Purpose</label>
                <select id="purpose" name="purpose" class="gh-select">
                    <option value="">All purposes</option>
                    @foreach ($purposes as $p)
                        <option value="{{ $p->value }}" @selected(old('purpose') === $p->value)>{{ $p->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="amount_per_night" class="gh-label gh-required">Rate per night (₹)</label>
                <input id="amount_per_night" name="amount_per_night" type="number" min="0" step="0.01" required
                       value="{{ old('amount_per_night') }}" class="gh-input" @error('amount_per_night') aria-invalid="true" @enderror>
            </div>
            <div>
                <label for="effective_from" class="gh-label gh-required">Effective from</label>
                <input id="effective_from" name="effective_from" type="date" required
                       value="{{ old('effective_from', now()->format('Y-m-d')) }}" class="gh-input">
            </div>
            <div>
                <label for="effective_to" class="gh-label">Until</label>
                <input id="effective_to" name="effective_to" type="date" value="{{ old('effective_to') }}" class="gh-input">
            </div>
            <button type="submit" class="gh-btn gh-btn-primary">Add</button>
        </div>
        <p class="gh-help mt-3">
            Rates are never edited in place. A new rate closes the previous open-ended one the day before it starts.
            Allotments already made keep the rate they were given.
        </p>
    </form>

    @foreach ($types as $t)
        <div class="gh-card mb-5 overflow-hidden">
            <div class="border-b border-[--color-line] px-5 py-3.5">
                <h2 class="gh-eyebrow">{{ $t->displayName() }} @unless ($t->is_active) · retired @endunless</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="gh-table">
                    <thead>
                        <tr>
                            <th scope="col">Purpose</th>
                            <th scope="col" class="text-right">Rate / night</th>
                            <th scope="col">From</th>
                            <th scope="col">Until</th>
                            <th scope="col">State</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($t->tariffs as $tariff)
                            @php
                                $today = now()->format('Y-m-d');
                                $from = $tariff->effective_from->format('Y-m-d');
                                $to = $tariff->effective_to?->format('Y-m-d');
                                $state = ! $tariff->is_active ? 'Inactive'
                                    : ($from > $today ? 'Scheduled' : ($to !== null && $to < $today ? 'Expired' : 'Current'));
                            @endphp
                            <tr x-data="{ ending: false }">
                                <td class="text-[--color-ink-soft]">{{ $tariff->purpose?->label() ?? 'All purposes' }}</td>
                                <td class="text-right font-medium text-[--color-ink]">₹{{ number_format((float) $tariff->amount_per_night, 2) }}</td>
                                <td class="text-[--color-ink-soft]">{{ $tariff->effective_from->format('d/m/Y') }}</td>
                                <td class="text-[--color-ink-soft]">{{ $tariff->effective_to?->format('d/m/Y') ?? 'open-ended' }}</td>
                                <td class="text-[--color-ink-soft]">{{ $state }}</td>
                                <td class="whitespace-nowrap text-right">
                                    @if ($state !== 'Expired' && $state !== 'Inactive')
                                        <button type="button" @click="ending = !ending" :aria-expanded="ending" x-show="!ending"
                                                class="gh-btn gh-btn-ghost text-[0.8125rem]">Set end date</button>
                                        <form method="POST" action="{{ route('admin.tariffs.end', $tariff) }}" x-show="ending" x-cloak
                                              class="inline-flex items-end gap-2">
                                            @csrf
                                            <label for="end-{{ $tariff->id }}" class="sr-only">Last day this rate applies</label>
                                            <input id="end-{{ $tariff->id }}" name="effective_to" type="date" required
                                                   min="{{ $from }}" class="gh-input">
                                            <button type="submit" class="gh-btn gh-btn-secondary text-[0.8125rem]">Save</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-[--color-ink-muted]">No tariff — allotments of this type will be charged ₹0.00.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
@endsection
