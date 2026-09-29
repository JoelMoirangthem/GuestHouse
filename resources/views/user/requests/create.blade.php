@extends('layouts.app')

@section('title', 'New Request')
@section('heading', 'New Booking Request')

@section('content')
    <div class="mb-6 max-w-2xl">
        <p class="text-sm leading-relaxed text-[--color-ink-muted]">
            Tell us when you need accommodation and why. Your Manager reviews the request.
            The Administration allots rooms once it is approved.
        </p>
    </div>

    @include('user.requests._form', [
        'action' => route('my.requests.store'),
        'method' => 'POST',
        'submitLabel' => 'Submit Request',
        'request' => null,
    ])
@endsection
