@extends('public-layout')
@section('title', 'Mes commandes')
@push('assets')
    @vite('resources/js/customer-orders.jsx')
@endpush
@section('content')
    <h1>Mes commandes</h1>
    <div id="customer-orders" data-api="{{ route('customer.orders.index') }}">
        <p role="status">Chargement des demandes…</p>
    </div>
    <noscript>Activez JavaScript pour consulter vos commandes.</noscript>
@endsection
