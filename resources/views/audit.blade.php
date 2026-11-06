@extends('public-layout')
@section('title', 'Journal et notifications')
@push('assets') @vite('resources/js/audit.jsx') @endpush
@section('content')
    <h1>Journal et notifications</h1>
    <p>Retrouvez les événements produits par le serveur et préparez votre export Excel mensuel.</p>
    <div id="audit-console" data-audit="{{ json_encode([
        'listUrl' => route('audit.index'), 'exportUrl' => route('audit.export'),
        'deliveriesUrl' => route('notification-deliveries.index'), 'retryUrl' => route('notification-deliveries.retry', ['kind' => '__KIND__', 'delivery' => '__DELIVERY__']),
        'canRead' => auth()->user()->can('AUDIT_READ'), 'canExport' => auth()->user()->can('AUDIT_EXPORT'),
        'canRetry' => auth()->user()->can('NOTIFICATION_RETRY'), 'canRetryAccess' => auth()->user()->can('USER_RESET_ACCESS'), 'canRetryOrders' => config('notifications.order_received_enabled'),
        'month' => now(config('audit.timezone'))->format('Y-m'), 'timezone' => config('audit.timezone'),
    ], JSON_THROW_ON_ERROR) }}"><p>Chargement du journal…</p></div>
    <noscript><p>Activez JavaScript pour consulter le journal. Les API restent protégées par vos habilitations.</p></noscript>
@endsection
