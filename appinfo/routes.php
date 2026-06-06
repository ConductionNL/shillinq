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

        // Inventory mobile scanner PWA sync endpoints (REQ-OFFLINE-002 / REQ-SYNC-001).
        // Static segments precede the {path} SPA catch-all so the sync routes match first.
        ['name' => 'inventoryMobileScanner#downloadDeltas', 'url' => '/api/v1/inventory/sync', 'verb' => 'GET'],
        ['name' => 'inventoryMobileScanner#uploadOperations', 'url' => '/api/v1/inventory/sync', 'verb' => 'POST'],
        ['name' => 'inventoryMobileScanner#listLocations', 'url' => '/api/v1/inventory/locations', 'verb' => 'GET'],

        // Booking Self-service Widget — public partner-facing API.
        // REQ-WSW-001/002 — authenticated via Authorization: Bearer + ?businessId, rate-limited
        // per WidgetAccessKey.rateLimit (default 100 req/min). Static segments precede any
        // dynamic ones so Symfony matches the explicit widget routes first.
        ['name' => 'widgetApi#services', 'url' => '/api/widget/services', 'verb' => 'GET'],
        ['name' => 'widgetApi#slots', 'url' => '/api/widget/slots', 'verb' => 'GET'],
        ['name' => 'widgetApi#appointments', 'url' => '/api/widget/appointments', 'verb' => 'POST'],
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

        // Calendar + booking REST API (bookings-resource-calendar, issue #117).
        // Specific path segments (.../bookings) precede the bare {calendarId} route
        // so Symfony matches the booking range/create endpoints first.
        ['name' => 'calendar#index', 'url' => '/api/v2/calendars', 'verb' => 'GET'],
        ['name' => 'calendar#listBookings', 'url' => '/api/v2/calendars/{calendarId}/bookings', 'verb' => 'GET'],
        ['name' => 'calendar#createBooking', 'url' => '/api/v2/calendars/{calendarId}/bookings', 'verb' => 'POST'],
        ['name' => 'calendar#show', 'url' => '/api/v2/calendars/{calendarId}', 'verb' => 'GET'],

        ['name' => 'vATReturn#show', 'url' => '/api/vat-returns/{returnId}', 'verb' => 'GET'],
        ['name' => 'vATReturn#update', 'url' => '/api/vat-returns/{returnId}', 'verb' => 'PUT'],
        ['name' => 'vATReturn#destroy', 'url' => '/api/vat-returns/{returnId}', 'verb' => 'DELETE'],

        // Invoice generation from time + expense (Tier 2, invoice-from-time-and-expense,
        // issue #111). Static {invoiceId}/{action} routes precede the bare {invoiceId}
        // routes so Symfony matches them first.
        ['name' => 'invoiceApi#generate', 'url' => '/api/v1/invoices/generate', 'verb' => 'POST'],
        ['name' => 'invoiceApi#index', 'url' => '/api/v1/invoices', 'verb' => 'GET'],
        ['name' => 'invoiceApi#post', 'url' => '/api/v1/invoices/{invoiceId}/post', 'verb' => 'POST'],
        ['name' => 'invoiceApi#pdf', 'url' => '/api/v1/invoices/{invoiceId}/pdf', 'verb' => 'GET'],
        ['name' => 'invoiceApi#show', 'url' => '/api/v1/invoices/{invoiceId}', 'verb' => 'GET'],

        // Appointment confirmation flow (bookings-confirm-flow, REQ-BCF-004/006/007).
        // Static segments precede the SPA catch-all so the confirm/resend/validate
        // endpoints match first. `confirm` and `validate-confirmation-token` are
        // #[PublicPage]; `resend-confirmation` is #[NoAdminRequired] + per-
        // appointment IDOR guard inside the controller.
        ['name' => 'confirmationApi#lookupByToken', 'url' => '/api/v1/appointments/validate-confirmation-token', 'verb' => 'GET'],
        ['name' => 'confirmationApi#confirm', 'url' => '/api/v1/appointments/{appointmentId}/confirm', 'verb' => 'PATCH'],
        ['name' => 'confirmationApi#resend', 'url' => '/api/v1/appointments/{appointmentId}/resend-confirmation', 'verb' => 'POST'],

        // Booking notification triggers + admin monitor (bookings-notification-triggers,
        // issue #115). Static admin segments precede the booking-scoped routes so the
        // /api/admin/* prefix matches first under Symfony.
        ['name' => 'notification#adminMonitor', 'url' => '/api/admin/notification-monitor', 'verb' => 'GET'],
        ['name' => 'notification#adminDisableAll', 'url' => '/api/admin/notification-monitor/disable-all', 'verb' => 'POST'],
        ['name' => 'notification#listForBooking', 'url' => '/api/bookings/{id}/notification-triggers', 'verb' => 'GET'],
        ['name' => 'notification#updateForBooking', 'url' => '/api/bookings/{id}/notification-triggers', 'verb' => 'PATCH'],

        // Inventory barcode lookup endpoint (REQ-SKU-007 / REQ-SKU-008).
        // Public route attribute (Bearer API-key in the controller body) so POS
        // terminals without an NC session can call it; declared before the SPA
        // catch-all so Symfony matches it first per ADR-016.
        ['name' => 'barcodeLookup#lookup', 'url' => '/api/barcode/lookup/{code}', 'verb' => 'GET', 'requirements' => ['code' => '.+']],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
