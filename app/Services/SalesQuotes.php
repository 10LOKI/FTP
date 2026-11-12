<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SalesQuotes
{
    public function __construct(private readonly QuoteIssuanceContext $context, private readonly QuotePdf $pdf) {}

    /** @return array{ready: bool, token: ?string, reason: ?string} */
    public function readiness(object $draft): array
    {
        try {
            $snapshot = json_decode($draft->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $context = $this->context->resolve($snapshot);

            return ['ready' => true, 'token' => hash('sha256', json_encode([$snapshot, $context], JSON_THROW_ON_ERROR)), 'reason' => null];
        } catch (HttpExceptionInterface $exception) {
            return ['ready' => false, 'token' => null, 'reason' => $exception->getMessage()];
        }
    }

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize('ORDER_REVIEW_MANAGE');
        abort_if(CustomerProfile::where('user_id', $actor->id)->exists(), 403);
    }

    private function lockOrder(User $actor, int $orderId): object
    {
        $order = DB::table('customer_orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
        $review = DB::table('sales_order_reviews')->where('order_id', $orderId)->first();
        abort_unless($review !== null && (int) $review->claimed_by === $actor->id, 403);

        return $order;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function issue(User $actor, int $orderId, array $input): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $orderId, $input): array {
            $order = $this->lockOrder($actor, $orderId);
            $hash = hash('sha256', json_encode([$input['draft_id'], (int) $input['expected_version'], $input['issuance_token']], JSON_THROW_ON_ERROR));
            $existing = DB::table('sales_quotes')->where('order_id', $orderId)->where('request_key', Str::lower($input['request_key']))->first();
            if ($existing !== null) {
                abort_unless((int) $existing->issued_by === $actor->id && hash_equals($existing->request_hash, $hash), 409);

                return ['quote_id' => $existing->id, 'version' => (int) $existing->order_version, 'replayed' => true];
            }
            abort_unless(in_array($order->status, ['EN_VERIFICATION', 'ATTENTE_ACCORD'], true) && (int) $order->version === (int) $input['expected_version'], 409, 'État ou version périmés.');
            $draft = DB::table('sales_quote_drafts')->where('order_id', $orderId)->where('id', Str::lower($input['draft_id']))->firstOrFail();
            abort_unless((int) $draft->order_version === (int) $order->version, 409, 'Préparez une nouvelle version du brouillon.');
            $snapshot = json_decode($draft->snapshot, true, flags: JSON_THROW_ON_ERROR);
            abort_unless($snapshot['valid_until'] >= now('UTC')->toDateString(), 409, 'Brouillon expiré.');
            $context = $this->context->resolve($snapshot);
            $ready = $this->readiness($draft);
            abort_unless($ready['ready'] && hash_equals($ready['token'], $input['issuance_token']), 409, 'Conditions documentaires modifiées.');
            $id = (string) Str::uuid();
            $number = $context['number_prefix'].$orderId.'-V'.$draft->revision;
            $at = now('UTC')->format('Y-m-d H:i:s');
            $snapshot = array_replace($snapshot, $context, ['number' => $number, 'issued_at' => $at, 'status' => 'ISSUED', 'revision' => (int) $draft->revision]);
            unset($snapshot['number_prefix']);
            $snapshot['customer']['name'] = $snapshot['customer']['company_name'] ?? $snapshot['contact']['name'] ?? '';
            $snapshot['customer']['address'] = implode(', ', array_filter($snapshot['billing_address'], fn ($value): bool => is_string($value) && $value !== ''));
            abort_unless($snapshot['customer']['name'] !== '' && $snapshot['customer']['address'] !== '', 409, 'Identité client documentaire incomplète.');
            $document = $this->pdf->render($snapshot);
            $version = (int) $order->version + 1;
            DB::table('sales_quotes')->insert(['id' => $id, 'draft_id' => $draft->id, 'order_id' => $orderId, 'issued_by' => $actor->id,
                'number' => $number, 'revision' => $draft->revision, 'order_version' => $version, 'request_key' => Str::lower($input['request_key']),
                'request_hash' => $hash, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'document_base64' => base64_encode($document),
                'document_sha256' => hash('sha256', $document), 'valid_until' => $snapshot['valid_until'], 'issued_at' => $at]);
            DB::table('customer_orders')->where('id', $orderId)->update(['status' => 'ATTENTE_ACCORD', 'version' => $version, 'updated_at' => now()]);
            $event = $this->audit($actor, $id, 'sales.quote_issued');
            app(QuoteNotificationSender::class)->enqueue($order, $id, $event);

            return ['quote_id' => $id, 'version' => $version, 'replayed' => false];
        }, 3);
    }

    public function readable(User $actor, string $id): object
    {
        $query = DB::table('sales_quotes')->where('id', Str::lower($id));
        if (CustomerProfile::where('user_id', $actor->id)->exists()) {
            $query->whereIn('order_id', DB::table('customer_orders')->where('user_id', $actor->id)->select('id'));
        } else {
            Gate::forUser($actor)->authorize('ORDER_REVIEW');
        }

        return $query->firstOrFail();
    }

    public function document(object $quote): string
    {
        $bytes = base64_decode($quote->document_base64, true);
        abort_unless(is_string($bytes) && hash_equals($quote->document_sha256, hash('sha256', $bytes)), 500, 'Intégrité du PDF invalide.');

        return $bytes;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function decide(User $actor, int $orderId, string $quoteId, array $input): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $orderId, $quoteId, $input): array {
            $order = $this->lockOrder($actor, $orderId);
            $quote = DB::table('sales_quotes')->where('id', Str::lower($quoteId))->where('order_id', $orderId)->firstOrFail();
            $hash = hash('sha256', json_encode([(int) $input['expected_version'], $input['outcome'], $input['contact_method'], $input['contact_name'], $input['contacted_at'], $input['note'] ?? null], JSON_THROW_ON_ERROR));
            $existing = DB::table('sales_quote_decisions')->where('quote_id', $quote->id)->first();
            if ($existing !== null) {
                abort_unless($existing->request_key === Str::lower($input['request_key']) && (int) $existing->actor_id === $actor->id && hash_equals($existing->request_hash, $hash), 409, 'Cette version possède déjà une décision.');

                return ['decision_id' => $existing->id, 'replayed' => true];
            }
            abort_unless($order->status === 'ATTENTE_ACCORD' && (int) $order->version === (int) $input['expected_version']
                && (int) $quote->order_version === (int) $order->version && $quote->valid_until >= now('UTC')->toDateString(), 409, 'Offre expirée ou modifiée : une nouvelle version est requise.');
            abort_unless($input['contacted_at'] >= $quote->issued_at, 422, 'Le contact doit suivre l’émission de cette version.');
            $id = (string) Str::uuid();
            DB::table('sales_quote_decisions')->insert(['id' => $id, 'quote_id' => $quote->id, 'actor_id' => $actor->id,
                'request_key' => Str::lower($input['request_key']), 'request_hash' => $hash, 'outcome' => $input['outcome'],
                'contact_method' => $input['contact_method'], 'contact_name' => $input['contact_name'], 'note' => $input['note'] ?? null,
                'contacted_at' => $input['contacted_at'], 'created_at' => now('UTC')]);
            DB::table('customer_orders')->where('id', $orderId)->update(['version' => (int) $order->version + 1,
                'status' => $input['outcome'] === 'REFUSED' ? 'EN_VERIFICATION' : 'ATTENTE_ACCORD', 'updated_at' => now()]);
            $this->audit($actor, $quote->id, 'sales.quote_'.strtolower($input['outcome']));

            return ['decision_id' => $id, 'replayed' => false];
        }, 3);
    }

    /**
     * Call inside the confirmation transaction so the order lock remains held during stock reservation.
     *
     * @return array<string, mixed>
     */
    public function accepted(User $actor, int $orderId): array
    {
        Gate::forUser($actor)->authorize('ORDER_CONFIRM');
        abort_if(CustomerProfile::where('user_id', $actor->id)->exists(), 403);
        abort_unless(DB::transactionLevel() > 0, 500, 'La lecture de l’accord doit appartenir à la transaction de confirmation.');

        return DB::transaction(function () use ($orderId): array {
            $order = DB::table('customer_orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            $quote = DB::table('sales_quotes')->where('order_id', $orderId)->orderByDesc('revision')->first();
            $decision = $quote === null ? null : DB::table('sales_quote_decisions')->where('quote_id', $quote->id)->first();
            abort_unless($quote !== null && $decision?->outcome === 'ACCEPTED' && $order->status === 'ATTENTE_ACCORD'
                && (int) $order->version === (int) $quote->order_version + 1 && $quote->valid_until >= now('UTC')->toDateString(), 409, 'Aucun accord courant et valide.');

            return ['quote_id' => $quote->id, 'quote_version' => (int) $quote->revision, 'order_version' => (int) $order->version,
                'document_sha256' => $quote->document_sha256, 'decision_id' => $decision->id,
                'snapshot' => json_decode($quote->snapshot, true, flags: JSON_THROW_ON_ERROR)];
        }, 3);
    }

    private function audit(User $actor, string $quoteId, string $action): AuditEvent
    {
        return AuditEvent::create(['actor_id' => $actor->id, 'request_id' => (string) Str::uuid(), 'action' => $action,
            'object_type' => 'sales_quote', 'object_id' => $quoteId, 'result' => 'SUCCESS', 'occurred_at' => now('UTC')]);
    }
}
