@extends('layouts.app')

@section('title', 'Notifications')
@section('heading', 'Notifications')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-[--color-ink-muted]">
            @if ($unread > 0)
                You have <span class="font-semibold text-[--color-ink]">{{ $unread }}</span> unread
                {{ Str::plural('notification', $unread) }}.
            @else
                Everything here has been read.
            @endif
        </p>

        @if ($unread > 0)
            <form method="POST" action="{{ route('notifications.readAll') }}">
                @csrf
                <button type="submit" class="gh-btn gh-btn-secondary">Mark all read</button>
            </form>
        @endif
    </div>

    @if ($notifications->isEmpty())
        <div class="gh-card p-10 text-center">
            <div class="mx-auto mb-4 flex h-11 w-11 items-center justify-center rounded-full border border-[--color-line] bg-[--color-surface-sunken] text-[--color-ink-muted]">
                <x-icon name="inbox" class="h-5 w-5" />
            </div>
            <h2 class="font-serif text-lg text-navy-900">No notifications yet</h2>
            <p class="mx-auto mt-1.5 max-w-sm text-sm text-[--color-ink-muted]">
                You will be told here when a request moves through the approval chain.
            </p>
        </div>
    @else
        <div class="gh-card divide-y divide-[--color-line] overflow-hidden">
            @foreach ($notifications as $n)
                <div class="flex gap-3 px-5 py-4 {{ $n->isRead() ? '' : 'bg-navy-50/50' }}">
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $n->isRead() ? 'bg-[--color-line-strong]' : 'bg-[--color-danger]' }}"
                          aria-hidden="true"></span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="text-sm font-semibold text-[--color-ink]">
                                {{ $n->title }}
                                @unless ($n->isRead())
                                    <span class="sr-only">(unread)</span>
                                @endunless
                            </p>
                            <time class="text-xs text-[--color-ink-faint]" datetime="{{ $n->created_at->toIso8601String() }}">
                                {{ $n->created_at->format('d/m/Y, g:i a') }}
                            </time>
                        </div>

                        <p class="mt-1 text-sm text-[--color-ink-soft]">{{ $n->body }}</p>

                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            @if ($n->action_url)
                                <a href="{{ $n->action_url }}"
                                   class="text-xs font-medium text-navy-700 underline decoration-navy-300 underline-offset-2 hover:text-navy-900">
                                    Open request
                                </a>
                            @endif

                            @unless ($n->isRead())
                                <form method="POST" action="{{ route('notifications.read', $n) }}">
                                    @csrf
                                    <button type="submit" class="text-xs font-medium text-[--color-ink-muted] hover:underline">
                                        Mark read
                                    </button>
                                </form>
                            @endunless
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($notifications->hasPages())
            <div class="mt-5">{{ $notifications->links() }}</div>
        @endif
    @endif
@endsection
