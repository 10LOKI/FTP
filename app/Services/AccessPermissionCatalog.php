<?php

namespace App\Services;

class AccessPermissionCatalog
{
    /** @var list<string> */
    public const ADMINISTRATOR_PERMISSIONS = ['ROLE_MANAGE', 'USER_ASSIGN_ROLE'];

    /** @var array<string, string> */
    public const LABELS = [
        'CUSTOMER_APPROVE' => 'Review customer registrations and approval decisions',
        'USER_CREATE' => 'Create employee accounts',
        'USER_ASSIGN_ROLE' => 'Assign ordinary roles to employees',
        'USER_RESET_ACCESS' => 'Reissue employee access and view delivery status',
        'ROLE_MANAGE' => 'Manage roles and their permissions',
        'ROLE_DELETE' => 'Delete eligible ordinary roles',
        'STOCK_VIEW' => 'Consulter les stocks, mouvements et alertes par dépôt',
        'STOCK_RECEIVE' => 'Réceptionner des produits en stock',
        'STOCK_ADJUST' => 'Corriger et contrôler le stock avec justification',
        'STOCK_TRANSFER' => 'Transférer le stock disponible entre dépôts',
        'STOCK_STRUCTURE_MANAGE' => 'Gérer les dépôts, emplacements et seuils',
        'ORDER_CONFIRM' => 'Confirmer une commande et réserver son stock',
        'RESERVATION_CANCEL' => 'Libérer les réservations d’une commande annulée',
    ];
}
