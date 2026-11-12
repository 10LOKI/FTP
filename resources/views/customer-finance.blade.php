@extends('public-layout')

@section('title', 'Soldes et règlements')

@section('content')
<section aria-labelledby="finance-title">
    <h1 id="finance-title">Soldes et règlements</h1>
    <p>Montants en MAD TTC. La dette, les chèques en attente, le crédit disponible et les remboursements sont présentés séparément.</p>
    <div id="customer-finance" data-own="{{ $own ? '1' : '0' }}" data-base="{{ url('/api/v1') }}"><p>Chargement…</p></div>
</section>
@vite('resources/js/customer-finance.jsx')
@endsection
