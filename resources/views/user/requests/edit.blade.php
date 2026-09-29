@extends('layouts.app')

@section('title', 'Edit Request')
@section('heading', 'Edit Request ' . $request->request_no)

@section('content')
    @if ($request->status === App\Domain\Enums\RequestStatus::MORE_INFO_MANAGER)
        <div class="gh-alert gh-alert-warn mb-6" role="alert">
            <x-icon name="inbox" class="mt-0.5 h-4 w-4 shrink-0" />
            <div>
                <p class="font-semibold">The Manager has asked for more information</p>
                @if ($request->more_info_note)
                    <p class="mt-1">{{ $request->more_info_note }}</p>
                @endif
                <p class="mt-1.5 text-xs">
                    Update the details below, then use "Save and Resend" to return it for review.
                </p>
            </div>
        </div>
    @endif

    @include('user.requests._form', [
        'action' => route('my.requests.update', $request),
        'method' => 'PUT',
        'submitLabel' => 'Save Changes',
        'request' => $request,
    ])
@endsection
