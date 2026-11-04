@extends('public-layout')
@section('title', $product->designation)
@push('assets')
    @can('CART_MANAGE_OWN')
        @vite('resources/js/customer-cart.jsx')
    @endcan
@endpush
@section('content')
    <h1>{{ $product->designation }}</h1>
    @can('REFERENCE_MANAGE')
        <a href="{{ route('product.references.edit', $product->id) }}">Gérer les références de ce produit</a>
    @endcan
    <div class="grid">
        @if ($product->images)
            <div>
                @foreach ($product->images as $image)
                    <img src="{{ $image }}" alt="{{ $product->designation }}">
                @endforeach
            </div>
        @endif
        <article>
            <p>Référence originale {{ $product->original_reference }}</p>
            <p>SKU {{ $product->sku }}</p>
            <p>{{ $product->part_brand }} {{ $product->category }}</p>
            @if ($product->hs_code !== null)
                <p>Code SH {{ $product->hs_code }}</p>
            @endif
            @if ($product->net_weight !== null)
                <p>Poids net {{ $product->net_weight }} {{ $product->weight_unit }}</p>
            @endif
            @if (array_key_exists('sale_price', $product->toArray()) && $product->sale_price !== null)
                @include('product-price', ['product' => $product])
            @endif
            @if ($product->cross_references->isNotEmpty())
                <h2>Références croisées vérifiées</h2>
                <ul>
                    @foreach ($product->cross_references as $reference)
                        <li>{{ $reference->brand }} · {{ $reference->reference }}</li>
                    @endforeach
                </ul>
            @endif
            @if ($product->compatibilities->isNotEmpty())
                <h2>Compatibilités camion</h2>
                <ul>
                    @foreach ($product->compatibilities as $vehicle)
                        <li>{{ $vehicle->vehicle_make }} {{ $vehicle->vehicle_model }}</li>
                    @endforeach
                </ul>
            @endif
            <p>Un compte approuvé est nécessaire pour commander.</p>
            @guest
                <a href="{{ route('login') }}">Se connecter pour acheter</a>
                <br>
                <a href="{{ route('register') }}">Demander un compte pour acheter</a>
            @else
                @can('catalogue-view-sale-price')
                    <p>Votre compte est approuvé.</p>
                    @can('CART_MANAGE_OWN')
                        <div id="cart-add" data-product="{{ $product->id }}" data-packagings="{{ $cartPackagings->push(1)->unique()->sort()->values()->toJson() }}"
                            data-api="{{ route('cart.show') }}" data-cart="{{ route('cart.page') }}">
                            <a href="{{ route('cart.page') }}">Ouvrir mon panier</a>
                        </div>
                    @endcan
                @else
                    <p>Votre compte ne dispose pas de l’accès commercial.</p>
                @endcan
                <a href="{{ route('public.contact') }}">Contacter l’équipe</a>
            @endguest
        </article>
    </div>
@endsection
