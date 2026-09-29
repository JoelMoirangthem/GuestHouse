@extends('layouts.app')

@php $editing = $room->exists; @endphp

@section('title', $editing ? 'Edit room' : 'Add room')
@section('heading', $editing ? 'Edit room ' . $room->room_number : 'Add room')

@section('content')
    @include('admin._errors')

    <form method="POST" action="{{ $editing ? route('admin.rooms.update', $room) : route('admin.rooms.store') }}"
          class="gh-card space-y-5 p-6" novalidate>
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="room_number" class="gh-label gh-required">Room number</label>
                <input id="room_number" name="room_number" type="text" required maxlength="20"
                       value="{{ old('room_number', $room->room_number) }}" class="gh-input"
                       @error('room_number') aria-invalid="true" @enderror>
                @error('room_number') <p class="gh-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="room_type_id" class="gh-label gh-required">Room type</label>
                <select id="room_type_id" name="room_type_id" required class="gh-select"
                        @error('room_type_id') aria-invalid="true" @enderror>
                    <option value="">Choose a type</option>
                    @foreach ($types as $t)
                        <option value="{{ $t->id }}" @selected((int) old('room_type_id', $room->room_type_id) === $t->id)>
                            {{ $t->displayName() }} — sleeps {{ $t->default_capacity }}
                        </option>
                    @endforeach
                </select>
                @error('room_type_id') <p class="gh-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="block" class="gh-label">Building block</label>
                <input id="block" name="block" type="text" maxlength="30" value="{{ old('block', $room->block) }}" class="gh-input">
            </div>
            <div>
                <label for="floor" class="gh-label">Floor</label>
                <input id="floor" name="floor" type="number" min="-2" max="50" value="{{ old('floor', $room->floor) }}" class="gh-input"
                       @error('floor') aria-invalid="true" @enderror>
                @error('floor') <p class="gh-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="capacity" class="gh-label">Capacity override</label>
                <input id="capacity" name="capacity" type="number" min="1" max="10"
                       value="{{ old('capacity', $room->capacity) }}" class="gh-input" aria-describedby="cap-help"
                       @error('capacity') aria-invalid="true" @enderror>
                <p id="cap-help" class="gh-help">Leave blank to use the room type's default.</p>
                @error('capacity') <p class="gh-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label for="notes" class="gh-label">Notes</label>
            <textarea id="notes" name="notes" rows="3" maxlength="1000" class="gh-textarea">{{ old('notes', $room->notes) }}</textarea>
        </div>

        @if ($editing)
            <p class="gh-help">Blocking and unblocking are done from the room list, so each block records a reason.</p>
        @endif

        <div class="gh-hairline-t flex justify-end gap-2 pt-5">
            <a href="{{ route('admin.rooms.index') }}" class="gh-btn gh-btn-secondary">Cancel</a>
            <button type="submit" class="gh-btn gh-btn-primary">{{ $editing ? 'Save changes' : 'Add room' }}</button>
        </div>
    </form>
@endsection
