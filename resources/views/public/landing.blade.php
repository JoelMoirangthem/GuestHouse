{{--
  Landing page — standalone (does not extend layouts.public, so no other page
  is affected by its styling).

  One-screen design: the page is exactly one dynamic viewport tall (100dvh)
  and every block is sized with clamp()/dvh so it fits from a 360×640 phone to
  a 1920×1080 desktop without scrolling in either direction. On very short
  screens (landscape phones) the main area scrolls internally instead of the
  page breaking.

  Layout:
    phones/tablets  — one centred column: brand → actions → contact
    lg and up       — brand on the left, actions + contact on the right
--}}
@php
    $logo = file_exists(public_path('images/nadt-logo.png')) ? asset('images/nadt-logo.png') : null;
    $phones = config('gh.reception_phones', []);
    $address = config('gh.building').', '.config('gh.institution').', '.config('gh.city').' – '.config('gh.pin');
    $fmt = fn (string $p) => strlen($p) === 10 ? substr($p, 0, 5).' '.substr($p, 5) : $p;
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Guest House booking portal of NADT, Regional Campus Lucknow (Pragya Bhawan).">
    <title>Welcome &middot; NADT, RC Lucknow &middot; Guest House Portal</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:ital,wght@0,400..700;1,400..700&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full overflow-hidden bg-[var(--color-canvas)] text-[var(--color-ink)] antialiased">

<div class="relative flex h-[100dvh] flex-col overflow-hidden">

    {{-- Decorative backdrop: soft navy/gold glows and a faint dot grid. --}}
    <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
        <div class="absolute -left-40 -top-40 h-[28rem] w-[28rem] rounded-full bg-navy-100/70 blur-3xl"></div>
        <div class="absolute -bottom-48 -right-32 h-[30rem] w-[30rem] rounded-full bg-gold-100/80 blur-3xl"></div>
        <div class="gh-canvas-grid absolute inset-0 opacity-60"></div>
    </div>

    {{-- ============ TOP BAR ============ --}}
    <header class="relative shrink-0 bg-navy-900 text-white">
        <div class="mx-auto flex h-12 max-w-6xl items-center justify-between gap-3 px-4 sm:h-14 sm:px-6">
            <div class="flex min-w-0 items-center gap-2.5">
                <svg class="h-6 w-6 shrink-0 text-gold-300" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 2 1.5 8.5l1 1.7L4 9.3V21h16V9.3l1.5.9 1-1.7L12 2Zm-5 17v-4h3v4H7Zm0-6V10h3v3H7Zm7 6v-4h3v4h-3Zm0-6V10h3v3h-3Z"/>
                </svg>
                <span class="truncate text-sm font-semibold uppercase tracking-[0.14em] sm:text-[0.9375rem]">Guest House Portal</span>
            </div>
            <span class="hidden shrink-0 text-xs text-navy-200 sm:block">
                {{ config('gh.building') }} &middot; {{ config('gh.city') }}
            </span>
        </div>
        {{-- Tricolour hairline --}}
        <div class="flex h-[3px]" aria-hidden="true">
            <span class="flex-1 bg-[#ff9933]"></span><span class="flex-1 bg-white"></span><span class="flex-1 bg-[#138808]"></span>
        </div>
    </header>

    {{-- ============ MAIN ============ --}}
    <main class="relative flex min-h-0 flex-1 flex-col overflow-y-auto overscroll-contain">
        {{-- my-auto (not content-center): when space is short this centres
             "safely" — content never gets pushed above the scroll origin. --}}
        <div class="mx-auto my-auto grid w-full max-w-6xl items-center gap-[clamp(0.75rem,2.2dvh,2.25rem)] px-4 py-[clamp(0.6rem,2dvh,2rem)] sm:px-6 lg:grid-cols-[1.05fr_1fr] lg:gap-12">

            {{-- ---------- Brand ---------- --}}
            <section class="text-center lg:text-left" aria-labelledby="welcome-title">
                <div class="inline-flex rounded-[1.75rem] bg-white/90 p-[clamp(0.5rem,1.4dvh,1rem)] shadow-[0_10px_30px_-12px_rgb(22_40_62/0.25)] ring-1 ring-[var(--color-line)]">
                    @if ($logo)
                        <img src="{{ $logo }}"
                             alt="Rashtriya Pratyaksh Kar Academy, Regional Campus Lucknow — Pragya Bhawan"
                             class="h-[clamp(4.25rem,12dvh,6.5rem)] w-auto object-contain sm:h-[clamp(5.5rem,16dvh,12rem)] lg:h-[clamp(7rem,24dvh,14rem)]"
                             width="366" height="346" decoding="async" fetchpriority="high">
                    @else
                        <span class="flex h-[clamp(5.25rem,17dvh,12rem)] w-[clamp(5.25rem,17dvh,12rem)] items-center justify-center text-navy-800" aria-hidden="true">
                            <svg class="h-1/2 w-1/2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><path d="M12 2.5 6 5.2v4.2c0 3.7 2.4 7.1 6 8.1 3.6-1 6-4.4 6-8.1V5.2L12 2.5Z" stroke-linejoin="round"/></svg>
                        </span>
                    @endif
                </div>

                <p class="mt-[clamp(0.6rem,1.8dvh,1.25rem)] text-[0.6875rem] font-semibold uppercase tracking-[0.22em] text-gold-700 sm:text-xs">
                    Pragya Bhawan &middot; Guest House
                </p>

                <h1 id="welcome-title"
                    class="mt-1 font-serif text-[clamp(1.6rem,3.2dvh+0.9vw,3.6rem)] leading-[1.05] tracking-tight text-navy-900 lg:text-[clamp(2.6rem,5dvh+1.2vw,4.5rem)]">
                    Welcome to <span class="whitespace-nowrap">NADT, RC</span>
                    <span class="italic text-navy-600">Lucknow</span>
                </h1>

                <div class="mx-auto mt-[clamp(0.5rem,1.4dvh,1rem)] flex w-32 items-center gap-2 lg:mx-0" aria-hidden="true">
                    <span class="h-px flex-1 bg-gold-400"></span>
                    <span class="h-1.5 w-1.5 rotate-45 bg-gold-500"></span>
                    <span class="h-px flex-1 bg-gold-400"></span>
                </div>

                <p class="mx-auto mt-[clamp(0.5rem,1.4dvh,1rem)] hidden max-w-md text-sm leading-relaxed text-[var(--color-ink-muted)] sm:block sm:text-[0.9375rem] lg:mx-0 lg:text-base [@media(max-height:700px)]:hidden">
                    National Academy of Direct Taxes, Regional Campus.
                    Book your stay and manage it with ease.
                </p>
            </section>

            {{-- ---------- Actions + contact ---------- --}}
            <div class="mx-auto flex w-full max-w-md flex-col gap-[clamp(0.6rem,1.6dvh,1rem)] lg:max-w-none">

                <nav class="grid gap-[clamp(0.5rem,1.3dvh,0.75rem)]" aria-label="Get started">
                    {{-- Requisition --}}
                    <a href="{{ route('public.booking') }}"
                       class="group flex items-center gap-4 rounded-2xl bg-[#0b5cc4] px-4 py-[clamp(0.7rem,1.9dvh,1.1rem)] text-white shadow-lg shadow-blue-900/20 transition
                              hover:-translate-y-0.5 hover:bg-[#0a52af] hover:shadow-xl focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-300 sm:px-5">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/15" aria-hidden="true">
                            <svg class="h-7 w-7" viewBox="0 0 48 48" fill="none">
                                <rect x="5" y="9" width="30" height="27" rx="3" stroke="currentColor" stroke-width="3"/>
                                <path d="M5 17h30" stroke="currentColor" stroke-width="3"/>
                                <path d="M13 5v8M27 5v8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                                <path d="M11 22h4v3h-4zM18 22h4v3h-4zM11 28h4v3h-4z" fill="currentColor"/>
                                <path d="M26 33 35.5 25 45 33" stroke="currentColor" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>
                                <path d="M29 31.5V43h13V31.5" fill="currentColor"/>
                            </svg>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[0.9375rem] font-bold leading-snug sm:text-base">Press here to submit your guest house requisition</span>
                            <span class="mt-0.5 block text-xs font-medium text-blue-100">No sign-in required</span>
                        </span>
                        <svg class="h-5 w-5 shrink-0 opacity-80 transition-transform group-hover:translate-x-0.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M7.3 14.7a1 1 0 0 1 0-1.4L10.6 10 7.3 6.7a1 1 0 0 1 1.4-1.4l4 4a1 1 0 0 1 0 1.4l-4 4a1 1 0 0 1-1.4 0Z" clip-rule="evenodd"/>
                        </svg>
                    </a>

                    {{-- Admin login --}}
                    <a href="{{ route('login') }}"
                       class="group flex items-center gap-4 rounded-2xl border border-slate-200 bg-white px-4 py-[clamp(0.7rem,1.9dvh,1.1rem)] text-slate-900 shadow-sm transition
                              hover:-translate-y-0.5 hover:border-navy-200 hover:shadow-md focus:outline-none focus-visible:ring-4 focus-visible:ring-slate-300 sm:px-5">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-800" aria-hidden="true">
                            <svg class="h-6 w-6" viewBox="0 0 48 48">
                                <path d="M11 20v-6a10 10 0 0 1 20 0v6" fill="none" stroke="currentColor" stroke-width="4"/>
                                <rect x="6" y="20" width="30" height="24" rx="4" fill="currentColor"/>
                                <circle cx="21" cy="31" r="3" fill="#f1f5f9"/>
                                <circle cx="37" cy="37" r="9" fill="#f1f5f9"/>
                                <circle cx="37" cy="37" r="7.5" fill="currentColor"/>
                                <circle cx="37" cy="34.5" r="2.5" fill="#f1f5f9"/>
                                <path d="M32.5 41.5a4.5 4.5 0 0 1 9 0z" fill="#f1f5f9"/>
                            </svg>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[0.9375rem] font-bold uppercase tracking-wide sm:text-base">Admin login</span>
                            <span class="mt-0.5 block text-xs font-medium text-slate-500">Manager, ADG &amp; Administration</span>
                        </span>
                        <svg class="h-5 w-5 shrink-0 text-slate-400 transition-transform group-hover:translate-x-0.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M7.3 14.7a1 1 0 0 1 0-1.4L10.6 10 7.3 6.7a1 1 0 0 1 1.4-1.4l4 4a1 1 0 0 1 0 1.4l-4 4a1 1 0 0 1-1.4 0Z" clip-rule="evenodd"/>
                        </svg>
                    </a>
                </nav>

                {{-- Contact: reception + location in one compact card --}}
                <section class="rounded-2xl border border-[var(--color-line)] bg-white/95 p-[clamp(0.75rem,1.8dvh,1.1rem)] shadow-sm" aria-label="Contact and location">
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4 shrink-0 text-navy-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2Z"/>
                        </svg>
                        <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-[var(--color-ink-soft)]">Reception Contact No.</h2>
                    </div>

                    <ul class="mt-2 grid grid-cols-3 gap-1.5 sm:gap-2">
                        @foreach ($phones as $phone)
                            <li>
                                <a href="tel:+91{{ $phone }}"
                                   class="block rounded-lg border border-[var(--color-line)] bg-[var(--color-surface-sunken)] px-1 py-1.5 text-center text-[0.78rem] font-semibold tabular-nums text-navy-900 transition-colors hover:border-navy-300 hover:bg-navy-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500 sm:text-sm"
                                   aria-label="Call reception on {{ $fmt($phone) }}">
                                    {{ $fmt($phone) }}
                                </a>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-[clamp(0.6rem,1.5dvh,0.9rem)] flex items-center gap-3 border-t border-[var(--color-line)] pt-[clamp(0.6rem,1.5dvh,0.9rem)]">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gold-50 text-gold-700" aria-hidden="true">
                            <svg class="h-[1.125rem] w-[1.125rem]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>
                            </svg>
                        </span>
                        <address class="min-w-0 flex-1 text-xs not-italic leading-snug text-[var(--color-ink-soft)] sm:text-[0.8125rem]">
                            <span class="sm:hidden">{{ config('gh.building') }}, NADT RC, Lucknow – {{ config('gh.pin') }}</span>
                            <span class="hidden sm:inline">{{ $address }}</span>
                        </address>
                        <a href="{{ config('gh.map_url') }}" target="_blank" rel="noopener noreferrer"
                           class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-navy-800 px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-navy-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-500 focus-visible:ring-offset-2 sm:text-[0.8125rem]">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 11 19-9-9 19-2-8-8-2Z"/></svg>
                            Get directions
                            <span class="sr-only">(opens Google Maps in a new tab)</span>
                        </a>
                    </div>
                </section>
            </div>
        </div>
    </main>

    {{-- ============ FOOTER ============ --}}
    <footer class="relative shrink-0 truncate border-t border-[var(--color-line)] bg-white/70 px-4 py-2 text-center text-[0.6875rem] text-[var(--color-ink-faint)] backdrop-blur" style="padding-bottom: max(0.5rem, env(safe-area-inset-bottom))">
        {{ config('gh.institution') }}<span class="hidden sm:inline"> &middot; For NADT employees and pensioners</span> &middot; All submissions are logged
    </footer>
</div>

</body>
</html>
