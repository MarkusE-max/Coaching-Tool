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

    // Set the HTTPS address used for password-reset links (including any subfolder).
    'app_base_url' => 'https://coaching.example.de',

    // Generate a unique 64-character hexadecimal key. Keep it in config.local.php only.
    'mail_encryption_key' => 'REPLACE_WITH_64_HEX_CHAR_PRIVATE_KEY',

    // Optional private backup folder; default: <project>/storage/backups.
    // Keep backups outside the public web folder and restrict folder access.
    // 'backup_storage_path' => '/volume1/<private-shared-folder>/Coaching-Tool-Backups',
    'backup_retention_days' => 30,
];
