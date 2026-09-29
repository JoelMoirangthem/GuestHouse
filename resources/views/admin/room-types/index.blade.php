@extends('layouts.app')

@section('title', 'Room types')
@section('heading', 'Room types')

@section('content')
    @include('admin._errors')

    <div class="mb-5 flex justify-end gap-2">
        <a href="{{ route('admin.rooms.index') }}" class="gh-btn gh-btn-secondary">Rooms</a>
        <a href="{{ route('admin.room-types.create') }}" class="gh-btn gh-btn-primary">Add room type</a>
    </div>

    <div class="gh-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="gh-table">
                <thead>
                    <tr>
                        <th scope="col">Code</th>
                        <th scope="col">Name</th>
                        <th scope="col">Category</th>
                        <th scope="col" class="text-right">Default capacity</th>
                        <th scope="col">AC</th>
                        <th scope="col" class="text-right">Rooms</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($types as $t)
                        <tr>
                            <td class="font-mono text-xs text-[--color-ink-soft]">{{ $t->code }}</td>
                            <td class="font-medium text-[--color-ink]">{{ $t->name }}</td>
                            <td class="text-[--color-ink-soft]">{{ $t->isVip() ? 'VIP' : 'Normal' }}</td>
                            <td class="text-right text-[--color-ink-soft]">{{ $t->default_capacity }}</td>
                            <td class="text-[--color-ink-soft]">{{ $t->has_ac ? 'Yes' : 'No' }}</td>
                            <td class="text-right text-[--color-ink-soft]">{{ $t->rooms_count }}</td>
                            <td>
                                @if ($t->is_active)
                                    <span class="gh-status bg-[--color-success-bg] border-green-200 text-[--color-success]">Active</span>
                                @else
                                    <span class="gh-status bg-[--color-danger-bg] border-red-200 text-[--color-danger]">Retired</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <a href="{{ route('admin.room-types.edit', $t) }}" class="gh-btn gh-btn-ghost text-[0.8125rem]">
                                    Edit<span class="sr-only"> {{ $t->name }}</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-8 text-center text-[--color-ink-muted]">No room types yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="gh-hairline-t bg-[--color-surface-sunken] px-5 py-3">
            <p class="text-xs text-[--color-ink-muted]">A retired type's rooms no longer appear in availability searches. Existing allotments are unaffected.</p>
        </div>
    </div>
@endsection
