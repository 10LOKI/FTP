@extends('public-layout')
@section('title', 'Références de '.$product->sku)
@push('assets')
    @vite('resources/js/product-references.jsx')
@endpush
@section('content')
    <h1>Références de {{ $product->designation }}</h1>
    <p>SKU {{ $product->sku }}</p>
    <p>Une référence croisée doit être vérifiée avant de permettre une recherche publique.</p>
    <div id="product-references" data-references="{{ json_encode(['endpoints' => $endpoints], JSON_THROW_ON_ERROR) }}">
        <p>Chargement des références…</p>
    </div>
    <noscript><p>Activez JavaScript pour utiliser l’éditeur de références.</p></noscript>
@endsection
