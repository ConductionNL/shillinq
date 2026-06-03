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

        // Booking notification trigger configuration (organizer, per booking).
        ['name' => 'bookingNotification#getBookingTriggers',    'url' => '/api/bookings/{id}/notification-triggers', 'verb' => 'GET'],
        ['name' => 'bookingNotification#updateBookingTriggers', 'url' => '/api/bookings/{id}/notification-triggers', 'verb' => 'PATCH'],

        // Admin notification monitor dashboard.
        ['name' => 'bookingNotification#getNotificationMonitor', 'url' => '/api/admin/notification-monitor', 'verb' => 'GET'],
        ['name' => 'bookingNotification#disableAllTriggers',     'url' => '/api/admin/notification-monitor/disable-all', 'verb' => 'POST'],

        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
