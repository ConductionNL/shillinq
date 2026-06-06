<?php

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

        // Generic per-user preferences (used by shared nextcloud-vue widgets, e.g. CnSupportDialog).
        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],

        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

        // Trial balance (Tier 2): read-only per-account period aggregation.
        ['name' => 'trialBalance#index', 'url' => '/api/trial-balance', 'verb' => 'GET'],

        // OSS (One-Stop-Shop, Tier 2): destination-country rate resolution + quarterly return generation.
        ['name' => 'oss#resolveRate', 'url' => '/api/oss/rate', 'verb' => 'GET'],
        ['name' => 'oss#generateReturn', 'url' => '/api/oss/return', 'verb' => 'GET'],

        // Multi-administratie (multi-tenant) context, switcher and per-administration export scope.
        // Static segments precede the {id} wildcard so they are matched first.
        ['name' => 'administration#context', 'url' => '/api/administrations/context', 'verb' => 'GET'],
        ['name' => 'administration#switch', 'url' => '/api/administrations/switch', 'verb' => 'POST'],
        ['name' => 'administration#exportScope', 'url' => '/api/administrations/{id}/export-scope', 'verb' => 'GET'],

        // Payroll engine (NL loonadministratie): read-only compute endpoints.
        ['name' => 'payroll#loonstrook', 'url' => '/api/payroll/loonstrook', 'verb' => 'GET'],
        ['name' => 'payroll#lhAfdracht', 'url' => '/api/payroll/lh-afdracht', 'verb' => 'GET'],
        ['name' => 'payroll#journaalpost', 'url' => '/api/payroll/journaalpost', 'verb' => 'GET'],

        // BTW-aangifte (Tier 3, bookkeeping-vat-btw-filing, issue #127). Specific
        // {returnId}/{action} routes precede the bare {returnId} routes so Symfony
        // matches them first; declaration + line endpoints are read-only.
        ['name' => 'vATReturn#index', 'url' => '/api/vat-returns', 'verb' => 'GET'],
        ['name' => 'vATReturn#create', 'url' => '/api/vat-returns', 'verb' => 'POST'],
        ['name' => 'vATReturn#submit', 'url' => '/api/vat-returns/{returnId}/submit', 'verb' => 'POST'],
        ['name' => 'vATReturn#rebase', 'url' => '/api/vat-returns/{returnId}/rebase', 'verb' => 'POST'],
        ['name' => 'vATDeclaration#listByReturn', 'url' => '/api/vat-returns/{returnId}/declarations', 'verb' => 'GET'],
        ['name' => 'vATLine#listByReturn', 'url' => '/api/vat-returns/{returnId}/lines', 'verb' => 'GET'],
        ['name' => 'vATLine#listByDeclaration', 'url' => '/api/vat-declarations/{declarationId}/lines', 'verb' => 'GET'],
        ['name' => 'vATReturn#show', 'url' => '/api/vat-returns/{returnId}', 'verb' => 'GET'],
        ['name' => 'vATReturn#update', 'url' => '/api/vat-returns/{returnId}', 'verb' => 'PUT'],
        ['name' => 'vATReturn#destroy', 'url' => '/api/vat-returns/{returnId}', 'verb' => 'DELETE'],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
