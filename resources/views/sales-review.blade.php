@extends('public-layout')
@section('title', 'Revue commerciale')
@push('assets')
    @vite('resources/js/sales-review.jsx')
@endpush
@section('content')
    <h1>Revue commerciale des commandes</h1>
    <p>Consultation uniquement : aucun stock ni aucune condition de paiement ne sont modifiés.</p>
    <div id="sales-review" data-api="{{ route('sales.review.index') }}"></div>
    <noscript>Activez JavaScript pour consulter la file commerciale.</noscript>
@endsection
