<?php

declare(strict_types=1);

return [
    'routes' => [
        // Auth flow (public, no login required)
        ['name' => 'auth#offer', 'url' => '/auth/offer', 'verb' => 'GET'],
        ['name' => 'auth#callback', 'url' => '/auth/callback', 'verb' => 'POST'],
        ['name' => 'auth#preflight', 'url' => '/auth/callback', 'verb' => 'OPTIONS'],
        ['name' => 'auth#status', 'url' => '/auth/status', 'verb' => 'GET'],
        ['name' => 'auth#completeLogin', 'url' => '/auth/complete', 'verb' => 'GET'],

        // Account linking (authenticated; wallet callback goes through auth#callback)
        ['name' => 'settings#linkStart', 'url' => '/settings/link/start', 'verb' => 'POST'],
        ['name' => 'settings#linkStatus', 'url' => '/settings/link/status', 'verb' => 'GET'],
        ['name' => 'settings#unlink', 'url' => '/settings/unlink', 'verb' => 'POST'],

        // eVault webhook (public, receives awareness protocol packets)
        ['name' => 'webhook#receive', 'url' => '/api/webhook', 'verb' => 'POST'],

        // Per-room poll of participant eVaults (authenticated; client-driven every ~15s)
        ['name' => 'poll#pollRoom', 'url' => '/api/rooms/{token}/poll', 'verb' => 'POST'],
    ],
];
