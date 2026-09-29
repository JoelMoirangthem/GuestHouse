@extends('layouts.guest')

@section('title', 'Set a new password')

@section('content')
    <div class="gh-card p-6 sm:p-7">
        <h2 class="text-base font-semibold text-[--color-ink]">Set a new password</h2>
        <p class="mt-1 mb-5 text-sm text-[--color-ink-muted]">
            Choose a password you have not used on this system before.
        </p>

        @if ($errors->any())
            <div class="gh-alert gh-alert-danger mb-5" role="alert">
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        <form method="POST" action="{{ route('password.update') }}" novalidate>
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div class="mb-4">
                <label for="email" class="gh-label gh-required">Email address</label>
                <input id="email" name="email" type="email" autocomplete="username"
                       value="{{ old('email', $email) }}" required class="gh-input">
            </div>

            <div class="mb-4">
                <label for="password" class="gh-label gh-required">New password</label>
                <input id="password" name="password" type="password" autocomplete="new-password"
                       required class="gh-input" aria-describedby="pwd-rules">
                <p id="pwd-rules" class="gh-help">
                    At least 10 characters, with upper and lower case, a number and a symbol.
                </p>
            </div>

            <div class="mb-5">
                <label for="password_confirmation" class="gh-label gh-required">Confirm new password</label>
                <input id="password_confirmation" name="password_confirmation" type="password"
                       autocomplete="new-password" required class="gh-input">
            </div>

            <button type="submit" class="gh-btn gh-btn-primary w-full">Update password</button>
        </form>
    </div>
@endsection
