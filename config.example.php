<?php
declare(strict_types=1);

/*
 * Copy this to config.local.php on the NAS.
 * Keep the real config outside public/ and out of Git.
 */
return [
    'database' => [
        'host' => '127.0.0.1',
        // Check the current MariaDB 10 port in DSM; it may be changed there.
        'port' => 3307,
        'name' => 'coaching_tool',
        'username' => 'coaching_app',
        'password' => 'SET_A_PRIVATE_PASSWORD',
    ],

    // Generate a random key of at least 32 characters for first-admin setup.
    // Remove this entry after creating the first account.
    'initial_admin_setup_key' => 'REPLACE_WITH_STRONG_PRIVATE_SETUP_KEY',
];
