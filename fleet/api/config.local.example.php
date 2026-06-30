<?php
/**
 * Local configuration overrides
 * Copy this file to config.local.php and fill in the values
 */

return [
    'app' => [
        'debug' => true,
        'url' => 'https://fleet.devcon1.hu',
    ],

    'database' => [
        'host' => 'localhost',
        'name' => 'fleet_manager',
        'user' => 'fleet_manager',
        'password' => 'YOUR_DB_PASSWORD_HERE',
    ],

    'jwt' => [
        // Generate with: php -r "echo bin2hex(random_bytes(32));"
        'secret' => 'YOUR_JWT_SECRET_HERE',
    ],

    'encryption' => [
        // Generate with: php -r "echo base64_encode(random_bytes(32));"
        'key' => 'YOUR_ENCRYPTION_KEY_HERE',
    ],
];

