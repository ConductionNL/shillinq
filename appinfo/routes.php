<?php

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

        // Roles.
        ['name' => 'role#index',   'url' => '/api/v1/roles',      'verb' => 'GET'],
        ['name' => 'role#create',  'url' => '/api/v1/roles',      'verb' => 'POST'],
        ['name' => 'role#show',    'url' => '/api/v1/roles/{id}', 'verb' => 'GET'],
        ['name' => 'role#update',  'url' => '/api/v1/roles/{id}', 'verb' => 'PUT'],
        ['name' => 'role#destroy', 'url' => '/api/v1/roles/{id}', 'verb' => 'DELETE'],

        // Teams.
        ['name' => 'team#index',   'url' => '/api/v1/teams',             'verb' => 'GET'],
        ['name' => 'team#create',  'url' => '/api/v1/teams',             'verb' => 'POST'],
        ['name' => 'team#show',    'url' => '/api/v1/teams/{id}',        'verb' => 'GET'],
        ['name' => 'team#update',  'url' => '/api/v1/teams/{id}',        'verb' => 'PUT'],
        ['name' => 'team#destroy', 'url' => '/api/v1/teams/{id}',        'verb' => 'DELETE'],
        ['name' => 'team#invite',  'url' => '/api/v1/teams/{id}/invite', 'verb' => 'POST'],

        // Users.
        ['name' => 'user#index',     'url' => '/api/v1/users',           'verb' => 'GET'],
        ['name' => 'user#show',      'url' => '/api/v1/users/{id}',      'verb' => 'GET'],
        ['name' => 'user#update',    'url' => '/api/v1/users/{id}',      'verb' => 'PUT'],
        ['name' => 'user#provision', 'url' => '/api/v1/users/provision', 'verb' => 'POST'],

        // Access Log (read-only).
        ['name' => 'access_control#index', 'url' => '/api/v1/access-log',      'verb' => 'GET'],
        ['name' => 'access_control#show',  'url' => '/api/v1/access-log/{id}', 'verb' => 'GET'],

        // Delegations.
        ['name' => 'delegation#create',  'url' => '/api/v1/delegations',      'verb' => 'POST'],
        ['name' => 'delegation#destroy', 'url' => '/api/v1/delegations/{id}', 'verb' => 'DELETE'],

        // Recertification.
        ['name' => 'recertification#index',  'url' => '/api/v1/recertifications',             'verb' => 'GET'],
        ['name' => 'recertification#create', 'url' => '/api/v1/recertifications',             'verb' => 'POST'],
        ['name' => 'recertification#review', 'url' => '/api/v1/recertifications/{id}/review', 'verb' => 'POST'],

        // Reports.
        ['name' => 'report#accessRights', 'url' => '/api/v1/reports/access-rights', 'verb' => 'GET'],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
