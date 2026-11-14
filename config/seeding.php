<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Initial admin account (seeded by DatabaseSeeder when no admin exists)
    |--------------------------------------------------------------------------
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'System Administrator'),
        'email' => env('ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('ADMIN_PASSWORD', 'password'),
    ],

];
