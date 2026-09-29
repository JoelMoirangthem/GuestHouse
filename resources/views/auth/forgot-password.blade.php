@extends('layouts.guest')

@section('title', 'Forgot password')

@section('content')
    <div class="gh-card p-6 sm:p-7">
        <h2 class="text-base font-semibold text-[--color-ink]">Reset your password</h2>
        <p class="mt-1 mb-5 text-sm leading-relaxed text-[--color-ink-muted]">
            Enter your official email address and we will send you a reset link.
        </p>

        @if (session('status'))
            <div class="gh-alert gh-alert-info mb-5" role="status">
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div class="gh-alert gh-alert-danger mb-5" role="alert">
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" novalidate>
            @csrf

            <div class="mb-5">
                <label for="email" class="gh-label gh-required">Email address</label>
                <input id="email" name="email" type="email" inputmode="email"
                       autocomplete="username" value="{{ old('email') }}" required autofocus
                       class="gh-input" placeholder="name@nadt.gov.in"
                       @if ($errors->has('email')) aria-invalid="true" @endif>
            </div>

            <button type="submit" class="gh-btn gh-btn-primary w-full">Send reset link</button>
        </form>

        <p class="mt-5 border-t border-[--color-line] pt-4 text-center text-xs text-[--color-ink-muted]">
            <a href="{{ route('login') }}" class="font-medium text-navy-700 underline decoration-navy-300 underline-offset-2 hover:text-navy-900">
                Back to sign in
            </a>
        </p>
    </div>
@endsection
