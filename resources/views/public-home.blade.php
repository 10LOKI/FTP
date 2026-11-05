@extends('public-layout')
@section('title', 'Accueil')
@section('content')
    <h1>Pièces de rechange pour camions</h1>
    <p>Consultez les références de pièces et leurs compatibilités, ou contactez-nous pour préciser votre besoin.</p>
    @if (config('app.company.image_url'))
        <img src="{{ config('app.company.image_url') }}" alt="{{ config('app.company.name') }}">
    @endif
    @if (config('app.company.presentation'))
        <section aria-label="Présentation de l’entreprise"><p>{{ config('app.company.presentation') }}</p></section>
    @endif
    @if (config('app.company.services'))
        <section><h2>Nos services</h2><p>{{ config('app.company.services') }}</p></section>
    @endif
    <form action="{{ route('public.catalogue') }}" method="get">
        <label>Référence de la pièce <input name="reference" maxlength="255"></label>
        <button>Rechercher</button>
    </form>
    @if ($categories->isNotEmpty())
        <h2>Familles de pièces</h2>
        <nav aria-label="Familles de pièces">
            @foreach ($categories as $category)
                <a href="{{ route('public.catalogue', ['category' => $category]) }}">{{ $category }}</a>
            @endforeach
        </nav>
    @endif
    @if ($brands->isNotEmpty())
        <h2>Marques du catalogue</h2>
        <nav aria-label="Marques des pièces">
            @foreach ($brands as $brand)
                <a href="{{ route('public.catalogue', ['part_brand' => $brand]) }}">{{ $brand }}</a>
            @endforeach
        </nav>
    @endif
    <h2>Produits à découvrir</h2>
    @include('public-products')
    <h2>Comment commander</h2>
    <p>Demandez un compte, puis connectez-vous avec votre code client après approbation de votre demande.</p>
    <a href="{{ route('public.contact') }}">Contacter l’équipe pour votre besoin</a>
@endsection
