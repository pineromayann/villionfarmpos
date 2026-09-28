@extends('errors.layout')

@php
    $maintenanceMessage = '';

    try {
        if (app()->maintenanceMode()->active()) {
            $maintenanceMessage = trim((string) (app()->maintenanceMode()->data()['message'] ?? ''));
        }
    } catch (Throwable) {
        $maintenanceMessage = '';
    }
@endphp

@section('title', 'Service unavailable')
@section('code', '503')

@section('message')
    {{ $maintenanceMessage !== '' ? $maintenanceMessage : 'We will be right back' }}
@endsection

@section('hint')
    @if ($maintenanceMessage !== '')
        This page will return on its own once the work finishes. If it does not, a restore may have
        failed and the site needs bringing back with <code>php artisan up</code>.
    @else
        We are currently performing maintenance. Please check back shortly.
    @endif
@endsection
