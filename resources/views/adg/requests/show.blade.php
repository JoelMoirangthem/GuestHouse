@extends('layouts.app')

@section('title', 'Approve ' . $request->request_no)
@section('heading', 'ADG Approval')

@section('content')
    @include('shared._review-detail', [
        'request' => $request,
        'history' => $history,
        'actionsPartial' => 'adg.requests._actions',
    ])
@endsection
