<?php

// Laravel's loader merges these overrides with its framework validation catalog.
return [
    'attributes' => [
        'name' => 'name',
        'email' => 'email',
        'password' => 'password',
        'role_ids' => 'roles',
        'role_ids.*' => 'role',
        'permissions' => 'permissions',
        'permissions.*' => 'permission',
        'slug' => 'slug',
        'description' => 'description',
        'is_active' => 'account enabled',
        'locale' => 'language',
        'return_to' => 'return page',
    ],
];
