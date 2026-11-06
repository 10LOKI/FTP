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
    ];
}
