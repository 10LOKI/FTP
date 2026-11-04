@extends('public-layout')
@section('title', 'Remises clients')
@push('assets') @vite('resources/js/customer-discounts.jsx') @endpush
@section('content')
    <h1>Remises clients</h1>
    <p>Le meilleur tarif entre promotion et remise client est retenu, sans cumul. Une remise de 0 % retire l’avantage client pour les prochains calculs.</p>
    <div id="customer-discounts" data-discounts="{{ json_encode(['listUrl' => route('discounts.index'), 'updateUrl' => route('discounts.update', '__CUSTOMER__')], JSON_THROW_ON_ERROR) }}"><p>Chargement des clients…</p></div>
    <noscript><p>Activez JavaScript pour gérer les remises.</p></noscript>
@endsection
