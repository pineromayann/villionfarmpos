<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Role Permissions
    |--------------------------------------------------------------------------
    |
    | Maps each role to the permissions it grants. A permission of "*" grants
    | everything. These strings are checked by App\Models\User::hasPermission()
    | and are the vocabulary used by policies and middleware throughout the
    | application.
    |
    */

    'permissions' => [

        'backup.view' => 'View the backup list and backup details',
        'backup.create' => 'Create new database and full backups',
        'backup.download' => 'Download backup files',
        'backup.delete' => 'Delete backups',
        'backup.restore' => 'Restore the database from a backup',

    ],

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    |
    | Roles are deliberately coarse and stored directly on the users table in
    | the "role" column. Only the permissions that are actually enforced by
    | the application are listed here.
    |
    */

    'roles' => [

        'administrator' => [
            'label' => 'Administrator',
            'permissions' => ['*'],
        ],

        'manager' => [
            'label' => 'Manager',
            'permissions' => [
                'backup.view',
                'backup.create',
                'backup.download',
                'backup.delete',
            ],
        ],

        'cashier' => [
            'label' => 'Cashier',
            'permissions' => [],
        ],

        'inventory' => [
            'label' => 'Inventory Staff',
            'permissions' => [],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Default Role
    |--------------------------------------------------------------------------
    */

    'default_role' => 'cashier',

];
