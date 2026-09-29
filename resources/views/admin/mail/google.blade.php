@extends('layouts.app')

@section('title', 'Outbound Mail')
@section('heading', 'Outbound Mail')

@section('content')
    <div class="max-w-2xl space-y-6">

        @error('google')
            <div class="gh-alert gh-alert-danger" role="alert"><span>{{ $message }}</span></div>
        @enderror

        {{-- Connection status --}}
        <div class="gh-card p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="gh-eyebrow">Gmail API</h2>
                    <p class="mt-2 flex items-center gap-2">
                        @if ($connected)
                            <span class="gh-status bg-[--color-success-bg] border-green-200 text-[--color-success]">Connected</span>
                        @else
                            <span class="gh-status bg-[--color-pending-bg] border-amber-200 text-[--color-pending]">Not connected</span>
                        @endif
                    </p>
                    @if ($connected && $sender)
                        <p class="mt-2 text-sm text-[--color-ink-soft]">
                            Sending as <span class="font-medium text-[--color-ink]">{{ $sender }}</span>
                        </p>
                    @endif
                </div>

                @if ($connected)
                    <form method="POST" action="{{ route('admin.mail.google.disconnect') }}"
                          x-data
                          @submit="if (! confirm('Disconnect Gmail? The system will stop sending email.')) $event.preventDefault()">
                        @csrf
                        <button type="submit" class="gh-btn gh-btn-reject">Disconnect</button>
                    </form>
                @else
                    <a href="{{ route('oauth.google.redirect') }}" class="gh-btn gh-btn-primary">
                        Connect Gmail
                    </a>
                @endif
            </div>

            @unless ($connected)
                <div class="gh-alert gh-alert-info mt-5" role="note">
                    <div>
                        <p class="font-semibold">You will be asked to sign in to Google</p>
                        <p class="mt-1">
                            Google issues the long-lived credential only to a human at a consent
                            screen, so this step cannot be automated. It is needed once.
                        </p>
                    </div>
                </div>
            @endunless
        </div>

        {{-- Configuration, for diagnosing a mismatch --}}
        <div class="gh-card p-5 sm:p-6">
            <h2 class="gh-eyebrow mb-4">Configuration</h2>
            <dl class="space-y-3 text-sm">
                <div class="flex flex-wrap justify-between gap-2">
                    <dt class="text-[--color-ink-muted]">Active mailer</dt>
                    <dd class="font-mono text-[--color-ink]">{{ $mailer }}</dd>
                </div>
                <div class="flex flex-wrap justify-between gap-2">
                    <dt class="text-[--color-ink-muted]">Client ID</dt>
                    <dd class="max-w-[22rem] truncate font-mono text-xs text-[--color-ink-soft]" title="{{ $clientId }}">
                        {{ $clientId ?: 'not set' }}
                    </dd>
                </div>
                <div class="flex flex-wrap justify-between gap-2">
                    <dt class="text-[--color-ink-muted]">Redirect URI</dt>
                    <dd class="font-mono text-xs text-[--color-ink-soft]">{{ $redirectUri }}</dd>
                </div>
                <div class="flex flex-wrap justify-between gap-2">
                    <dt class="text-[--color-ink-muted]">From address</dt>
                    <dd class="font-mono text-xs text-[--color-ink-soft]">{{ config('mail.from.address') }}</dd>
                </div>
            </dl>

            <p class="gh-help mt-4">
                The redirect URI above must be registered on the OAuth client in Google Cloud
                Console, character for character.
            </p>

            {{-- Honest note: consumer Gmail is not a bulk sender. --}}
            <div class="gh-alert gh-alert-warn mt-5" role="note">
                <div>
                    <p class="font-semibold">A note on using Gmail for official mail</p>
                    <p class="mt-1">
                        A consumer Gmail account is capped at roughly 500 recipients a day, and
                        messages will be sent from that address rather than from an
                        <span class="font-mono text-xs">@nadt.gov.in</span> domain. For
                        production, a departmental SMTP relay is the better choice.
                    </p>
                </div>
            </div>
        </div>

        {{-- Test send: the only claim worth making is a delivered message --}}
        @if ($connected)
            <div class="gh-card p-5 sm:p-6">
                <h2 class="gh-eyebrow mb-1">Send a test message</h2>
                <p class="mb-4 text-sm text-[--color-ink-muted]">
                    Confirms the whole path: token refresh, Gmail API call, delivery.
                </p>

                <form method="POST" action="{{ route('admin.mail.google.test') }}">
                    @csrf
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="min-w-[16rem] flex-1">
                            <label for="to" class="gh-label gh-required">Send to</label>
                            <input id="to" name="to" type="email" required class="gh-input"
                                   value="{{ old('to', $sender) }}" placeholder="name@example.com">
                        </div>
                        <button type="submit" class="gh-btn gh-btn-primary">Send test</button>
                    </div>
                    @error('to')<p class="gh-error" role="alert">{{ $message }}</p>@enderror
                </form>
            </div>
        @endif
    </div>
@endsection
