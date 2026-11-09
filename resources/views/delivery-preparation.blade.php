@extends('public-layout')

@section('title', 'Préparation des livraisons')

@section('content')
<main class="public-shell">
    <h1>Préparation et départ des BL</h1>
    <p>Le scan contrôle le BL sans modifier le stock. Seule la validation séparée du départ réel consomme la réservation.</p>
    <div id="delivery-preparation" data-delivery="{{ json_encode([
        'baseUrl' => url('/api/v1/deliveries'),
        'permissions' => collect(['DELIVERY_TRANSFER', 'DELIVERY_DEPARTURE_CONFIRM'])->filter(fn ($code) => auth()->user()->can($code))->values(),
    ], JSON_THROW_ON_ERROR) }}"><p>Chargement des BL…</p></div>
</main>
@vite('resources/js/delivery-preparation.jsx')
@endsection
