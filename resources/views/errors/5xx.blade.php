@extends('errors.layout')

{{-- Never the exception message: for a 5xx it can carry SQL, file paths or data. --}}
@section('code', isset($exception) && method_exists($exception, 'getStatusCode') ? (string) $exception->getStatusCode() : '500')
@section('title', 'Something went wrong on our side')
@section('message', 'The problem has been recorded. Please try again in a few minutes; if it persists, contact the Guest House Administration.')
