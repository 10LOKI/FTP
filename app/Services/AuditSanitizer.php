<?php

namespace App\Services;

class AuditSanitizer
{
    private const FIELDS = [
        'before', 'after', 'id', 'product_id', 'customer_profile_id', 'customer_code', 'type', 'approval',
        'code', 'label', 'permissions', 'version', 'access_version', 'must_change_password', 'role_ids', 'delivery_id', 'status', 'attempt',
        'approval_event_id', 'trigger_event_id', 'schema_version', 'publication', 'sale_price', 'percentage',
        'currency', 'tax_basis', 'reference', 'original_reference', 'brand', 'verified_at', 'starts_at', 'ends_at',
        'state', 'kind', 'value', 'quantity', 'units_per_package', 'before_quantity', 'after_quantity',
        'line_id', 'title', 'sku', 'designation', 'part_brand', 'category', 'hs_code', 'net_weight', 'weight_unit', 'purchase_price_changed', 'barcodes_count', 'compatibilities_count', 'count', 'row_count', 'month', 'channel', 'notification_type',
    ];

    /** @return array<string|int, mixed>|null */
    public static function changes(?array $values, int $depth = 0, ?string $field = null): ?array
    {
        if ($values === null || $depth > 5) {
            return null;
        }
        // Role changes accept up to 1,000 permission codes; keep the complete audit delta.
        if ($field === 'permissions') {
            return array_values(array_filter(array_slice($values, 0, 1000), fn ($value): bool => is_string($value)
                && preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,254}\z/', $value) === 1));
        }

        $safe = [];
        foreach (array_slice($values, 0, 100, true) as $key => $value) {
            if ((is_int($key) && $field !== 'role_ids') || (! is_int($key) && ! in_array($key, self::FIELDS, true))) {
                continue;
            }
            if (is_array($value)) {
                $safe[$key] = self::changes($value, $depth + 1, (string) $key);
            } elseif ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
                $safe[$key] = $value;
            } elseif (is_string($value)) {
                $safe[$key] = mb_substr($value, 0, 255);
            }
        }

        return $safe;
    }
}
