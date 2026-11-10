<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InvoiceIssuanceContext
{
    /** @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    public function resolve(array $preview): array
    {
        abort_unless(config('billing.issuance_enabled') === true, 503, 'L’émission est désactivée en attente des règles approuvées.');
        $policy = config('billing');
        foreach (['policy_version', 'number_prefix', 'sequence_key', 'tax_note'] as $field) {
            abort_unless(is_string($policy[$field] ?? null) && trim($policy[$field]) !== '', 503, 'La politique de facturation est incomplète.');
        }
        abort_unless(preg_match('/\A[A-Za-z0-9-]{1,60}\z/', $policy['number_prefix']) === 1
            && strlen($policy['sequence_key']) <= 80 && $policy['tax_presentation'] === 'ttc_only', 503, 'La numérotation ou la présentation fiscale doit être approuvée.');
        $seller = $this->identity($policy['seller']);
        $customer = null;
        $terms = null;
        foreach (collect($preview['lines'])->pluck('order_id')->unique()->sort()->values() as $orderId) {
            $order = DB::table('customer_orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            $commercial = json_decode($order->commercial_snapshot ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            $approved = $commercial['billing_v1'] ?? [];
            abort_unless(($approved['status'] ?? null) === 'confirmed' && ($approved['currency'] ?? null) === 'MAD'
                && ($approved['tax_basis'] ?? null) === 'TTC' && is_int($approved['confirmed_by'] ?? null)
                && is_string($approved['confirmed_at'] ?? null) && trim($approved['confirmed_at']) !== ''
                && ($approved['lines_sha256'] ?? null) === self::linesHash($order->lines), 409, 'Le contrat commercial confirmé de la commande est indisponible ou périmé.');
            abort_unless((int) ($approved['customer_id'] ?? 0) === (int) $preview['customer_id'], 409, 'L’identité confirmée ne correspond pas au client.');
            $nextCustomer = $this->identity($approved['customer'] ?? []);
            $nextTerms = $approved['payment_terms'] ?? [];
            abort_unless(is_array($nextTerms) && is_string($nextTerms['mode'] ?? null) && trim($nextTerms['mode']) !== ''
                && is_string($nextTerms['label'] ?? null) && trim($nextTerms['label']) !== '', 409, 'Les conditions historiques de paiement sont indisponibles.');
            abort_unless(($nextTerms['due_days'] ?? null) === null || (is_int($nextTerms['due_days']) && $nextTerms['due_days'] >= 0), 409, 'Le délai historique est invalide.');
            abort_unless(($nextTerms['due_date'] ?? null) === null || (is_string($nextTerms['due_date']) && $this->validDate($nextTerms['due_date'])), 409, 'L’échéance historique est invalide.');
            $nextTerms = ['mode' => $nextTerms['mode'], 'label' => $nextTerms['label'], 'due_days' => $nextTerms['due_days'] ?? null, 'due_date' => $nextTerms['due_date'] ?? null];
            abort_if(($customer !== null && $customer !== $nextCustomer) || ($terms !== null && $terms !== $nextTerms), 409, 'Les BL ont des identités ou conditions différentes. Séparez les factures.');
            $customer = $nextCustomer;
            $terms = $nextTerms;
        }
        abort_if($customer === null, 409, 'Aucune ligne éligible.');

        return ['schema_version' => 1, 'policy_version' => $policy['policy_version'], 'number_prefix' => $policy['number_prefix'],
            'sequence_key' => $policy['sequence_key'], 'seller' => $seller, 'customer' => $customer,
            'payment_terms' => $terms, 'tax_note' => $policy['tax_note'], 'currency' => 'MAD', 'tax_basis' => 'TTC'];
    }

    private function validDate(string $value): bool
    {
        return preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D', $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    public static function linesHash(string $json): string
    {
        return hash('sha256', json_encode(json_decode($json, true, flags: JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    private function identity(array $input): array
    {
        $safe = [];
        foreach (['name', 'address', 'ice', 'tax_identifier', 'rc', 'customer_code'] as $key) {
            $value = $input[$key] ?? null;
            abort_unless($value === null || (is_string($value) && mb_strlen($value) <= 2000), 409, 'Une identité documentaire est invalide.');
            $safe[$key] = $value;
        }
        abort_unless(is_string($safe['name']) && trim($safe['name']) !== '' && is_string($safe['address']) && trim($safe['address']) !== '', 409, 'Le nom et l’adresse documentaires sont requis.');

        return $safe;
    }
}
