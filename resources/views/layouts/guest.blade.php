<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Sign in') &middot; {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:ital,wght@0,400..700;1,400..700&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full gh-canvas-grid">

    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-10">

        {{-- Institutional identity. The emblem and full unit name matter here:
             users must be able to tell at a glance that this is the official
             system and not a lookalike. --}}
        <header class="mb-7 flex flex-col items-center text-center">
            <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-full border border-[--color-line-strong] bg-white shadow-[--shadow-card]">
                <svg class="h-8 w-8 text-navy-800" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true">
                    <path d="M12 2.5 6 5.2v4.2c0 3.7 2.4 7.1 6 8.1 3.6-1 6-4.4 6-8.1V5.2L12 2.5Z" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M9.4 11.2l1.8 1.8 3.4-3.4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>

            <p class="gh-eyebrow mb-1.5">{{ config('gh.institution') }}</p>

            <h1 class="font-serif text-[1.75rem] leading-tight text-navy-900">
                Guest House Booking System
            </h1>

            <p class="mt-1.5 text-sm text-[--color-ink-muted]">
                {{ config('gh.building') }} &middot; {{ config('gh.city') }}
            </p>
        </header>

        <main class="w-full max-w-[26rem]">
            @yield('content')
        </main>

        <footer class="mt-8 max-w-[26rem] text-center">
            <p class="text-xs leading-relaxed text-[--color-ink-faint]">
                Authorised users only. All activity on this system is logged.
                Accounts are provisioned by the Guest House Administrator.
            </p>
        </footer>
    </div>

</body>
</html>
