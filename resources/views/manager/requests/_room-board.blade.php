{{--
  Manager room board — PLAN.md decision 10.

  A block → floor → room grid for THIS request's dates only. Available rooms
  are selectable; booked and offline rooms are shown for context but cannot be
  picked. Selection state lives in the x-data on manager/requests/show and is
  submitted by the Approve form in the action panel.

  Who holds a booked room is deliberately not shown.
--}}

@php
    $nights = (int) $request->nights;
    $fromLabel = \Illuminate\Support\Carbon::parse($board['from'])->format('d M Y');
    $toLabel = \Illuminate\Support\Carbon::parse($board['to'])->format('d M Y');
@endphp

<section class="gh-card overflow-hidden" aria-labelledby="board-title">

    {{-- Header + legend --}}
    <div class="flex flex-col gap-4 border-b border-[--color-line] px-5 py-4 sm:px-6 md:flex-row md:items-start md:justify-between">
        <div class="min-w-0">
            <h2 id="board-title" class="text-base font-semibold text-[--color-ink]">Room allotment</h2>
            <p class="mt-0.5 text-sm text-[--color-ink-muted]">
                Live status for {{ $fromLabel }} &rarr; {{ $toLabel }}
                ({{ $nights }} {{ Str::plural('night', $nights) }}). Optional &mdash; pick rooms to hold them with your approval.
            </p>
        </div>

        <ul class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-[--color-ink-soft]" aria-label="Legend">
            <li class="flex items-center gap-1.5">
                <span class="h-3.5 w-3.5 rounded bg-emerald-500" aria-hidden="true"></span>
                Available ({{ $board['counts']['available'] }})
            </li>
            <li class="flex items-center gap-1.5">
                <span class="h-3.5 w-3.5 rounded bg-navy-800" aria-hidden="true"></span>
                <span x-text="'Selected (' + selected.length + ')'">Selected (0)</span>
            </li>
            <li class="flex items-center gap-1.5">
                <span class="h-3.5 w-3.5 rounded bg-slate-300" aria-hidden="true"></span>
                Booked ({{ $board['counts']['booked'] }})
            </li>
            <li class="flex items-center gap-1.5">
                <span class="h-3.5 w-3.5 rounded border border-dashed border-slate-400" aria-hidden="true"></span>
                Offline ({{ $board['counts']['offline'] }})
            </li>
        </ul>
    </div>

    {{-- Blocks --}}
    <div class="grid items-start gap-4 p-4 sm:p-5 @if (count($board['blocks']) > 1) xl:grid-cols-2 @endif">
        @foreach ($board['blocks'] as $block)
            @php
                $total = array_sum($block['counts']);
                $allOffline = $block['counts']['offline'] === $total;
            @endphp

            <div class="overflow-hidden rounded-xl border border-[--color-line] bg-white">
                <div class="flex items-center justify-between gap-3 border-b border-[--color-line] bg-navy-50 px-4 py-2.5">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-navy-900">{{ $block['name'] }}</h3>
                    @if ($allOffline)
                        <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-amber-700">Offline</span>
                    @else
                        <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-emerald-700">
                            {{ $block['counts']['available'] }} free &middot; {{ count($block['floors']) }} {{ Str::plural('floor', count($block['floors'])) }}
                        </span>
                    @endif
                </div>

                @if ($allOffline)
                    <div class="m-4 flex flex-col items-center rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center">
                        <x-icon name="lock" class="mb-2 h-6 w-6 text-slate-400" />
                        <p class="text-sm font-semibold text-[--color-ink]">Not available for allotment</p>
                        <p class="mt-1 text-xs text-[--color-ink-muted]">All {{ $total }} rooms in this block are out of service for these dates.</p>
                    </div>
                @else
                    <div class="divide-y divide-[--color-line]">
                        @foreach ($block['floors'] as $floor)
                            <div class="flex items-start gap-3 px-4 py-2.5">
                                <span class="w-14 shrink-0 pt-2 text-[0.6875rem] font-semibold uppercase tracking-wider text-[--color-ink-muted]">
                                    {{ $floor['label'] }}
                                </span>
                                <div class="flex flex-1 flex-wrap gap-1.5">
                                    @foreach ($floor['rooms'] as $room)
                                        @php
                                            $tip = "Room {$room['number']} · {$room['type']} · sleeps {$room['capacity']}"
                                                . ($room['state'] === 'booked' ? ' · booked for these dates' : '')
                                                . ($room['state'] === 'offline' ? ' · '.$room['note'] : '');
                                        @endphp

                                        @if ($room['state'] === 'available')
                                            <button type="button"
                                                    @click="toggle({{ $room['id'] }})"
                                                    :aria-pressed="isSelected({{ $room['id'] }}).toString()"
                                                    :class="isSelected({{ $room['id'] }})
                                                        ? 'bg-navy-800 text-white ring-2 ring-navy-300 ring-offset-1'
                                                        : 'bg-emerald-500 text-white hover:bg-emerald-600'"
                                                    class="relative h-9 min-w-[3.5rem] rounded-md px-2 text-sm font-semibold shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500 focus-visible:ring-offset-2"
                                                    title="{{ $tip }}" aria-label="{{ $tip }}">
                                                {{ $room['number'] }}
                                                <svg x-show="isSelected({{ $room['id'] }})" x-cloak class="absolute -right-1 -top-1 h-4 w-4 rounded-full bg-white text-navy-800" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.7-9.3a1 1 0 0 0-1.4-1.4L9 10.6 7.7 9.3a1 1 0 0 0-1.4 1.4l2 2a1 1 0 0 0 1.4 0l4-4Z" clip-rule="evenodd"/>
                                                </svg>
                                            </button>
                                        @elseif ($room['state'] === 'booked')
                                            <span
                                                  class="flex h-9 min-w-[3.5rem] cursor-not-allowed items-center justify-center rounded-md bg-slate-200 px-2 text-sm font-medium text-slate-500"
                                                  title="{{ $tip }}" aria-label="{{ $tip }}">
                                                {{ $room['number'] }}
                                            </span>
                                        @else
                                            <span
                                                  class="flex h-9 min-w-[3.5rem] cursor-not-allowed items-center justify-center rounded-md border border-dashed border-slate-300 px-2 text-sm font-medium text-slate-400 line-through decoration-slate-300"
                                                  title="{{ $tip }}" aria-label="{{ $tip }}">
                                                {{ $room['number'] }}
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Selection bar --}}
    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[--color-line] bg-[--color-surface-sunken] px-5 py-3 sm:px-6"
         aria-live="polite">
        <p class="text-sm text-[--color-ink-soft]">
            <span class="font-semibold text-[--color-ink]" x-text="selected.length">0</span>
            of {{ $request->rooms_needed }} {{ Str::plural('room', $request->rooms_needed) }} selected
            <span x-show="selected.length" x-cloak>
                &middot; sleeps <span x-text="beds"></span>
            </span>
            <span x-show="selected.length > needed" x-cloak class="ml-1 text-amber-700">(more than requested)</span>
        </p>
        <div class="flex items-center gap-4">
            <button type="button" @click="clear()" x-show="selected.length" x-cloak
                    class="text-sm font-medium text-navy-700 underline decoration-navy-300 underline-offset-2 hover:text-navy-900">
                Clear selection
            </button>
            {{-- On narrow screens the action panel sits below the whole page. --}}
            <a href="#manager-action" x-show="selected.length" x-cloak
               class="gh-btn gh-btn-primary px-3 py-1.5 text-sm lg:hidden">
                Review &amp; approve &darr;
            </a>
        </div>
    </div>
</section>
