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

        // Catalog routes.
        ['name' => 'catalog#search', 'url' => '/api/v1/catalog/search', 'verb' => 'GET'],
        ['name' => 'catalog#import', 'url' => '/api/v1/catalogs/{id}/import', 'verb' => 'POST'],

        // Order basket routes.
        ['name' => 'orderBasket#submit', 'url' => '/api/v1/order-baskets/{id}/submit', 'verb' => 'POST'],

        // Purchase order routes.
        ['name' => 'purchaseOrder#submit', 'url' => '/api/v1/purchase-orders/{id}/submit', 'verb' => 'POST'],
        ['name' => 'purchaseOrder#cancel', 'url' => '/api/v1/purchase-orders/{id}/cancel', 'verb' => 'POST'],
        ['name' => 'purchaseOrder#sendReminder', 'url' => '/api/v1/purchase-orders/{id}/send-reminder', 'verb' => 'POST'],

        // Goods receipt routes.
        ['name' => 'goodsReceipt#create', 'url' => '/api/v1/goods-receipts', 'verb' => 'POST'],

        // RFQ routes.
        ['name' => 'rFQ#publish', 'url' => '/api/v1/rfqs/{id}/publish', 'verb' => 'POST'],
        ['name' => 'rFQ#award', 'url' => '/api/v1/rfqs/{id}/award', 'verb' => 'POST'],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
