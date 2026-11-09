@extends('public-layout')
@section('title','Retours fournisseurs')
@push('assets') @vite('resources/js/supplier-returns.jsx') @endpush
@section('content')
<h1>Retours et avoirs fournisseurs</h1><p>La préparation ne sort pas le stock. Le solde change uniquement après acceptation de l’avoir.</p><div id="supplier-returns" data-base-url="{{ url('/api/v1/supplier-returns') }}"><p>Chargement…</p></div>
@endsection
