<?php

namespace App\Services;

class QuoteIssuanceContext
{
    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function resolve(array $snapshot): array
    {
        abort_unless(config('quotes.issuance_enabled') === true, 503, 'Émission des devis désactivée : règles documentaires à approuver.');
        $policy = config('quotes');
        foreach (['policy_version', 'number_prefix', 'tax_note'] as $field) {
            abort_unless(is_string($policy[$field] ?? null) && trim($policy[$field]) !== '', 503, 'La politique des devis est incomplète.');
        }
        abort_unless(preg_match('/\A[A-Za-z0-9-]{1,40}\z/', $policy['number_prefix']) === 1, 503, 'Préfixe de devis invalide.');
        foreach (['name', 'address'] as $field) {
            abort_unless(is_string($policy['seller'][$field] ?? null) && trim($policy['seller'][$field]) !== '', 503, 'Identité vendeur à approuver.');
        }
        abort_unless(($snapshot['payment_terms_status'] ?? null) === 'ready' && is_array($snapshot['payment_terms'] ?? null), 409, 'Le contrat de paiement réel est en attente d’intégration.');
        foreach (['mode', 'label'] as $field) {
            abort_unless(is_string($snapshot['payment_terms'][$field] ?? null) && trim($snapshot['payment_terms'][$field]) !== '', 409, 'Conditions de paiement incomplètes.');
        }
        abort_unless(($snapshot['currency'] ?? null) === 'MAD' && ($snapshot['tax_basis'] ?? null) === 'TTC'
            && is_string($snapshot['fees'] ?? null) && preg_match('/\A[0-9]+\.[0-9]{2}\z/', $snapshot['fees']) === 1, 409, 'Montants ou frais non validés.');
        abort_unless($snapshot['fees'] === '0.00', 409, 'Les frais non nuls nécessitent leur contrat de totalisation.');

        return ['policy_version' => $policy['policy_version'], 'number_prefix' => $policy['number_prefix'],
            'seller' => $policy['seller'], 'tax_note' => $policy['tax_note']];
    }
}
