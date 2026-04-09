<?php

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

        // Comment endpoints.
        ['name' => 'comment#index',   'url' => '/api/v1/comments',              'verb' => 'GET'],
        ['name' => 'comment#create',  'url' => '/api/v1/comments',              'verb' => 'POST'],
        ['name' => 'comment#update',    'url' => '/api/v1/comments/{id}',           'verb' => 'PUT'],
        ['name' => 'comment#anonymise', 'url' => '/api/v1/comments/{id}/anonymise', 'verb' => 'PATCH'],
        ['name' => 'comment#resolve',   'url' => '/api/v1/comments/{id}/resolve',   'verb' => 'PATCH'],
        ['name' => 'comment#destroy',   'url' => '/api/v1/comments/{id}',           'verb' => 'DELETE'],

        // Collaboration role endpoints.
        ['name' => 'collaborationRole#index',   'url' => '/api/v1/collaboration/roles',      'verb' => 'GET'],
        ['name' => 'collaborationRole#create',  'url' => '/api/v1/collaboration/roles',      'verb' => 'POST'],
        ['name' => 'collaborationRole#destroy', 'url' => '/api/v1/collaboration/roles/{id}', 'verb' => 'DELETE'],

        // Presence endpoints.
        ['name' => 'presence#ping',  'url' => '/api/v1/presence/ping', 'verb' => 'POST'],
        ['name' => 'presence#index', 'url' => '/api/v1/presence',      'verb' => 'GET'],

        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
