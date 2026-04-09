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

        // Portal routes (token-based auth, no Nextcloud session required).
        ['name' => 'portal#auth', 'url' => '/api/v1/portal/auth', 'verb' => 'POST'],
        ['name' => 'portal#invoices', 'url' => '/api/v1/portal/invoices', 'verb' => 'GET'],
        ['name' => 'portal#payments', 'url' => '/api/v1/portal/payments', 'verb' => 'GET'],
        ['name' => 'portal#generate', 'url' => '/api/v1/portal/tokens', 'verb' => 'POST'],

        // Analytics routes.
        ['name' => 'analytics#kpi', 'url' => '/api/v1/analytics/kpi/{metricKey}', 'verb' => 'GET'],
        ['name' => 'analytics#runReport', 'url' => '/api/v1/analytics/reports/{reportType}/run', 'verb' => 'POST'],
        ['name' => 'analytics#snapshot', 'url' => '/api/v1/analytics/reports/{id}/snapshot', 'verb' => 'GET'],

        // Bulk action routes.
        ['name' => 'bulkAction#approve', 'url' => '/api/v1/bulk/{schema}/approve', 'verb' => 'POST'],
        ['name' => 'bulkAction#delete', 'url' => '/api/v1/bulk/{schema}/delete', 'verb' => 'POST'],
        ['name' => 'bulkAction#assign', 'url' => '/api/v1/bulk/{schema}/assign', 'verb' => 'POST'],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
