@extends('public-layout')
@section('title', ($promotionsOnly ?? false) ? 'Promotions' : 'Catalogue')
@push('assets')
    @vite('resources/js/public-catalogue.jsx')
@endpush
@section('content')
    <h1>{{ ($promotionsOnly ?? false) ? 'Promotions en cours' : 'Catalogue public' }}</h1>
    @if ($errors->any())
        <p role="alert">{{ $errors->first() }}</p>
    @endif
    @php
        $catalogue = [
            'initialProducts' => $products->toArray() + (auth()->user()?->can('catalogue-view-sale-price') ? ['refresh_after_ms' => app(\App\Services\CustomerPricing::class)->refreshAfterMs()] : []),
            'initialFilters' => collect(['reference', 'category', 'part_brand', 'vehicle_make', 'vehicle_model'])
                ->mapWithKeys(fn ($field) => [$field => is_string(request($field)) ? request($field) : ''])->all(),
            'initialErrors' => (object) $errors->messages(),
            'apiUrl' => route(($promotionsOnly ?? false) ? 'customer.promotions.index' : 'public.products.index'),
            'catalogueUrl' => route(($promotionsOnly ?? false) ? 'customer.promotions' : 'public.catalogue'),
            'productUrl' => route('public.product', '__PRODUCT__'),
            'contactUrl' => route('public.contact'),
        ];
    @endphp
    <div id="catalogue-app" data-catalogue="{{ json_encode($catalogue, JSON_THROW_ON_ERROR) }}">
        <form class="grid" method="get" action="{{ route(($promotionsOnly ?? false) ? 'customer.promotions' : 'public.catalogue') }}">
            @foreach (['reference' => 'Référence', 'category' => 'Catégorie', 'part_brand' => 'Marque de la pièce', 'vehicle_make' => 'Constructeur du camion', 'vehicle_model' => 'Modèle du camion'] as $field => $label)
                <label for="filter-{{ $field }}">{{ $label }}
                    <input id="filter-{{ $field }}" name="{{ $field }}" value="{{ is_string(request($field)) ? request($field) : '' }}" @error($field) aria-invalid="true" aria-describedby="error-{{ $field }}" @enderror>
                    @error($field) <span class="error" id="error-{{ $field }}">{{ $message }}</span> @enderror
                </label>
            @endforeach
            <button>Appliquer les filtres</button>
        </form>
        <p>{{ $products->total() }} résultat(s)</p>
        @include('public-products')
        <nav class="pagination" aria-label="Pagination">
            @if ($products->previousPageUrl()) <a href="{{ $products->previousPageUrl() }}">Précédent</a> @endif
            <span>Page {{ $products->currentPage() }} sur {{ $products->lastPage() }}</span>
            @if ($products->nextPageUrl()) <a href="{{ $products->nextPageUrl() }}">Suivant</a> @endif
        </nav>
    </div>
@endsection
