@extends('public-layout')
@section('title', 'Mon panier')
@push('assets')
    @vite('resources/js/customer-cart.jsx')
@endpush
@section('content')
    <h1>Mon panier</h1>
    <p>Les articles du panier ne réservent pas de stock. Les prix clients sont recalculés à la soumission ; les frais et conditions commerciales seront confirmés avec l’équipe.</p>
    <div id="customer-cart" data-api="{{ route('cart.show') }}" data-catalogue="{{ route('public.catalogue') }}"
         data-orders="{{ route('customer.orders.store') }}" data-history="{{ route('customer.orders.page') }}">
        <p role="status">Chargement du panier…</p>
    </div>
    <noscript>Activez JavaScript pour gérer votre panier.</noscript>
@endsection
