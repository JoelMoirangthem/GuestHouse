<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @guest<meta name="gh-csrf-refresh" content="1">@endguest
    <title>@yield('title', 'Guest House Portal') &middot; {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:ital,wght@0,400..700;1,400..700&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full flex-col bg-[var(--color-canvas)]">

    {{-- App bar. Inner pages get a back arrow to the landing page; the landing
         page shows the portal brand instead. --}}
    <header class="sticky top-0 z-20 bg-navy-800 text-white shadow-md">
        <div class="mx-auto flex h-14 max-w-3xl items-center justify-between gap-3 px-4 sm:h-16">
            <div class="flex min-w-0 items-center gap-3">
                @hasSection('back')
                    <a href="@yield('back')"
                       class="-ml-2 flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-white transition-colors hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                       aria-label="Back to home">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M17 10a1 1 0 0 1-1 1H6.4l4.3 4.3a1 1 0 0 1-1.4 1.4l-6-6a1 1 0 0 1 0-1.4l6-6a1 1 0 1 1 1.4 1.4L6.4 9H16a1 1 0 0 1 1 1Z" clip-rule="evenodd"/>
                        </svg>
                    </a>
                @else
                    <svg class="h-7 w-7 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 2 1.5 8.5l1 1.7L4 9.3V21h16V9.3l1.5.9 1-1.7L12 2Zm-5 17v-4h3v4H7Zm0-6V10h3v3H7Zm7 6v-4h3v4h-3Zm0-6V10h3v3h-3Z"/>
                    </svg>
                @endif

                <h1 class="truncate text-base font-semibold uppercase tracking-wide text-white sm:text-lg">
                    @yield('heading', 'Guest House Portal')
                </h1>
            </div>

            @hasSection('back')
                <a href="{{ route('login') }}"
                   class="shrink-0 rounded-md border border-white/30 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                    Admin login
                </a>
            @endif
        </div>
    </header>

    <main class="mx-auto w-full max-w-3xl flex-1 px-4 pb-10 pt-6 sm:pt-10">
        @yield('content')
    </main>

    <footer class="mx-auto w-full max-w-3xl px-4 @yield('footer_padding', 'pb-8') text-center">
        <p class="text-xs leading-relaxed text-[var(--color-ink-faint)]">
            {{ config('gh.building') }}, {{ config('gh.institution') }}, {{ config('gh.city') }}.
            All submissions are logged.
        </p>
    </footer>

    @yield('after')
</body>
</html>
