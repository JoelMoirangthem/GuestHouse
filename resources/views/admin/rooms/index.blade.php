@extends('layouts.app')

@section('title', 'Rooms')
@section('heading', 'Rooms')

@section('content')
    @include('admin._errors')

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" action="{{ route('admin.rooms.index') }}" class="flex flex-wrap items-end gap-3">
            <div>
                <label for="type" class="gh-label">Room type</label>
                <select id="type" name="type" class="gh-select">
                    <option value="">All types</option>
                    @foreach ($types as $t)
                        <option value="{{ $t->id }}" @selected($typeFilter === $t->id)>{{ $t->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="state" class="gh-label">State</label>
                <select id="state" name="state" class="gh-select">
                    <option value="">Any</option>
                    <option value="active" @selected($stateFilter === 'active')>In service</option>
                    <option value="blocked" @selected($stateFilter === 'blocked')>Out of service</option>
                </select>
            </div>
            <button type="submit" class="gh-btn gh-btn-secondary">Filter</button>
        </form>

        <div class="flex gap-2">
            <a href="{{ route('admin.room-types.index') }}" class="gh-btn gh-btn-secondary">Room types</a>
            <a href="{{ route('admin.rooms.create') }}" class="gh-btn gh-btn-primary">Add room</a>
        </div>
    </div>

    <div class="gh-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="gh-table">
                <thead>
                    <tr>
                        <th scope="col">Room</th>
                        <th scope="col">Type</th>
                        <th scope="col" class="text-right">Capacity</th>
                        <th scope="col">State</th>
                        <th scope="col">Block</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rooms as $room)
                        @php $outOfService = ! $room->status->isAllottable() || $room->blocked_from !== null; @endphp
                        <tr x-data="{ blocking: false, mode: 'MAINTENANCE' }">
                            <td class="font-medium text-[--color-ink]">
                                {{ $room->room_number }}
                                @if ($room->floor !== null || $room->block)
                                    <span class="block text-xs font-normal text-[--color-ink-muted]">
                                        {{ $room->block ? 'Block ' . $room->block : '' }}{{ $room->block && $room->floor !== null ? ', ' : '' }}{{ $room->floor !== null ? 'Floor ' . $room->floor : '' }}
                                    </span>
                                @endif
                            </td>
                            <td class="text-[--color-ink-soft]">{{ $room->roomType->displayName() }}</td>
                            <td class="text-right text-[--color-ink-soft]">
                                {{ $room->effectiveCapacity() }}
                                @if ($room->capacity !== null)<span class="sr-only">(overridden)</span><span aria-hidden="true">*</span>@endif
                            </td>
                            <td>
                                <span class="gh-status {{ $room->status->badgeClasses() }}">{{ $room->status->label() }}</span>
                            </td>
                            <td class="text-[--color-ink-soft]">
                                @if ($room->blocked_from)
                                    {{ $room->blocked_from->format('d/m/Y') }} &rarr; {{ $room->blocked_to?->format('d/m/Y') ?? 'open-ended' }}
                                @endif
                                @if ($room->block_reason)
                                    <span class="block text-xs text-[--color-ink-muted]">{{ $room->block_reason }}</span>
                                @endif
                                @unless ($room->blocked_from || $room->block_reason) &mdash; @endunless
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <a href="{{ route('admin.rooms.edit', $room) }}" class="gh-btn gh-btn-ghost text-[0.8125rem]">
                                    Edit<span class="sr-only"> room {{ $room->room_number }}</span>
                                </a>
                                @if ($outOfService)
                                    <form method="POST" action="{{ route('admin.rooms.unblock', $room) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="gh-btn gh-btn-approve text-[0.8125rem]">
                                            Unblock<span class="sr-only"> room {{ $room->room_number }}</span>
                                        </button>
                                    </form>
                                @else
                                    <button type="button" @click="blocking = !blocking" :aria-expanded="blocking"
                                            class="gh-btn gh-btn-reject text-[0.8125rem]">
                                        Block<span class="sr-only"> room {{ $room->room_number }}</span>
                                    </button>
                                @endif
                            </td>
                        </tr>
                        @unless ($outOfService)
                            <tr x-show="blocking" x-cloak>
                                <td colspan="6" class="bg-[--color-surface-sunken]">
                                    <form method="POST" action="{{ route('admin.rooms.block', $room) }}"
                                          class="grid items-end gap-3 py-2 sm:grid-cols-[auto_1fr_1fr_2fr_auto]">
                                        @csrf
                                        <div>
                                            <label for="mode-{{ $room->id }}" class="gh-label">Block type</label>
                                            <select id="mode-{{ $room->id }}" name="mode" x-model="mode" class="gh-select">
                                                <option value="MAINTENANCE">Maintenance (until unblocked)</option>
                                                <option value="BLOCKED">Blocked (until unblocked)</option>
                                                <option value="RANGE">For a date range</option>
                                            </select>
                                        </div>
                                        <div x-show="mode === 'RANGE'">
                                            <label for="from-{{ $room->id }}" class="gh-label">From</label>
                                            <input id="from-{{ $room->id }}" name="blocked_from" type="date" class="gh-input" :required="mode === 'RANGE'">
                                        </div>
                                        <div x-show="mode === 'RANGE'">
                                            <label for="to-{{ $room->id }}" class="gh-label">To (inclusive)</label>
                                            <input id="to-{{ $room->id }}" name="blocked_to" type="date" class="gh-input">
                                        </div>
                                        <div>
                                            <label for="reason-{{ $room->id }}" class="gh-label gh-required">Reason</label>
                                            <input id="reason-{{ $room->id }}" name="block_reason" type="text" required minlength="3" maxlength="255" class="gh-input"
                                                   placeholder="e.g. AC repair">
                                        </div>
                                        <button type="submit" class="gh-btn gh-btn-reject">Confirm block</button>
                                    </form>
                                    <p class="gh-help pb-2">Refused if the room is allotted during the block. Leave "To" empty for an open-ended block.</p>
                                </td>
                            </tr>
                        @endunless
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-[--color-ink-muted]">No rooms match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="gh-hairline-t bg-[--color-surface-sunken] px-5 py-3">
            <p class="text-xs text-[--color-ink-muted]">* Capacity overridden on this room; otherwise the room type's default applies.</p>
        </div>
    </div>

    <div class="mt-5">{{ $rooms->links() }}</div>
@endsection
