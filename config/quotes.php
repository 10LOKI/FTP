<?php

return [
    'issuance_enabled' => false,
    'notifications_enabled' => env('QUOTE_NOTIFICATION_ENABLED', false),
    'policy_version' => null,
    'number_prefix' => null,
    'seller' => ['name' => null, 'address' => null, 'ice' => null, 'tax_identifier' => null, 'rc' => null],
    'tax_note' => null,
];
