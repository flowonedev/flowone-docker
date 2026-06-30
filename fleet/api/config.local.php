<?php
/**
 * Local configuration overrides
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
        'password' => 'YPuHHY$uMudaHEpH',
    ],

    'jwt' => [
        'secret' => 'dev-secret-change-in-production',
    ],

    'encryption' => [
        'key' => 'ZGV2LWVuY3J5cHRpb24ta2V5LWNoYW5nZS1pbi1wcm9k',
    ],
];

