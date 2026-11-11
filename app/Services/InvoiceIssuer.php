<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class InvoiceIssuer
{
    public function __construct(private readonly InvoiceIssuanceContext $context, private readonly HistoricalInvoicePdf $pdf) {}

    public function authorize(User $actor): void
    {
        app(InvoicePreview::class)->authorize($actor);
        Gate::forUser($actor)->authorize('INVOICE_ISSUE');
    }

    /** @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    public function readiness(array $preview): array
    {
        try {
            $context = $this->context->resolve($preview);

            return ['ready' => true, 'token' => hash('sha256', json_encode([$context, $preview], JSON_THROW_ON_ERROR)), 'reason' => null];
        } catch (HttpExceptionInterface $exception) {
            return ['ready' => false, 'token' => null, 'reason' => $exception->getMessage()];
        }
    }

    /** @param array<string, mixed> $input
     * @return array{invoice: object, replayed: bool}
     */
    public function issue(User $actor, array $input): array
    {
        $this->authorize($actor);
        $input['request_key'] = Str::lower($input['request_key']);
        $input['lines'] = collect($input['lines'])->map(fn (array $line): array => [
            'delivery_line_id' => Str::lower($line['delivery_line_id']), 'quantity' => (int) $line['quantity'], 'availability_token' => $line['availability_token'],
        ])->sortBy('delivery_line_id')->values()->all();
        $hash = hash('sha256', json_encode(['customer_id' => (int) $input['customer_id'], 'lines' => $input['lines'], 'issuance_token' => $input['issuance_token']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $input, $hash): array {
            $existing = DB::table('customer_invoices')->where('request_key', $input['request_key'])->first();
            if ($existing !== null) {
                return $this->replay($actor, $existing, $hash);
            }
            abort_unless(config('billing.issuance_enabled') === true, 503, 'L’émission est désactivée en attente des règles approuvées.');
            $key = config('billing.sequence_key');
            abort_unless(is_string($key) && $key !== '' && strlen($key) <= 80, 503, 'La séquence approuvée est indisponible.');
            DB::table('invoice_sequences')->insertOrIgnore(['id' => $key, 'next_number' => 1]);
            $sequence = DB::table('invoice_sequences')->where('id', $key)->lockForUpdate()->firstOrFail();
            $existing = DB::table('customer_invoices')->where('request_key', $input['request_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                return $this->replay($actor, $existing, $hash);
            }
            $preview = app(InvoicePreview::class)->preview($actor, $input);
            abort_unless($preview['issuance']['ready'] && hash_equals($preview['issuance']['token'], $input['issuance_token']), 409, 'L’aperçu ou les conditions ont changé. Recalculez avant émission.');
            $context = $this->context->resolve($preview);
            abort_if(bccomp($preview['total'], '9999999999999999999999.99', 2) > 0, 422, 'Le total dépasse la capacité documentaire.');
            $number = $context['number_prefix'].str_pad((string) $sequence->next_number, 6, '0', STR_PAD_LEFT);
            abort_if((int) $sequence->next_number >= PHP_INT_MAX, 409, 'Séquence épuisée.');
            $id = (string) Str::uuid();
            $issuedAt = now('UTC')->format('Y-m-d H:i:s');
            $snapshot = ['schema_version' => 1, 'number' => $number, 'issued_at' => $issuedAt,
                'policy_version' => $context['policy_version'], 'seller' => $context['seller'], 'customer' => $context['customer'],
                'payment_terms' => $context['payment_terms'], 'tax_note' => $context['tax_note'],
                'currency' => 'MAD', 'tax_basis' => 'TTC', 'total' => $preview['total'],
                'lines' => collect($preview['lines'])->map(fn (array $line): array => collect($line)->only([
                    'id', 'delivery_id', 'delivery_number', 'order_id', 'order_number', 'order_line_id',
                    'sku', 'designation', 'original_reference', 'units_per_package', 'quantity_unit', 'quantity', 'unit_price', 'total',
                ])->all())->all()];
            $document = $this->pdf->render($snapshot);
            DB::table('customer_invoices')->insert(['id' => $id, 'number' => $number, 'customer_id' => (int) $input['customer_id'], 'issued_by' => $actor->id,
                'request_key' => $input['request_key'], 'request_hash' => $hash, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'total' => $preview['total'], 'document_base64' => base64_encode($document), 'document_sha256' => hash('sha256', $document), 'issued_at' => $issuedAt]);
            foreach ($preview['lines'] as $line) {
                DB::table('billing_quantity_allocations')->insert(['id' => (string) Str::uuid(), 'delivery_line_id' => $line['id'], 'kind' => 'invoice',
                    'source_id' => $id, 'quantity' => $line['quantity'], 'created_at' => $issuedAt]);
            }
            DB::table('invoice_sequences')->where('id', $key)->increment('next_number');
            app(AuditWriter::class)->record(['actor_id' => $actor->id, 'request_id' => app(AuditContext::class)->requestId ?? (string) Str::uuid(),
                'action' => 'invoice.issued', 'object_type' => 'customer_invoice', 'object_id' => $id, 'result' => 'SUCCESS',
                'changes' => ['count' => count($snapshot['lines']), 'currency' => 'MAD', 'tax_basis' => 'TTC'], 'occurred_at' => $issuedAt]);

            return ['invoice' => DB::table('customer_invoices')->where('id', $id)->firstOrFail(), 'replayed' => false];
        }, 3);
    }

    /** @return array{invoice: object, replayed: bool} */
    private function replay(User $actor, object $invoice, string $hash): array
    {
        abort_unless((int) $invoice->issued_by === (int) $actor->id && hash_equals($invoice->request_hash, $hash), 409, 'Cette clé a déjà servi pour une autre émission.');

        return ['invoice' => $invoice, 'replayed' => true];
    }

    public function readable(User $actor, string $id): object
    {
        $query = DB::table('customer_invoices')->where('id', Str::lower($id));
        if (CustomerProfile::where('user_id', $actor->id)->exists()) {
            $query->where('customer_id', $actor->id);
        } else {
            Gate::forUser($actor)->authorize('INVOICE_READ');
        }

        return $query->firstOrFail();
    }

    /** @return array<string, mixed> */
    public function present(object $invoice): array
    {
        return ['id' => $invoice->id, 'number' => $invoice->number, 'issued_at' => $invoice->issued_at,
            'document_status' => 'issued', 'payment_status' => 'unavailable',
            'snapshot' => json_decode($invoice->snapshot, true, flags: JSON_THROW_ON_ERROR),
            'document_url' => route('invoices.document', $invoice->id)];
    }

    public function document(object $invoice): string
    {
        $bytes = base64_decode($invoice->document_base64, true);
        abort_unless(is_string($bytes) && hash_equals($invoice->document_sha256, hash('sha256', $bytes)), 500, 'L’intégrité du document historique est invalide.');

        return $bytes;
    }
}
