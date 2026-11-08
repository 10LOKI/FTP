@extends('public-layout')
@section('title', 'Stocks et dépôts')
@push('assets') @vite('resources/js/warehouse-stock.jsx') @endpush
@section('content')
    <h1>Stocks et dépôts</h1>
    <p>Quantités en unités. Le disponible correspond au physique vendable moins le réservé. Les articles en contrôle ne sont pas disponibles à la vente.</p>
    <div id="warehouse-stock" data-stock="{{ json_encode([
        'baseUrl' => url('/api/v1/stock'),
        'permissions' => collect(['STOCK_RECEIVE', 'STOCK_ADJUST', 'STOCK_TRANSFER', 'STOCK_STRUCTURE_MANAGE'])->filter(fn ($code) => auth()->user()->can($code))->values(),
    ], JSON_THROW_ON_ERROR) }}"><p>Chargement des stocks…</p></div>
    <noscript><p>Activez JavaScript pour gérer les stocks.</p></noscript>
@endsection
