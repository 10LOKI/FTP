@extends('public-layout')

@section('title', 'Aperçu de facturation')

@section('content')
<section aria-labelledby="billing-title">
    <h1 id="billing-title">Préparer une facture</h1>
    <p>Sélectionnez les quantités livrées d’un même client. L’aperçu utilise les prix historiques MAD TTC et ne crée aucune facture.</p>
    <div id="invoice-preview" data-can-issue="{{ auth()->user()->can('INVOICE_ISSUE') ? '1' : '0' }}" data-base-url="{{ url('/api/v1/billing') }}"><p>Chargement…</p></div>
</section>
@vite('resources/js/invoice-preview.jsx')
@endsection
