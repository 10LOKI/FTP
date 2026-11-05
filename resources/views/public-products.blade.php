<div class="grid">
    @forelse ($products as $product)
        <article>
            <h2><a href="{{ route('public.product', $product->id) }}">{{ $product->designation }}</a></h2>
            <p>Référence {{ $product->original_reference }}</p>
            @if ($product->matched_reference)
                <p>
                    Correspondance {{ $product->matched_reference['type'] === 'ORIGINALE' ? 'originale' : 'croisée' }} :
                    <strong>{{ $product->matched_reference['reference'] }}</strong>
                    @if ($product->matched_reference['brand']) · {{ $product->matched_reference['brand'] }} @endif
                </p>
            @endif
            <p>{{ $product->part_brand }} {{ $product->category }}</p>
            @if (array_key_exists('sale_price', $product->toArray()) && $product->sale_price !== null)
                @include('product-price', ['product' => $product])
            @endif
        </article>
    @empty
        <p>Aucun produit ne correspond à votre recherche. <a href="{{ route('public.contact') }}">Contactez l’équipe</a>.</p>
    @endforelse
</div>
