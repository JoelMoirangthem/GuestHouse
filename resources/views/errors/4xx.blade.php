@extends('errors.layout')

{{-- Any other 4xx (403, 405, 409, 422 ...). The message shown is only ever one
     the application itself passed to abort(), which is written for the user;
     framework-generated 4xx messages are replaced with a generic line. --}}
@php
    $status = isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 400;
    $own = isset($exception) && $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpException
        && ! $exception->getPrevious() && $exception->getMessage() !== '';
    $titles = [403 => 'You do not have access to this page', 405 => 'That action is not available here',
               409 => 'This cannot be done right now', 422 => 'The request could not be processed'];
@endphp

@section('code', (string) $status)
@section('title', $titles[$status] ?? 'The request could not be completed')
@section('message', $own ? $exception->getMessage() : 'If you think this is a mistake, contact the Guest House Administration.')
