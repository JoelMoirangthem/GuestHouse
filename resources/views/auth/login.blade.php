@extends('layouts.guest')

@section('title', 'Sign in')

@section('content')
    <div class="gh-card p-6 sm:p-7">

        <h2 class="text-base font-semibold text-[--color-ink]">Sign in</h2>
        <p class="mt-1 mb-5 text-sm text-[--color-ink-muted]">
            Use your official email address to continue.
        </p>

        {{-- Throttle / generic failure messages. A single generic message is
             deliberate: revealing whether the email exists would let an attacker
             enumerate valid accounts (SECURITY.md section 3). --}}
        @if ($errors->any())
            <div class="gh-alert gh-alert-danger mb-5" role="alert">
                <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9 9a1 1 0 012 0v4a1 1 0 11-2 0V9zm1-4a1 1 0 100 2 1 1 0 000-2z" clip-rule="evenodd"/>
                </svg>
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        @if (session('status'))
            <div class="gh-alert gh-alert-success mb-5" role="status">
                <span>{{ session('status') }}</span>
            </div>
        @endif

        <form method="POST" action="{{ route('login.attempt') }}" novalidate>
            @csrf

            <div class="mb-4">
                <label for="email" class="gh-label gh-required">Email address</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    inputmode="email"
                    autocomplete="username"
                    value="{{ old('email') }}"
                    required
                    autofocus
                    class="gh-input"
                    placeholder="name@nadt.gov.in"
                    @if ($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif
                >
                @error('email')
                    <p id="email-error" class="gh-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4" x-data="{ show: false }">
                <div class="mb-1.5 flex items-baseline justify-between">
                    <label for="password" class="gh-label gh-required mb-0">Password</label>
                    <a href="{{ route('password.request') }}"
                       class="text-xs font-medium text-navy-700 underline decoration-navy-300 underline-offset-2 transition-colors hover:text-navy-900 hover:decoration-navy-600">
                        Forgot password?
                    </a>
                </div>

                <div class="relative">
                    <input
                        id="password"
                        name="password"
                        :type="show ? 'text' : 'password'"
                        autocomplete="current-password"
                        required
                        class="gh-input pr-11"
                        placeholder="Enter your password"
                        @if ($errors->has('password')) aria-invalid="true" @endif
                    >
                    <button
                        type="button"
                        @click="show = !show"
                        class="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-lg text-[--color-ink-faint] transition-colors hover:text-[--color-ink-soft]"
                        :aria-label="show ? 'Hide password' : 'Show password'"
                        :aria-pressed="show.toString()"
                    >
                        <svg x-show="!show" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/>
                            <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/>
                        </svg>
                        <svg x-show="show" x-cloak class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M3.707 2.293a1 1 0 00-1.414 1.414l14 14a1 1 0 001.414-1.414l-1.473-1.473A10.014 10.014 0 0019.542 10C18.268 5.943 14.478 3 10 3a9.958 9.958 0 00-4.512 1.074l-1.78-1.781zm4.261 4.26l1.514 1.515a2.003 2.003 0 012.45 2.45l1.514 1.514a4 4 0 00-5.478-5.478z" clip-rule="evenodd"/>
                            <path d="M12.454 16.697L9.75 13.992a4 4 0 01-3.742-3.741L2.335 6.578A9.98 9.98 0 00.458 10c1.274 4.057 5.065 7 9.542 7 .847 0 1.669-.105 2.454-.303z"/>
                        </svg>
                    </button>
                </div>

                @error('password')
                    <p class="gh-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <label class="mb-5 flex items-center gap-2 text-sm text-[--color-ink-soft]">
                <input type="checkbox" name="remember" value="1"
                       class="h-4 w-4 rounded border-[--color-line-strong] text-navy-700 focus:ring-navy-500">
                Keep me signed in
            </label>

            <button type="submit" class="gh-btn gh-btn-primary w-full">
                Sign in
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/>
                </svg>
            </button>
        </form>

        {{-- Registration is not self-service (SECURITY.md section 3, ROUTES.md).
             Saying so plainly prevents support requests asking where to sign up. --}}
        <p class="mt-5 border-t border-[--color-line] pt-4 text-center text-xs text-[--color-ink-muted]">
            Need an account? Contact the Guest House Administrator.
        </p>
    </div>

    <p class="mt-4 text-center text-sm">
        <a href="{{ route('landing') }}" class="font-medium text-navy-700 underline decoration-navy-300 underline-offset-2 hover:text-navy-900">
            &larr; Back to Guest House Portal
        </a>
    </p>
@endsection
