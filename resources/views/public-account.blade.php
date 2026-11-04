@extends('public-layout')
@section('title', $mode === 'login' ? 'Connexion' : 'Demander un compte')
@section('content')
    <h1>{{ $mode === 'login' ? 'Connexion client' : 'Demander un compte client' }}</h1>
    @guest
        @php
            $account = [
                'mode' => $mode,
                'endpoint' => url($mode === 'login' ? '/api/v1/login' : '/api/v1/customer-registrations'),
                'catalogueUrl' => route('public.catalogue'),
                'loginUrl' => route('login'),
                'registerUrl' => route('register'),
            ];
        @endphp
        <div id="public-account" data-account="{{ json_encode($account, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) }}">
            <p>Chargement du formulaire…</p>
        </div>
        <noscript><p>Activez JavaScript pour utiliser le formulaire, ou <a href="{{ route('public.contact') }}">contactez l’équipe</a>.</p></noscript>
    @else
        <p>Vous êtes connecté. <a href="{{ route('public.catalogue') }}">Consulter le catalogue</a>.</p>
    @endguest
@endsection
