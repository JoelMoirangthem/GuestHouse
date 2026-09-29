@extends('layouts.app')

@section('title', 'Edit template')
@section('heading', 'Template — ' . ($event?->bellTitle() ?? $template->event_key))

@section('content')
    @include('admin._errors')

    <div class="grid gap-6 lg:grid-cols-2">
        <form method="POST" action="{{ route('admin.email-templates.update', $template) }}" class="gh-card space-y-5 p-6" novalidate>
            @csrf
            @method('PUT')
            <p class="font-mono text-xs text-[--color-ink-muted]">{{ $template->event_key }}</p>

            <div>
                <label for="subject" class="gh-label gh-required">Subject</label>
                <input id="subject" name="subject" type="text" maxlength="200" required value="{{ old('subject', $template->subject) }}"
                       class="gh-input" @error('subject') aria-invalid="true" @enderror>
                @error('subject') <p class="gh-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="body_html" class="gh-label gh-required">Email body (HTML)</label>
                <textarea id="body_html" name="body_html" rows="12" required class="gh-textarea font-mono text-xs"
                          @error('body_html') aria-invalid="true" @enderror>{{ old('body_html', $template->body_html) }}</textarea>
                @error('body_html') <p class="gh-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="body_text" class="gh-label">Email body (plain text)</label>
                <textarea id="body_text" name="body_text" rows="6" class="gh-textarea font-mono text-xs"
                          @error('body_text') aria-invalid="true" @enderror>{{ old('body_text', $template->body_text) }}</textarea>
                @error('body_text') <p class="gh-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="sms_text" class="gh-label">SMS text</label>
                <input id="sms_text" name="sms_text" type="text" maxlength="320" value="{{ old('sms_text', $template->sms_text) }}"
                       class="gh-input" aria-describedby="sms-help" @error('sms_text') aria-invalid="true" @enderror>
                <p id="sms-help" class="gh-help">Up to 320 characters. Only used for events that send SMS.</p>
                @error('sms_text') <p class="gh-error">{{ $message }}</p> @enderror
            </div>

            <input type="hidden" name="is_active" value="0">
            <label class="flex items-center gap-2 text-sm text-[--color-ink-soft]">
                <input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $template->is_active))
                       class="h-4 w-4 rounded border-[--color-line-strong] text-navy-700 focus:ring-navy-500">
                Use this template (otherwise the built-in wording is sent)
            </label>

            <div>
                <p class="gh-label">Placeholders you can use</p>
                <p class="font-mono text-xs leading-relaxed text-[--color-ink-soft]">
                    @foreach ($placeholders as $p){{ '{' . '{' . $p . '}' . '}' }} @endforeach
                </p>
                <p class="gh-help">Identity numbers and document links can never be included: mail and SMS leave the institute's network.</p>
            </div>

            <div class="gh-hairline-t flex justify-end gap-2 pt-5">
                <a href="{{ route('admin.email-templates.index') }}" class="gh-btn gh-btn-secondary">Back</a>
                <button type="submit" class="gh-btn gh-btn-primary">Save template</button>
            </div>
        </form>

        <div class="space-y-4">
            <div class="gh-card p-5">
                <h2 class="gh-eyebrow mb-2">Preview (saved version, sample data)</h2>
                <p class="mb-3 text-sm font-semibold text-[--color-ink]">{{ $preview['subject'] }}</p>
                {{-- Rendered in a sandboxed frame with no permissions: even if a
                     template carried markup, nothing in it can run or navigate. --}}
                <iframe title="Email preview" sandbox srcdoc="{{ $preview['html'] }}"
                        class="h-96 w-full rounded-lg border border-[--color-line] bg-white"></iframe>
            </div>
            @if ($preview['sms'])
                <div class="gh-card p-5">
                    <h2 class="gh-eyebrow mb-2">SMS preview</h2>
                    <p class="text-sm text-[--color-ink-soft]">{{ $preview['sms'] }}</p>
                    <p class="gh-help">{{ mb_strlen($preview['sms']) }} characters with sample data.</p>
                </div>
            @endif
        </div>
    </div>
@endsection
