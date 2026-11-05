@if ($product->pricing)
    <div class="product-price" data-price="{{ json_encode(['initialPrice' => $product->pricing, 'endpoint' => route('customer.price', $product->id)], JSON_THROW_ON_ERROR) }}">
        <p>Prix de vente {{ $product->pricing['unit_price'] }} {{ $product->pricing['currency'] }} {{ $product->pricing['tax_basis'] ?? '(base fiscale à préciser)' }}</p>
        @if ($product->pricing['promotion']) <p>Offre : {{ $product->pricing['promotion']['title'] }}</p> @endif
    </div>
@else
    <p>Prix de vente {{ $product->sale_price }}</p>
@endif
