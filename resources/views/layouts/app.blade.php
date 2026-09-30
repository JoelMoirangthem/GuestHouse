<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Home') &middot; {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:ital,wght@0,400..700;1,400..700&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full" x-data="{ mobileNav: false }">

    {{-- Skip link: the first tab stop for keyboard users, letting them jump
         past the navigation on every page (WCAG 2.4.1). --}}
    <a href="#main"
       class="sr-only-focusable absolute left-4 top-4 z-50 rounded-lg bg-navy-900 px-4 py-2 text-sm font-medium text-white shadow-[--shadow-float]">
        Skip to main content
    </a>

    <div class="flex min-h-screen">

        {{-- ============ SIDEBAR ============ --}}
        <aside
            class="fixed inset-y-0 left-0 z-40 w-[15.5rem] shrink-0 border-r border-[--color-line] bg-white transition-transform duration-200 lg:static lg:translate-x-0"
            :class="mobileNav ? 'translate-x-0' : '-translate-x-full'"
            aria-label="Main navigation"
        >
            <div class="flex h-full flex-col">

                {{-- Wordmark --}}
                <div class="flex items-center gap-2.5 border-b border-[--color-line] px-4 py-3.5">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-navy-900">
                        <svg class="h-[1.05rem] w-[1.05rem] text-gold-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path d="M12 2.5 6 5.2v4.2c0 3.7 2.4 7.1 6 8.1 3.6-1 6-4.4 6-8.1V5.2L12 2.5Z" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="truncate font-serif text-[0.9375rem] leading-tight text-navy-900">Guest House</p>
                        <p class="truncate text-[0.6875rem] text-[--color-ink-muted]">{{ config('gh.institution') }}</p>
                    </div>
                </div>

                {{-- Navigation --}}
                <nav class="flex-1 overflow-y-auto px-3 py-4">
                    @php $role = auth()->user()->roleSlug()?->value; @endphp

                    <p class="gh-eyebrow mb-2 px-1">
                        {{ auth()->user()->roleSlug()?->shortLabel() }} workspace
                    </p>

                    <ul class="space-y-0.5">
                        @if ($role === 'admin')
                            <li>
                                <a href="{{ route('dashboard') }}"
                                   class="gh-nav-item @if (request()->routeIs('dashboard')) gh-nav-item-active @endif"
                                   @if (request()->routeIs('dashboard')) aria-current="page" @endif>
                                    <x-icon name="grid" />
                                    Dashboard
                                </a>
                            </li>
                        @endif

                        @if ($role === 'manager')
                            <li>
                                <a href="{{ route('manager.requests.index') }}"
                                   class="gh-nav-item @if (request()->routeIs('manager.*')) gh-nav-item-active @endif"
                                   @if (request()->routeIs('manager.*')) aria-current="page" @endif>
                                    <x-icon name="inbox" />
                                    Pending Review
                                </a>
                            </li>
                        @endif

                        {{-- Booking operations: the Manager runs these; the Admin can too. --}}
                        @if (in_array($role, ['manager', 'admin'], true))
                            <li>
                                <a href="{{ route('admin.allotments.index') }}"
                                   class="gh-nav-item @if (request()->routeIs('admin.allotments.*') || request()->routeIs('admin.availability.*')) gh-nav-item-active @endif"
                                   @if (request()->routeIs('admin.allotments.*')) aria-current="page" @endif>
                                    <x-icon name="key" />
                                    Allotment Queue
                                </a>
                            </li>
                            <li>
                                <a href="{{ route('admin.stays.index') }}"
                                   class="gh-nav-item @if (request()->routeIs('admin.stays.*')) gh-nav-item-active @endif"
                                   @if (request()->routeIs('admin.stays.*')) aria-current="page" @endif>
                                    <x-icon name="clock" />
                                    Front Desk
                                </a>
                            </li>
                        @endif

                        @if ($role === 'admin')
                            <li class="pt-4"><p class="gh-eyebrow mb-2 px-1">Administration</p></li>
                            <li>
                                <a href="{{ route('admin.users.index') }}"
                                   class="gh-nav-item @if (request()->routeIs('admin.users.*')) gh-nav-item-active @endif"
                                   @if (request()->routeIs('admin.users.*')) aria-current="page" @endif>
                                    <x-icon name="users" />
                                    Users
                                </a>
                            </li>
                            <li>
                                <a href="{{ route('admin.rooms.index') }}"
                                   class="gh-nav-item @if (request()->routeIs('admin.rooms.*') || request()->routeIs('admin.room-types.*')) gh-nav-item-active @endif"
                                   @if (request()->routeIs('admin.rooms.*') || request()->routeIs('admin.room-types.*')) aria-current="page" @endif>
                                    <x-icon name="building" />
                                    Rooms
                                </a>
                            </li>
                            <li>
                                <a href="{{ route('admin.tariffs.index') }}"
                                   class="gh-nav-item @if (request()->routeIs('admin.tariffs.*')) gh-nav-item-active @endif"
                                   @if (request()->routeIs('admin.tariffs.*')) aria-current="page" @endif>
                                    <x-icon name="briefcase" />
                                    Tariffs
                                </a>
                            </li>
                            <li>
                                <a href="{{ route('admin.holidays.index') }}"
                                   class="gh-nav-item @if (request()->routeIs('admin.holidays.*')) gh-nav-item-active @endif"
                                   @if (request()->routeIs('admin.holidays.*')) aria-current="page" @endif>
                                    <x-icon name="clock" />
                                    Holidays
                                </a>
                            </li>
                            @foreach ([
                                ['admin.email-templates.index', 'admin.email-templates.*', 'inbox', 'Notification templates'],
                                ['admin.settings.edit', 'admin.settings.*', 'lock', 'Settings'],
                                ['admin.audit-logs.index', 'admin.audit-logs.*', 'document', 'Audit log'],
                            ] as [$r, $pattern, $icon, $text])
                                <li>
                                    <a href="{{ route($r) }}"
                                       class="gh-nav-item @if (request()->routeIs($pattern)) gh-nav-item-active @endif"
                                       @if (request()->routeIs($pattern)) aria-current="page" @endif>
                                        <x-icon :name="$icon" />
                                        {{ $text }}
                                    </a>
                                </li>
                            @endforeach
                        @endif

                        @if ($role === 'adg')
                            <li>
                                <a href="{{ route('adg.requests.index') }}"
                                   class="gh-nav-item @if (request()->routeIs('adg.*')) gh-nav-item-active @endif"
                                   @if (request()->routeIs('adg.*')) aria-current="page" @endif>
                                    <x-icon name="check-badge" />
                                    Pending Approval
                                </a>
                            </li>
                        @endif

                        <li>
                            <a href="{{ route('my.requests.index') }}"
                               class="gh-nav-item @if (request()->routeIs('my.*')) gh-nav-item-active @endif"
                               @if (request()->routeIs('my.*')) aria-current="page" @endif>
                                <x-icon name="document" />
                                My Requests
                            </a>
                        </li>
                    </ul>
                </nav>

                {{-- Account --}}
                <div class="border-t border-[--color-line] p-3">
                    <div class="mb-2 flex items-center gap-2.5 px-1">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-navy-100 text-[0.6875rem] font-semibold text-navy-800">
                            {{ auth()->user()->initials() }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[0.8125rem] font-medium text-[--color-ink]">{{ auth()->user()->name }}</p>
                            <p class="truncate text-[0.6875rem] text-[--color-ink-muted]">{{ auth()->user()->designation ?? auth()->user()->email }}</p>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="gh-btn gh-btn-ghost w-full justify-start text-[0.8125rem]">
                            <x-icon name="logout" />
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        {{-- Mobile scrim --}}
        <div x-show="mobileNav" x-cloak
             @click="mobileNav = false"
             class="fixed inset-0 z-30 bg-navy-950/20 backdrop-blur-[2px] lg:hidden"
             aria-hidden="true"></div>

        {{-- ============ MAIN ============ --}}
        <div class="flex min-w-0 flex-1 flex-col">

            <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-[--color-line] bg-[--color-canvas]/85 px-4 backdrop-blur-md sm:px-6">
                <button type="button" @click="mobileNav = true"
                        class="gh-btn gh-btn-ghost -ml-2 px-2 lg:hidden"
                        aria-label="Open navigation">
                    <x-icon name="menu" />
                </button>

                <div class="min-w-0 flex-1">
                    <h1 class="truncate text-[0.9375rem] font-semibold text-[--color-ink]">@yield('heading', 'Home')</h1>
                </div>

                {{-- Live notification bell. Updates without a page refresh.
                     Hidden while notifications are switched off. --}}
                @if (config('gh.notifications_enabled'))
                    <x-notification-bell />
                @endif

                <span class="hidden shrink-0 rounded-full border border-[--color-line] bg-white px-2.5 py-1 text-[0.6875rem] font-medium text-[--color-ink-muted] sm:inline-flex">
                    {{ auth()->user()->roleSlug()?->label() }}
                </span>
            </header>

            <main id="main" class="flex-1 px-4 py-6 sm:px-6 sm:py-8">
                <div class="mx-auto max-w-6xl">
                    @if (session('success'))
                        <div class="gh-alert gh-alert-success mb-5" role="status">{{ session('success') }}</div>
                    @endif
                    @if (session('error'))
                        <div class="gh-alert gh-alert-danger mb-5" role="alert">{{ session('error') }}</div>
                    @endif

                    @yield('content')
                </div>
            </main>

            <footer class="gh-hairline-t px-4 py-4 sm:px-6">
                <p class="mx-auto max-w-6xl text-xs text-[--color-ink-faint]">
                    {{ config('gh.building') }}, {{ config('gh.institution') }} &middot;
                    {{ config('gh.city') }} &ndash; {{ config('gh.pin') }}
                </p>
            </footer>
        </div>
    </div>

</body>
</html>
