@extends('layouts.app')

@section('title', 'Allot Rooms')
@section('heading', 'Room Allotment')

@section('content')
    {{-- Screen 3.5 --}}

    @error('allot')
        {{-- This is where the concurrency message surfaces: "Room 101 was allotted
             by another administrator moments ago." --}}
        <div class="gh-alert gh-alert-danger mb-6" role="alert">
            <div>
                <p class="font-semibold">Allotment could not be completed</p>
                <p class="mt-1">{{ $message }}</p>
                <a href="{{ route('admin.availability.show', $request) }}"
                   class="mt-2 inline-block font-medium underline">Re-check availability</a>
            </div>
        </div>
    @enderror

    <div class="grid gap-6 lg:grid-cols-3">

        <div class="space-y-6 lg:col-span-2">

            {{-- Allotment details --}}
            <div class="gh-card p-5 sm:p-6">
                <h2 class="gh-eyebrow mb-4">Allotment details</h2>

                <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-[--color-ink-muted]">Request ID</dt>
                        <dd class="mt-0.5 font-medium text-[--color-ink]">{{ $request->request_no }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[--color-ink-muted]">Guest</dt>
                        <dd class="mt-0.5 font-medium text-[--color-ink]">
                            {{ $request->guestName() }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[--color-ink-muted]">Booking</dt>
                        <dd class="mt-0.5 font-medium text-[--color-ink]">{{ $request->bookingSummary() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[--color-ink-muted]">Persons</dt>
                        <dd class="mt-0.5 font-medium text-[--color-ink]">{{ $request->total_members }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[--color-ink-muted]">From</dt>
                        <dd class="mt-0.5 font-medium text-[--color-ink]">{{ $request->check_in_date->format('d/m/Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[--color-ink-muted]">To</dt>
                        <dd class="mt-0.5 font-medium text-[--color-ink]">
                            {{ $request->check_out_date->format('d/m/Y') }}
                            <span class="font-normal text-[--color-ink-muted]">({{ $request->nights }} {{ Str::plural('night', $request->nights) }})</span>
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- Already held --}}
            @if ($held->isNotEmpty())
                <div class="gh-card overflow-hidden">
                    <div class="border-b border-[--color-line] px-5 py-3.5">
                        <h2 class="gh-eyebrow">Rooms already allotted ({{ $held->count() }} of {{ $request->rooms_needed }})</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="gh-table">
                            <thead>
                                <tr>
                                    <th scope="col">Room</th>
                                    <th scope="col">Type</th>
                                    <th scope="col" class="text-right">Rate/night</th>
                                    <th scope="col" class="text-right">Total</th>
                                    <th scope="col"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($held as $a)
                                    <tr>
                                        <td class="font-medium text-[--color-ink]">{{ $a->room->room_number }}</td>
                                        <td class="text-[--color-ink-soft]">{{ $a->room->roomType->displayName() }}</td>
                                        <td class="text-right text-[--color-ink-soft]">&#8377; {{ number_format((float) $a->rate_per_night, 2) }}</td>
                                        <td class="text-right font-medium text-[--color-ink]">&#8377; {{ number_format((float) $a->total_amount, 2) }}</td>
                                        <td class="text-right">
                                            <form method="POST" action="{{ route('admin.allotments.destroy', $a) }}"
                                                  x-data
                                                  @submit="if (! confirm('Release room {{ $a->room->room_number }}?')) $event.preventDefault()">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-sm font-medium text-[--color-danger] hover:underline">
                                                    Release
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Room selection --}}
            <form method="POST" action="{{ route('admin.allotments.store', $request) }}"
                  x-data="{ chosen: [] }">
                @csrf

                <div class="gh-card overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[--color-line] px-5 py-3.5">
                        <h2 class="gh-eyebrow">Select rooms</h2>
                        <p class="text-xs text-[--color-ink-muted]">
                            <span x-text="chosen.length"></span> selected
                            &middot; {{ $request->rooms_needed - $held->count() }} more suggested
                        </p>
                    </div>

                    @if ($rooms->isEmpty())
                        <div class="p-8 text-center">
                            <p class="text-sm text-[--color-ink-muted]">
                                No rooms are free for {{ \Illuminate\Support\Carbon::parse($from)->format('d/m/Y') }}
                                &ndash; {{ \Illuminate\Support\Carbon::parse($to)->format('d/m/Y') }}.
                            </p>
                            <a href="{{ route('admin.availability.show', $request) }}" class="gh-btn gh-btn-secondary mt-4">
                                Back to availability
                            </a>
                        </div>
                    @else
                        <div class="divide-y divide-[--color-line]">
                            @foreach ($rooms as $typeName => $group)
                                <div class="p-5">
                                    <p class="mb-3 text-sm font-semibold text-[--color-ink]">
                                        {{ $typeName }}
                                        <span class="font-normal text-[--color-ink-muted]">&middot; {{ $group->count() }} free</span>
                                    </p>

                                    <div class="grid grid-cols-3 gap-2 sm:grid-cols-5 lg:grid-cols-6">
                                        @foreach ($group as $room)
                                            <label class="flex cursor-pointer flex-col items-center rounded-lg border border-[--color-line-strong] bg-white px-2 py-2.5 transition-colors hover:border-navy-300
                                                          has-[:checked]:border-navy-600 has-[:checked]:bg-navy-50 has-[:checked]:ring-1 has-[:checked]:ring-navy-600">
                                                <input type="checkbox" name="room_ids[]" value="{{ $room->id }}"
                                                       x-model="chosen" class="sr-only">
                                                <span class="text-sm font-semibold text-[--color-ink]">{{ $room->room_number }}</span>
                                                <span class="text-[0.625rem] text-[--color-ink-faint]">
                                                    sleeps {{ $room->effectiveCapacity() }}
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="gh-hairline-t flex flex-wrap items-center justify-between gap-3 bg-[--color-surface-sunken] px-5 py-4">
                            <p class="text-xs text-[--color-ink-muted]">
                                Allotment is at the discretion of the authority. You may allot
                                more or fewer rooms than suggested.
                            </p>
                            <button type="submit" class="gh-btn gh-btn-primary" :disabled="chosen.length === 0">
                                Confirm Allotment
                            </button>
                        </div>
                    @endif
                </div>
            </form>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            <div class="gh-card p-5">
                <h2 class="gh-eyebrow mb-3">Progress</h2>
                <p class="gh-metric-value text-navy-900">
                    {{ $held->count() }}<span class="text-base font-normal text-[--color-ink-muted]"> / {{ $request->rooms_needed }}</span>
                </p>
                <p class="mt-1 text-xs text-[--color-ink-faint]">rooms allotted</p>

                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-[--color-surface-sunken]" role="presentation">
                    <div class="h-full rounded-full bg-[--color-success]"
                         style="width: {{ $request->rooms_needed > 0 ? min(100, (int) round($held->count() / $request->rooms_needed * 100)) : 0 }}%"></div>
                </div>

                <p class="mt-3 text-xs text-[--color-ink-muted]">
                    <x-status :status="$request->status" />
                </p>
            </div>

            <div class="gh-card p-5">
                <h2 class="gh-eyebrow mb-3">Occupants</h2>
                <ul class="space-y-2 text-sm">
                    @foreach ($request->occupants as $o)
                        <li class="flex items-start justify-between gap-2">
                            <span class="text-[--color-ink-soft]">{{ $o->name }}</span>
                            @if ($o->is_primary)
                                <span class="shrink-0 text-[0.625rem] font-semibold uppercase tracking-wide text-[--color-ink-faint]">Primary</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            <a href="{{ route('admin.availability.show', $request) }}" class="gh-btn gh-btn-secondary w-full">
                Back to availability
            </a>
        </div>
    </div>
@endsection
