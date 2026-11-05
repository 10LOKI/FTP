@extends('public-layout')
@section('title', 'Gestion des promotions')
@push('assets') @vite('resources/js/promotions-manage.jsx') @endpush
@section('content')
    <h1>Gestion des promotions</h1>
    <p>Les offres ne modifient pas le tarif de base. Une seule offre activée par produit peut couvrir un instant donné.</p>
    <div id="promotions-manage" data-promotions="{{ json_encode([
        'listUrl' => route('promotions.index'), 'productsUrl' => route('promotions.products'), 'settingsUrl' => route('promotions.settings'),
        'priceUrl' => route('product.price', '__PRODUCT__'), 'canEditPrice' => auth()->user()->can('PRODUCT_PRICE_UPDATE'),
        'canEditSettings' => auth()->user()->can('SETTINGS_MANAGE'),
    ], JSON_THROW_ON_ERROR) }}"><p>Chargement des promotions…</p></div>
    <noscript><p>Activez JavaScript pour gérer les promotions.</p></noscript>
@endsection
