@extends('layouts.app')

@section('title', 'Settings')
@section('heading', 'Settings')

@section('content')
    @include('admin._errors')

    <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-6" novalidate>
        @csrf
        @method('PUT')

        @foreach ($groups as $group => $defs)
            <fieldset class="gh-card p-6">
                <legend class="gh-eyebrow mb-4 px-1">{{ $group }}</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($defs as $key => [$path, $type, $g, $label, $rules, $help])
                        @if ($type === 'BOOL')
                            <div class="sm:col-span-2">
                                <input type="hidden" name="{{ $key }}" value="0">
                                <label class="flex items-center gap-2 text-sm text-[--color-ink-soft]">
                                    <input type="checkbox" name="{{ $key }}" value="1" @checked((bool) old($key, $values[$key]))
                                           class="h-4 w-4 rounded border-[--color-line-strong] text-navy-700 focus:ring-navy-500"
                                           @if ($help) aria-describedby="{{ $key }}-help" @endif>
                                    {{ $label }}
                                </label>
                                @if ($help)<p id="{{ $key }}-help" class="gh-help">{{ $help }}</p>@endif
                            </div>
                        @else
                            <div>
                                <label for="{{ $key }}" class="gh-label gh-required">{{ $label }}</label>
                                <input id="{{ $key }}" name="{{ $key }}" type="{{ $type === 'INT' ? 'number' : 'text' }}"
                                       value="{{ old($key, $values[$key]) }}" class="gh-input"
                                       @if ($help) aria-describedby="{{ $key }}-help" @endif
                                       @error($key) aria-invalid="true" @enderror>
                                @if ($help)<p id="{{ $key }}-help" class="gh-help">{{ $help }}</p>@endif
                                @error($key) <p class="gh-error">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    @endforeach
                </div>
            </fieldset>
        @endforeach

        <div class="flex justify-end">
            <button type="submit" class="gh-btn gh-btn-primary">Save settings</button>
        </div>
    </form>
@endsection
