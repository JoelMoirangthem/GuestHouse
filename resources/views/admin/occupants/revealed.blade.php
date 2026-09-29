@extends('layouts.app')

@section('title', 'Identity number')
@section('heading', 'Identity number — ' . $request->request_no)

@section('content')
    <div class="gh-card mx-auto max-w-lg p-6">
        <p class="gh-eyebrow">{{ $occupant->name }} &middot; {{ $occupant->id_proof_type }}</p>
        <p class="mt-3 select-all font-mono text-2xl tracking-wider text-[--color-ink]">{{ $number }}</p>
        <div class="gh-alert gh-alert-warn mt-5" role="note">
            <span>This view has been recorded in the audit log with your reason. Do not copy the number into email, chat or any other system.</span>
        </div>
        <a href="{{ route('admin.allotments.show', $request) }}" class="gh-btn gh-btn-secondary mt-5 w-full">Back to the request</a>
    </div>
@endsection
