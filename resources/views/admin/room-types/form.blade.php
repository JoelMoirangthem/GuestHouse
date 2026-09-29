@extends('layouts.app')

@php $editing = $type->exists; @endphp

@section('title', $editing ? 'Edit room type' : 'Add room type')
@section('heading', $editing ? 'Edit room type — ' . $type->name : 'Add room type')

@section('content')
    @include('admin._errors')

    <form method="POST" action="{{ $editing ? route('admin.room-types.update', $type) : route('admin.room-types.store') }}"
          class="gh-card space-y-5 p-6" novalidate>
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="code" class="gh-label gh-required">Code</label>
                <input id="code" name="code" type="text" required maxlength="30" value="{{ old('code', $type->code) }}"
                       class="gh-input font-mono uppercase" aria-describedby="code-help" @error('code') aria-invalid="true" @enderror>
                <p id="code-help" class="gh-help">Letters, digits and underscores, e.g. DELUXE_AC.</p>
                @error('code') <p class="gh-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="name" class="gh-label gh-required">Name</label>
                <input id="name" name="name" type="text" required maxlength="80" value="{{ old('name', $type->name) }}"
                       class="gh-input" @error('name') aria-invalid="true" @enderror>
                @error('name') <p class="gh-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="category" class="gh-label gh-required">Category</label>
                <select id="category" name="category" required class="gh-select">
                    <option value="NORMAL" @selected(old('category', $type->category) === 'NORMAL')>Normal</option>
                    <option value="VIP" @selected(old('category', $type->category) === 'VIP')>VIP</option>
                </select>
            </div>
            <div>
                <label for="default_capacity" class="gh-label gh-required">Default capacity</label>
                <input id="default_capacity" name="default_capacity" type="number" min="1" max="10" required
                       value="{{ old('default_capacity', $type->default_capacity) }}" class="gh-input"
                       @error('default_capacity') aria-invalid="true" @enderror>
                @error('default_capacity') <p class="gh-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="sort_order" class="gh-label">Display order</label>
                <input id="sort_order" name="sort_order" type="number" min="0" max="255"
                       value="{{ old('sort_order', $type->sort_order ?? 0) }}" class="gh-input">
            </div>
            <div>
                <label for="description" class="gh-label">Description</label>
                <input id="description" name="description" type="text" maxlength="255"
                       value="{{ old('description', $type->description) }}" class="gh-input">
            </div>
        </div>

        <div class="flex flex-wrap gap-6">
            <input type="hidden" name="has_ac" value="0">
            <label class="flex items-center gap-2 text-sm text-[--color-ink-soft]">
                <input type="checkbox" name="has_ac" value="1" @checked((bool) old('has_ac', $type->has_ac))
                       class="h-4 w-4 rounded border-[--color-line-strong] text-navy-700 focus:ring-navy-500">
                Air-conditioned
            </label>
            <input type="hidden" name="is_active" value="0">
            <label class="flex items-center gap-2 text-sm text-[--color-ink-soft]">
                <input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $type->is_active))
                       class="h-4 w-4 rounded border-[--color-line-strong] text-navy-700 focus:ring-navy-500">
                Active (rooms of this type can be allotted)
            </label>
        </div>

        <div class="gh-hairline-t flex justify-end gap-2 pt-5">
            <a href="{{ route('admin.room-types.index') }}" class="gh-btn gh-btn-secondary">Cancel</a>
            <button type="submit" class="gh-btn gh-btn-primary">{{ $editing ? 'Save changes' : 'Add room type' }}</button>
        </div>
    </form>
@endsection
