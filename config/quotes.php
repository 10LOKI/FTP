<?php

return [
    'issuance_enabled' => false,
    'demo_mode' => env('QUOTE_DEMO_MODE', false),
    'notifications_enabled' => env('QUOTE_NOTIFICATION_ENABLED', false),
    'policy_version' => null,
    'number_prefix' => null,
    'seller' => ['name' => null, 'address' => null, 'ice' => null, 'tax_identifier' => null, 'rc' => null],
    'tax_note' => null,
    'demo' => [
        'policy_version' => 'DEMO-V1',
        'number_prefix' => 'DEMO-',
        'seller' => ['name' => 'FTP DEMO - Societe fictive', 'address' => '123 rue de Demonstration, Ville Exemple, Maroc',
            'ice' => 'DEMO-ICE-NON-VALIDE', 'tax_identifier' => 'DEMO-IF-NON-VALIDE', 'rc' => 'DEMO-RC-NON-VALIDE'],
        'tax_note' => 'DEMONSTRATION : montants de test en MAD TTC. Mention fiscale fictive, a remplacer avant usage reel.',
    ],
];
