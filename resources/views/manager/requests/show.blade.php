@extends('layouts.app')

@section('title', 'Review ' . $request->request_no)
@section('heading', 'Manager Review')

@php
    // Flat index of selectable rooms for the selection summary in the action
    // panel. Only available rooms are selectable, so only they are indexed.
    $roomIndex = [];
    foreach ($board['blocks'] ?? [] as $block) {
        foreach ($block['floors'] as $floor) {
            foreach ($floor['rooms'] as $room) {
                if ($room['state'] === 'available') {
                    $roomIndex[$room['id']] = ['number' => $room['number'], 'type' => $room['type'], 'capacity' => $room['capacity']];
                }
            }
        }
    }

    // After a failed approve (e.g. a room taken a moment ago), restore the
    // selection minus anything no longer available.
    $restored = array_values(array_filter(
        array_map('intval', (array) old('room_ids', [])),
        fn ($id) => isset($roomIndex[$id]),
    ));
@endphp

@section('content')
    {{-- Page-level selection state, shared by the room board (main column) and
         the approve form (sidebar). --}}
    <div x-data="{
            rooms: @js((object) $roomIndex),
            selected: @js($restored),
            needed: {{ (int) $request->rooms_needed }},
            members: {{ (int) $request->total_members }},
            isSelected(id) { return this.selected.includes(id); },
            toggle(id) {
                this.selected = this.isSelected(id)
                    ? this.selected.filter(x => x !== id)
                    : [...this.selected, id];
            },
            clear() { this.selected = []; },
            get beds() { return this.selected.reduce((n, id) => n + (this.rooms[id]?.capacity ?? 0), 0); },
         }">
        @include('shared._review-detail', [
            'request' => $request,
            'history' => $history,
            'actionsPartial' => 'manager.requests._actions',
            'boardPartial' => $board ? 'manager.requests._room-board' : null,
            'board' => $board,
        ])
    </div>
@endsection
