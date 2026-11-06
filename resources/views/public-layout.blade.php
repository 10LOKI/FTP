<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') | {{ config('app.company.name') }}</title>
    @vite('resources/js/public-account.jsx')
    @vite('resources/js/product-price.jsx')
    @stack('assets')
    <style>
        body { font: 1rem/1.6 system-ui, sans-serif; margin: 0; color: #142136; background: #f5f7fa; overflow-wrap: anywhere; }
        * { box-sizing: border-box; }
        main, nav { max-width: 70rem; margin: auto; padding: 1.25rem; }
        main { min-height: 65vh; }
        header { background: #12233c; color: white; }
        nav { display: flex; gap: 1rem; flex-wrap: wrap; }
        nav a { color: inherit; }
        a { color: #134da1; }
        :focus-visible { outline: 3px solid #b85a00; outline-offset: 3px; }
        form, article { background: white; padding: 1rem; border-radius: .6rem; min-width: 0; }
        form { display: grid; gap: .75rem; }
        article { overflow-wrap: anywhere; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr)); gap: 1rem; }
        label { display: grid; }
        input, textarea, select, button { font: inherit; padding: .65rem; max-width: 100%; min-width: 0; }
        input, textarea { width: 100%; border: 1px solid #69788c; border-radius: .25rem; }
        button { cursor: pointer; min-height: 2.75rem; }
        button:disabled { cursor: wait; }
        .error { color: #9c2020; margin-top: .25rem; }
        [aria-invalid="true"] { border-color: #9c2020; }
        .skip-link { position: absolute; top: -10rem; left: 1rem; background: white; padding: .75rem; z-index: 1; }
        .skip-link:focus { top: .5rem; }
        .pagination { justify-content: center; align-items: center; }
        .pagination a, header a { padding: .5rem; }
        footer { border-top: 1px solid #d4dce6; }
        header form { background: transparent; padding: 0; color: white; }
        img { max-width: 100%; height: auto; }
    </style>
</head>
<body>
<a class="skip-link" href="#main-content">Aller au contenu</a>
<header>
    <nav aria-label="Navigation principale">
        @if (config('app.company.logo_url'))
            <img src="{{ config('app.company.logo_url') }}" alt="{{ config('app.company.name') }}" width="120">
        @else
            <strong>{{ config('app.company.name') }}</strong>
        @endif
        <a href="{{ route('public.home') }}">Accueil</a>
        <a href="{{ route('public.catalogue') }}">Catalogue</a>
        @can('CART_MANAGE_OWN')
            <a href="{{ route('cart.page') }}">Mon panier</a>
            <a href="{{ route('customer.orders.page') }}">Mes commandes</a>
        @endcan
        <a href="{{ route('public.contact') }}">Contact</a>
        @can('catalogue-view-sale-price') <a href="{{ route('customer.promotions') }}">Promotions</a> @endcan
        @can('PROMOTION_MANAGE') <a href="{{ route('promotions.manage') }}">Gérer les promotions</a> @endcan
        @if (auth()->check() && auth()->user()->canAny(['AUDIT_READ', 'AUDIT_EXPORT']))
            <a href="{{ route('audit.page') }}">Journal et notifications</a>
        @endif
        @can('CUSTOMER_TERMS_MANAGE') <a href="{{ route('discounts.manage') }}">Remises clients</a> @endcan
        @guest
            <a href="{{ route('login') }}">Connexion</a>
            <a href="{{ route('register') }}">Demander un compte</a>
        @else
            <span>Connecté : {{ auth()->user()->name }}</span>
            <form id="public-logout" action="{{ url('/api/v1/logout') }}" method="post" data-home="{{ route('public.home') }}">
                @csrf
                <button>Se déconnecter</button>
                <span role="alert"></span>
            </form>
        @endguest
    </nav>
</header>
<main id="main-content" tabindex="-1">@yield('content')</main>
<footer>
    <nav aria-label="Coordonnées de l’entreprise">
        @foreach (['address', 'hours', 'email', 'phone'] as $field)
            @if (config('app.company.'.$field))
                <span>{{ config('app.company.'.$field) }}</span>
            @endif
        @endforeach
        <a href="{{ route('public.contact') }}">Contacter l’entreprise</a>
        @if (config('app.company.legal_url'))
            <a href="{{ config('app.company.legal_url') }}">Informations contractuelles</a>
        @endif
    </nav>
</footer>
</body>
</html>
