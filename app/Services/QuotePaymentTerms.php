<?php

namespace App\Services;

use App\Models\CustomerProfile;
use Illuminate\Support\Facades\DB;

class QuotePaymentTerms
{
    /** @return array{payment_terms: ?array<string, mixed>, payment_terms_status: string} */
    public function capture(int $customerId, ?string $dueDate): array
    {
        abort_unless($dueDate === null || $dueDate >= now('UTC')->toDateString(), 422, 'L’échéance proposée ne peut être passée (UTC).');
        if (config('receivables.enabled') !== true) {
            return ['payment_terms' => null, 'payment_terms_status' => 'policy_disabled'];
        }
        $terms = $this->current($customerId);
        if ($terms === null || ! in_array($terms->mode, CustomerFinance::MODES, true) || trim($terms->label) === '') {
            return ['payment_terms' => null, 'payment_terms_status' => 'terms_required'];
        }

        return ['payment_terms' => ['source' => 'customer_payment_terms', 'source_id' => $terms->id,
            'version' => (int) $terms->version, 'mode' => $terms->mode, 'label' => $terms->label, 'due_date' => $dueDate],
            'payment_terms_status' => $dueDate === null ? 'due_date_required' : 'ready'];
    }

    /** @param array<string, mixed> $snapshot */
    public function assertCurrent(int $customerId, array $snapshot, bool $lockAccount = true): void
    {
        abort_unless(config('receivables.enabled') === true, 409, 'La politique des conditions de paiement est désactivée.');
        abort_unless(CustomerProfile::where('user_id', $customerId)->where('approval', 'APPROUVE')->exists(), 409, 'Le compte client doit rester approuvé avant émission.');
        $terms = $this->current($customerId, $lockAccount);
        $captured = $snapshot['payment_terms'] ?? [];
        abort_unless($terms !== null && ($captured['source'] ?? null) === 'customer_payment_terms'
            && ($captured['source_id'] ?? null) === $terms->id && ($captured['version'] ?? null) === (int) $terms->version
            && ($captured['mode'] ?? null) === $terms->mode && ($captured['label'] ?? null) === $terms->label,
            409, 'Les conditions du compte ont changé. Préparez une nouvelle version du devis.');
        abort_unless(is_string($captured['due_date'] ?? null) && $captured['due_date'] >= now('UTC')->toDateString(), 409, 'L’échéance proposée doit être actualisée.');
    }

    private function current(int $customerId, bool $lockAccount = true): ?object
    {
        if ($lockAccount) {
            app(CustomerFinance::class)->lock($customerId);
        }

        return DB::table('customer_payment_terms')->where('customer_id', $customerId)->orderByDesc('version')->first();
    }
}
