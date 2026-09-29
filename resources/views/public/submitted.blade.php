@extends('layouts.public')

@section('title', 'Requisition submitted')
@section('heading', 'Requisition Submitted')
@section('back', route('landing'))

@section('content')
    <div class="gh-card p-6 text-center" role="status">
        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-[--color-success-bg] text-green-700">
            <svg class="h-7 w-7" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/>
            </svg>
        </div>

        <h2 class="text-lg font-semibold text-[--color-ink]">Your requisition has been submitted</h2>

        <p class="mt-3 text-sm text-[--color-ink-muted]">Request number</p>
        <p class="mt-1 font-mono text-xl font-semibold text-navy-800">{{ $requestNo }}</p>

        <p class="mx-auto mt-4 max-w-sm text-sm leading-relaxed text-[--color-ink-soft]">
            Keep this number for reference. Your Manager reviews it.
            You will be notified by email at each step, and when a room is allotted.
        </p>

        <a href="{{ route('public.booking') }}" class="gh-btn gh-btn-secondary mt-6">Submit another requisition</a>
    </div>
@endsection
