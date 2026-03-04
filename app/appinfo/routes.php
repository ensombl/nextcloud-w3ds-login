<?php

declare(strict_types=1);

return [
    'routes' => [
        // Auth flow (public, no login required)
        ['name' => 'auth#offer', 'url' => '/auth/offer', 'verb' => 'GET'],
        ['name' => 'auth#callback', 'url' => '/auth/callback', 'verb' => 'POST'],
        ['name' => 'auth#status', 'url' => '/auth/status', 'verb' => 'GET'],
        ['name' => 'auth#completeLogin', 'url' => '/auth/complete', 'verb' => 'GET'],

        // Personal settings (authenticated)
        ['name' => 'settings#linkOffer', 'url' => '/settings/link', 'verb' => 'GET'],
        ['name' => 'settings#linkCallback', 'url' => '/settings/link/callback', 'verb' => 'POST'],
        ['name' => 'settings#unlink', 'url' => '/settings/unlink', 'verb' => 'POST'],
    ],
];
