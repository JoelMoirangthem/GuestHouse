{{-- Error summary for the admin master forms. Focused on render so keyboard and
     screen-reader users land on it (WCAG 3.3.1). --}}
@if ($errors->any())
    <div class="gh-alert gh-alert-danger mb-6" role="alert" tabindex="-1" x-data x-init="$el.focus()">
        <div class="min-w-0">
            <p class="font-semibold">
                {{ $errors->count() === 1 ? 'There is a problem' : 'There are ' . $errors->count() . ' problems' }}
            </p>
            <ul class="mt-1.5 list-inside list-disc space-y-0.5">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
