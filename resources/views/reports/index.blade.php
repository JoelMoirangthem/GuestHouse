@extends('layouts.app')

@section('title', 'Reports')
@section('heading', 'Reports')

@section('content')
    @php
        $blurbs = [
            'occupancy' => 'Nights occupied against nights available, per room type.',
            'booking' => 'Every request with its decisions and rooms allotted.',
            'allotment' => 'One row per room: dates, actual check-in and out, amount.',
            'user-wise' => 'Requests, approvals and room-nights per applicant.',
            'purpose-wise' => 'Training, Self and Guest visits compared.',
            'revenue' => 'Realised and projected income, kept separate.',
            'feedback' => 'Average ratings, response rate and comments.',
        ];
    @endphp

    <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($types as $type => $title)
            <li>
                <a href="{{ route('reports.show', $type) }}" class="gh-card block h-full p-5 transition-colors hover:bg-[--color-surface-sunken]">
                    <h2 class="text-sm font-semibold text-[--color-ink]">{{ $title }}</h2>
                    <p class="mt-1 text-sm text-[--color-ink-muted]">{{ $blurbs[$type] ?? '' }}</p>
                </a>
            </li>
        @endforeach
    </ul>

    @unless (auth()->user()->hasPermission('report.view.all'))
        <p class="gh-help mt-5">Booking, allotment and user-wise reports show your reportees only.</p>
    @endunless
@endsection
