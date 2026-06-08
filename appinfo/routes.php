<?php

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

        // Pipelinq integration connection settings — bookings-pipelinq-customer-bridge
        // member 01. GET returns endpoint + hasToken flag (never the token itself);
        // POST persists endpoint + optional token (absent token preserves current
        // value, '' clears); test runs the live health-check used by the admin
        // "Test Connection" button. All three are #[AuthorizedAdminSetting]-gated.
        ['name' => 'pipelinqSettings#index', 'url' => '/api/pipelinq/settings', 'verb' => 'GET'],
        ['name' => 'pipelinqSettings#create', 'url' => '/api/pipelinq/settings', 'verb' => 'POST'],
        ['name' => 'pipelinqSettings#test', 'url' => '/api/pipelinq/settings/test', 'verb' => 'POST'],

        // bookings-pipelinq-customer-bridge slice 09 — admin dead-letter
        // dashboard over the TimelineDeadLetter register populated by
        // PipelinqTimelineRetryJob. index() lists exhausted retries;
        // retry({id}) re-queues an event by writing a fresh
        // TimelinePublishRetryEntry + scheduling a job tick. Both gated
        // by #[AuthorizedAdminSetting].
        ['name' => 'timelineDeadLetter#index', 'url' => '/api/pipelinq/dead-letter', 'verb' => 'GET'],
        ['name' => 'timelineDeadLetter#retry', 'url' => '/api/pipelinq/dead-letter/{id}/retry', 'verb' => 'POST'],

        // Generic per-user preferences (used by shared nextcloud-vue widgets, e.g. CnSupportDialog).
        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],

        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Pipelinq customer-bridge metrics (slice 11). Prometheus exposition
        // format; pulls from CustomerBridgeMetricsService for the contact /
        // timeline / retry / dead-letter / circuit-breaker series.
        ['name' => 'metrics#pipelinq', 'url' => '/api/metrics/pipelinq', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

        // Trial balance (Tier 2): read-only per-account period aggregation.
        ['name' => 'trialBalance#index', 'url' => '/api/trial-balance', 'verb' => 'GET'],

        // Credit control & dunning ladder (Tier 2 — issue #124).
        // Static segments first; the resume route uses a {pauseId} wildcard.
        ['name' => 'dunning#bik', 'url' => '/api/dunning/bik', 'verb' => 'POST'],
        ['name' => 'dunning#executeRun', 'url' => '/api/dunning/runs/execute', 'verb' => 'POST'],
        ['name' => 'dunning#pause', 'url' => '/api/dunning/pauses', 'verb' => 'POST'],
        ['name' => 'dunning#dossier', 'url' => '/api/dunning/incasso/dossier', 'verb' => 'POST'],
        ['name' => 'dunning#writeOff', 'url' => '/api/dunning/writeoffs', 'verb' => 'POST'],
        ['name' => 'dunning#resumePause', 'url' => '/api/dunning/pauses/{pauseId}/resume', 'verb' => 'POST'],

        // OSS (One-Stop-Shop, Tier 2): destination-country rate resolution + quarterly return generation.
        ['name' => 'oss#resolveRate', 'url' => '/api/oss/rate', 'verb' => 'GET'],
        ['name' => 'oss#generateReturn', 'url' => '/api/oss/return', 'verb' => 'GET'],

        // Multi-administratie (multi-tenant) context, switcher and per-administration export scope.
        // Static segments precede the {id} wildcard so they are matched first.
        ['name' => 'administration#context', 'url' => '/api/administrations/context', 'verb' => 'GET'],
        ['name' => 'administration#switch', 'url' => '/api/administrations/switch', 'verb' => 'POST'],
        ['name' => 'administration#exportScope', 'url' => '/api/administrations/{id}/export-scope', 'verb' => 'GET'],
        ['name' => 'administration#writableStatus', 'url' => '/api/administrations/{id}/writable', 'verb' => 'GET'],

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

        // Booking detail (bookings-pipelinq-customer-bridge-05). Hydrates the
        // Appointment record with the linked pipelinq Contact profile +
        // klantbeeld history when `pipelinqContactId` is set, degrading to
        // `contactError` (booking still renders) on adapter failure.
        // #[NoAdminRequired] + AdministrationContextService IDOR guard.
        // Declared before the SPA catch-all so Symfony matches it first per
        // ADR-016.
        ['name' => 'bookingDetail#show', 'url' => '/api/v1/bookings/{id}', 'verb' => 'GET'],

        // Inventory barcode lookup endpoint (REQ-SKU-007 / REQ-SKU-008).
        // Public route attribute (Bearer API-key in the controller body) so POS
        // terminals without an NC session can call it; declared before the SPA
        // catch-all so Symfony matches it first per ADR-016.
        ['name' => 'barcodeLookup#lookup', 'url' => '/api/barcode/lookup/{code}', 'verb' => 'GET', 'requirements' => ['code' => '.+']],

        // Stock-ledger drill-down per inventory-stock-movement-ledger REQ-SM-005 + REQ-SM-009.
        // Returns the reconciled on-hand, reserved + available breakdown plus a chronological
        // running-total trace of every posted, non-cancelled StockMove touching (admin, location, sku).
        // #[NoAdminRequired] in the controller; AdministrationContextService enforces tenant IDOR
        // (masked 404 for non-members). Declared before the SPA catch-all so Symfony matches it
        // first per ADR-016.
        ['name' => 'stockLedger#trace', 'url' => '/api/stock-ledger/trace', 'verb' => 'GET'],

        // Purchase Order 3-way-match core (slice 02): server-authoritative create +
        // approval-chain preview + send-block guard. Static segments precede the
        // {id} wildcard so they are matched first (Symfony route ordering).
        ['name' => 'purchaseOrder#previewApprovalChain', 'url' => '/api/purchase-orders/approval-chain', 'verb' => 'GET'],
        ['name' => 'purchaseOrder#create', 'url' => '/api/purchase-orders', 'verb' => 'POST'],
        ['name' => 'purchaseOrder#send', 'url' => '/api/purchase-orders/{id}/send', 'verb' => 'POST'],

        // Purchase Order 3-way-match slice 03 — Peppol transmission + PDF/email
        // fallback. Static segments precede the {id} wildcard so they are matched
        // first (Symfony route ordering).
        ['name' => 'purchaseOrder#transmitPeppol', 'url' => '/api/purchase-orders/{id}/transmit/peppol', 'verb' => 'POST'],
        ['name' => 'purchaseOrder#transmitEmail', 'url' => '/api/purchase-orders/{id}/transmit/email', 'verb' => 'POST'],

        // Goods Receipt Note (slice 04 of bookkeeping-purchase-order-3way):
        // server-authoritative create / add-line / quality-check / accept /
        // upload-photos endpoints. The accept transition posts a StockMove
        // credit per accepted line and updates the originating PO(s) lifecycle.
        // Every endpoint is #[NoAdminRequired] with a per-administration IDOR
        // guard inside the controller (ADR-005). Static path segments precede
        // the {id} wildcard so Symfony's route ordering matches them first.
        ['name' => 'goodsReceiptNote#create', 'url' => '/api/goods-receipt-notes', 'verb' => 'POST'],
        ['name' => 'goodsReceiptNote#addLine', 'url' => '/api/goods-receipt-notes/{id}/lines', 'verb' => 'POST'],
        ['name' => 'goodsReceiptNote#qualityCheck', 'url' => '/api/goods-receipt-notes/{id}/quality-check', 'verb' => 'POST'],
        ['name' => 'goodsReceiptNote#accept', 'url' => '/api/goods-receipt-notes/{id}/accept', 'verb' => 'POST'],
        ['name' => 'goodsReceiptNote#uploadPhotos', 'url' => '/api/goods-receipt-notes/{id}/photos', 'verb' => 'POST'],

        // Three-Way Matching Engine (slice 06 of bookkeeping-purchase-order-3way):
        // server-authoritative trigger endpoint that evaluates a SupplierInvoice
        // against its PO + GRN candidates, applies the most-specific
        // ToleranceProfile (supplier > category > gl_account > global) and
        // writes a ThreeWayMatch record. The endpoint is #[NoAdminRequired] with
        // a per-administration IDOR guard inside the controller (ADR-005);
        // cross-tenant invoice ids are masked as 404. Declared before the SPA
        // catch-all so Symfony's route ordering matches it first.
        ['name' => 'threeWayMatch#evaluate', 'url' => '/api/three-way-matches/evaluate', 'verb' => 'POST'],

        // Multi-PO consolidation (slice 07 of bookkeeping-purchase-order-3way):
        // consolidate fan-out, candidate enumeration for the disambiguation panel,
        // and the operator's disambiguation choice. Every endpoint is
        // #[NoAdminRequired] with a per-administration IDOR guard in the
        // controller (ADR-005). Static segments only — no path wildcards — so
        // Symfony route ordering vs. the SPA catch-all is not at risk.
        ['name' => 'multiPoConsolidation#consolidate', 'url' => '/api/three-way-match/consolidate', 'verb' => 'POST'],
        ['name' => 'multiPoConsolidation#candidates', 'url' => '/api/three-way-match/candidates', 'verb' => 'GET'],
        ['name' => 'multiPoConsolidation#disambiguate', 'url' => '/api/three-way-match/disambiguate', 'verb' => 'POST'],

        // bookkeeping-purchase-order-3way slice 08 (REQ-PO3W-005) — the
        // three resolution dispositions of the exception workflow. Every
        // endpoint is #[NoAdminRequired] with a per-administration IDOR
        // guard in the controller (ADR-005). Static segments only — no
        // path wildcards — so Symfony route ordering vs. the SPA
        // catch-all is not at risk.
        ['name' => 'threeWayMatchException#accept', 'url' => '/api/three-way-match/exceptions/accept', 'verb' => 'POST'],
        ['name' => 'threeWayMatchException#dispute', 'url' => '/api/three-way-match/exceptions/dispute', 'verb' => 'POST'],
        ['name' => 'threeWayMatchException#reject', 'url' => '/api/three-way-match/exceptions/reject', 'verb' => 'POST'],

        // bookkeeping-purchase-order-3way slice 11 (REQ-PO3W-010) — the
        // audit-trail export endpoints (lifecycle ledger + ZIP package)
        // and the approval-decision endpoint that records approver
        // identity + timestamp on the PurchaseOrder approval chain.
        // Every endpoint is #[NoAdminRequired] with a per-administration
        // IDOR guard in the controller (ADR-005). Static segments only —
        // no path wildcards — so Symfony route ordering vs. the SPA
        // catch-all is not at risk.
        ['name' => 'threeWayMatchAudit#ledger', 'url' => '/api/three-way-match/audit-trail', 'verb' => 'GET'],
        ['name' => 'threeWayMatchAudit#export', 'url' => '/api/three-way-match/audit-trail/export', 'verb' => 'POST'],
        ['name' => 'purchaseOrderApproval#decide', 'url' => '/api/purchase-orders/{id}/approval-decision', 'verb' => 'POST'],

        // bookkeeping-waterschappen-bbv-variant slice 04 — manifest + routes
        // skeleton for the waterschappen BBV chain. Registers three GET page
        // routes (the BBV compliance dashboard envelope + Budget Mapping
        // index/detail) that members 05 (dashboard widgets) and 06/07
        // (mapping UI) bind to. Every endpoint is #[NoAdminRequired] in the
        // controller; mutating writes go through OpenRegister's object
        // endpoints (admin-write per slice 01 permissions) so no per-object
        // IDOR surface is introduced here. Routes are registered only in
        // appinfo/routes.php (ADR-016) and declared before the SPA catch-all
        // so Symfony matches them first.
        ['name' => 'bBVDashboard#index', 'url' => '/bbv-dashboard', 'verb' => 'GET'],
        ['name' => 'budgetBBVMapping#index', 'url' => '/budget-mappings', 'verb' => 'GET'],
        ['name' => 'budgetBBVMapping#show', 'url' => '/budget-mappings/{id}', 'verb' => 'GET'],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
