@extends('layouts.app')

@section('title', 'Feedback — ' . $request->request_no)
@section('heading', 'Feedback on your stay')

@section('content')
    @include('admin._errors')

    <form method="POST" action="{{ route('my.requests.feedback.store', $request) }}" class="gh-card mx-auto max-w-2xl space-y-6 p-6" novalidate>
        @csrf

        <div>
            <p class="gh-eyebrow">Request {{ $request->request_no }}</p>
            <p class="mt-1 text-sm text-[--color-ink-soft]">
                {{ $request->check_in_date->format('d/m/Y') }} &rarr; {{ $request->check_out_date->format('d/m/Y') }}.
                Rate each aspect from 1 (poor) to 5 (excellent). Feedback can be given once and cannot be edited.
            </p>
        </div>

        @foreach ($aspects as $field => $label)
            {{-- A radio group in a fieldset, so the question is announced with each
                 option and the whole scale is reachable with the arrow keys. --}}
            <fieldset @error($field) aria-describedby="{{ $field }}-error" @enderror>
                <legend class="gh-label gh-required">{{ $label }}</legend>
                <div class="mt-1 flex flex-wrap gap-2">
                    @for ($n = 1; $n <= 5; $n++)
                        <label class="flex cursor-pointer items-center gap-1.5 rounded-lg border border-[--color-line-strong] bg-white px-3 py-2 text-sm text-[--color-ink] has-[:checked]:border-navy-700 has-[:checked]:bg-navy-50 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-navy-500">
                            <input type="radio" name="{{ $field }}" value="{{ $n }}" required
                                   @checked((int) old($field) === $n)
                                   class="h-4 w-4 text-navy-700 focus:ring-navy-500">
                            {{ $n }}
                            <span class="sr-only">out of 5</span>
                            @if ($n === 1)<span class="text-xs text-[--color-ink-muted]">Poor</span>@endif
                            @if ($n === 5)<span class="text-xs text-[--color-ink-muted]">Excellent</span>@endif
                        </label>
                    @endfor
                </div>
                @error($field) <p id="{{ $field }}-error" class="gh-error">{{ $message }}</p> @enderror
            </fieldset>
        @endforeach

        <div>
            <label for="comments" class="gh-label">Comments</label>
            <textarea id="comments" name="comments" rows="4" maxlength="2000" class="gh-textarea"
                      aria-describedby="comments-help">{{ old('comments') }}</textarea>
            <p id="comments-help" class="gh-help">Optional. Please do not include identity numbers or other personal details.</p>
        </div>

        <div class="gh-hairline-t flex justify-end gap-2 pt-5">
            <a href="{{ route('my.requests.show', $request) }}" class="gh-btn gh-btn-secondary">Back</a>
            <button type="submit" class="gh-btn gh-btn-primary">Submit feedback</button>
        </div>
    </form>
@endsection
