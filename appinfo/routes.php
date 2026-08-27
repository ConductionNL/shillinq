<?php

/**
 * Shillinq route table.
 *
 * AppHost adoption (adopt-apphost): the canonical fleet-skeleton routes
 * (`dashboard#page`, `dashboard#catchAll`, `settings#index|create|load`,
 * `preferences#getPreference|setPreference`, `metrics#index`, `health#index`)
 * are provided by `\OCA\OpenRegister\AppHost\Routes::standard()` — their names,
 * URLs and verbs are unchanged, so info.xml navigation + every probe/scrape URL
 * keeps resolving. The `dashboard`/`health`/`metrics` controllers resolve to
 * the engine generics (aliased in Application::register()); the `settings` and
 * `preferences` controllers resolve to shillinq's KEPT bespoke controllers
 * (fragment-merge config loading + per-user preferences — see Application.php).
 *
 * Every shillinq-specific route is passed as `$extra`; `Routes::standard()`
 * inserts them BEFORE the SPA catch-all so they keep priority.
 *
 * The former JSON `GET /api/metrics` (ADR-006 violation) and the redundant
 * `GET /api/metrics/pipelinq` Prometheus alias are removed: the customer-bridge
 * series are now merged into the engine-owned `GET /api/metrics` Prometheus
 * exposition via the CustomerBridgeMetricsService IMetricsProvider.
 *
 * @category AppInfo
 * @package  OCA\Shillinq\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

return \OCA\OpenRegister\AppHost\Routes::standard(
        [
        // Pipelinq integration connection settings — bookings-pipelinq-customer-bridge
        // member 01. GET returns endpoint + hasToken flag (never the token itself);
        // POST persists endpoint + optional token (absent token preserves current
        // value, '' clears); test runs the live health-check used by the admin
        // "Test Connection" button. All three are #[AuthorizedAdminSetting]-gated.
            ['name' => 'pipelinqSettings#index', 'url' => '/api/pipelinq/settings', 'verb' => 'GET'],
            ['name' => 'pipelinqSettings#create', 'url' => '/api/pipelinq/settings', 'verb' => 'POST'],
            ['name' => 'pipelinqSettings#test', 'url' => '/api/pipelinq/settings/test', 'verb' => 'POST'],
        // First-time setup wizard (ADR-042).
            ['name' => 'setup#status',     'url' => '/api/setup/status',            'verb' => 'GET'],
            ['name' => 'setup#saveConfig', 'url' => '/api/setup/config',            'verb' => 'POST'],
            ['name' => 'setup#runAction',  'url' => '/api/setup/action/{actionId}', 'verb' => 'POST'],

        // Bookings-pipelinq-customer-bridge slice 09 — admin dead-letter
        // dashboard over the TimelineDeadLetter register populated by
        // PipelinqTimelineRetryJob. index() lists exhausted retries;
        // retry({id}) re-queues an event by writing a fresh
        // TimelinePublishRetryEntry + scheduling a job tick. Both gated
        // by #[AuthorizedAdminSetting].
            ['name' => 'timelineDeadLetter#index', 'url' => '/api/pipelinq/dead-letter', 'verb' => 'GET'],
            ['name' => 'timelineDeadLetter#retry', 'url' => '/api/pipelinq/dead-letter/{id}/retry', 'verb' => 'POST'],

        // Add-shillinq-multi-currency Task 14: FxRate admin status — read-only
        // endpoint that surfaces the last-run timestamp of FxRateImportJob plus
        // the TreasuryRateAdapter dormancy flag. Drives the FxRatesAdmin Vue
        // page "Import status" header strip. Gated by
        // #[AuthorizedAdminSetting(Application::class)].
            ['name' => 'fxRateAdmin#status', 'url' => '/api/admin/fx-rate-import-status', 'verb' => 'GET'],

        // integration-config-to-openconnector (formerly Shillinq W8):
        // read-only admin roster over the 15 dormant external-API
        // adapter families (Digipoort/SBR, Salarisbureau, RvO, IB47,
        // CBS x2, BZK SiSa, Mollie, Bunq, KvK, UWV, Treasury Rates,
        // CCM Rule Engine, CSRD ESRS XBRL, DepositPayment). Drives the
        // single ExternalAdaptersStatus.vue roster page — the 15
        // per-adapter detail pages (and their #show deep-link target)
        // are gone, so #show was removed as dead code (no browser
        // caller left; ORCHESTRATOR RULING: dead surface once the
        // per-adapter pages go). Gated by
        // #[AuthorizedAdminSetting(Application::class)] — the
        // per-row activation recipe reveals configuration keys which
        // are admin-only data.
            ['name' => 'externalAdaptersAdmin#index', 'url' => '/api/admin/external-adapters', 'verb' => 'GET'],

        // Booking notification trigger configuration (organizer, per booking).
            ['name' => 'bookingNotification#getBookingTriggers',    'url' => '/api/bookings/{id}/notification-triggers', 'verb' => 'GET'],
            ['name' => 'bookingNotification#updateBookingTriggers', 'url' => '/api/bookings/{id}/notification-triggers', 'verb' => 'PATCH'],

        // Admin notification monitor dashboard.
            ['name' => 'bookingNotification#getNotificationMonitor', 'url' => '/api/admin/notification-monitor', 'verb' => 'GET'],
            ['name' => 'bookingNotification#disableAllTriggers',     'url' => '/api/admin/notification-monitor/disable-all', 'verb' => 'POST'],

        // Trial balance (Tier 2): read-only per-account period aggregation.
            ['name' => 'trialBalance#index', 'url' => '/api/trial-balance', 'verb' => 'GET'],

        // Financial overview dashboard (Wave-4 endpoint-bound widgets):
        // read-only monthly series + KPI summary computed server-side over
        // ALL matching OpenRegister objects (no client-side 2000-row cap).
        // Both #[NoAdminRequired]; RBAC/multitenancy enforced by OR reads.
            ['name' => 'financialDashboard#series', 'url' => '/api/dashboard/financial-series', 'verb' => 'GET'],
            ['name' => 'financialDashboard#summary', 'url' => '/api/dashboard/financial-summary', 'verb' => 'GET'],

        // Spend analytics (spend-analytics): single-dimension spend analysis
        // (by supplier / category / cost-centre / period) computed server-side
        // by CONSUMING OpenRegister's aggregation-api (runAdhocByRef, ADR-022).
        // #[NoAdminRequired]; RBAC/multitenancy enforced by OR aggregation.
            ['name' => 'spendAnalytics#spend', 'url' => '/api/analytics/spend', 'verb' => 'GET'],

        // Budget charts (budget-charts, REQ-BCH-003): actual/projected/begroot
        // trend+cumulative series for every in-scope Account/LedgerGroup in one
        // administration, composed from budget-core-schema's
        // BudgetVsActualsReader/Calculator and budget-projection-engine's
        // BudgetProjectionReader/Calculator. #[NoAdminRequired]; per-administration
        // membership enforced by AdministrationContextService::canAccess()
        // (masked 404), mirroring spendAnalytics#spend's own posture.
            ['name' => 'budgetCharts#series', 'url' => '/api/budget-charts/series', 'verb' => 'GET'],

        // Credit control & dunning ladder (Tier 2 — issue #124).
        // Static segments first; the resume route uses a {pauseId} wildcard.
            ['name' => 'dunning#bik', 'url' => '/api/dunning/bik', 'verb' => 'POST'],
            ['name' => 'dunning#executeRun', 'url' => '/api/dunning/runs/execute', 'verb' => 'POST'],
            ['name' => 'dunning#pause', 'url' => '/api/dunning/pauses', 'verb' => 'POST'],
            ['name' => 'dunning#dossier', 'url' => '/api/dunning/incasso/dossier', 'verb' => 'POST'],
            ['name' => 'dunning#transfer', 'url' => '/api/dunning/incasso/transfer', 'verb' => 'POST'],
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
            ['name' => 'administrationExport#exportXaf', 'url' => '/api/administrations/{id}/export', 'verb' => 'GET'],
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
        // Bookings-depth: no-show-fee capture + recurring appointment series.
        // Both are operator actions (#[NoAdminRequired] + per-administration
        // guard). no-show captures the defined noShowFee via the DepositPayment
        // provider rails; appointment-series expands an RRULE into individual
        // appointments (skipping availability/conflict violations).
            ['name' => 'bookingDepth#captureNoShow', 'url' => '/api/v1/appointments/{appointmentId}/no-show', 'verb' => 'POST'],
            ['name' => 'bookingDepth#createSeries', 'url' => '/api/v1/appointment-series', 'verb' => 'POST'],
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

        // Billing intake (time-expense-invoice-intake): authenticated ingress for
        // pipelinq's time-billing-handoff-emit change to POST a batch of approved
        // time entries and receive back a draft T&M BillableInvoice reference.
        // Unversioned per contract.md (billing-ingress convention, additive-only
        // response). #[NoAdminRequired] declared on the controller method.
            ['name' => 'billingIntake#timeIntake', 'url' => '/api/billing/time-intake', 'verb' => 'POST'],

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

        // Inventory accounting correctness (inventory-accounting-correctness).
        // valuation-report replays the immutable StockMove ledger to return the
        // stock value as-of a cut-off date (jaarrekening voorraadwaarde per 31-12),
        // optionally with a FIFO ageing breakdown. landed-cost capitalises a
        // receipt's freight + duties into unit cost and posts one balanced
        // GLTransaction; nrv-writedown applies lower-of-cost-or-NRV (RJ 220 / IAS 2.9)
        // and posts the balanced period-end adjustment. All #[NoAdminRequired] with
        // AdministrationContextService enforcing tenant IDOR (masked 404). Static
        // segments precede the SPA catch-all per ADR-016.
            ['name' => 'inventoryValuationReport#report', 'url' => '/api/inventory/valuation-report', 'verb' => 'GET'],
            ['name' => 'inventoryAdjustment#landedCost', 'url' => '/api/inventory/landed-cost', 'verb' => 'POST'],
            ['name' => 'inventoryAdjustment#nrvWriteDown', 'url' => '/api/inventory/nrv-writedown', 'verb' => 'POST'],

        // Purchase Order 3-way-match core (slice 02): server-authoritative create +
        // approval-chain preview + send-block guard. Static segments precede the
        // {id} wildcard so they are matched first (Symfony route ordering).
            ['name' => 'purchaseOrder#previewApprovalChain', 'url' => '/api/purchase-orders/approval-chain', 'verb' => 'GET'],
            ['name' => 'purchaseOrder#create', 'url' => '/api/purchase-orders', 'verb' => 'POST'],
            ['name' => 'purchaseOrder#send', 'url' => '/api/purchase-orders/{id}/send', 'verb' => 'POST'],

        // Purchase requisition (aanvraag) — server-authoritative create /
        // submit / approve / reject / convert-to-PO. Approval reuses
        // BudgetBlocker/MandateEnforcer from bookkeeping-verplichtingenadministratie
        // (no parallel approval system). Every endpoint is #[NoAdminRequired]
        // with a per-administration IDOR guard in the controller (ADR-005).
        // Static segments precede the {id} wildcard so Symfony's route
        // ordering matches them first.
            ['name' => 'requisition#create', 'url' => '/api/requisitions', 'verb' => 'POST'],
            ['name' => 'requisition#submit', 'url' => '/api/requisitions/{id}/submit', 'verb' => 'POST'],
            ['name' => 'requisition#approve', 'url' => '/api/requisitions/{id}/approve', 'verb' => 'POST'],
            ['name' => 'requisition#reject', 'url' => '/api/requisitions/{id}/reject', 'verb' => 'POST'],
            ['name' => 'requisition#convert', 'url' => '/api/requisitions/{id}/convert', 'verb' => 'POST'],

        // Purchase Order 3-way-match slice 03 — Peppol transmission + PDF/email
        // fallback. Static segments precede the {id} wildcard so they are matched
        // first (Symfony route ordering).
            ['name' => 'purchaseOrder#transmitPeppol', 'url' => '/api/purchase-orders/{id}/transmit/peppol', 'verb' => 'POST'],
            ['name' => 'purchaseOrder#transmitEmail', 'url' => '/api/purchase-orders/{id}/transmit/email', 'verb' => 'POST'],

        // Add-invoice-pdf-export-with-ubl-peppol-support — AR outbound e-invoicing
        // (REQ-EINV-005). Server-authoritative Send e-invoice action on an issued
        // ARInvoice: pre-send validation, NLCIUS UBL 2.1 + PDF/A-3 hybrid, Peppol
        // submit via the generalised transmission port, delivery-status queued.
            ['name' => 'aRInvoiceEInvoice#send', 'url' => '/api/ar-invoices/{invoiceNumber}/send-einvoice', 'verb' => 'POST'],

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

        // Service Receipt / prestatieverklaring (member 12 of
        // bookkeeping-purchase-order-3way, REQ-PO3W-011): the service-PO
        // alternative to Goods Receipt Note — server-authoritative create /
        // add-line / confirm / accept endpoints. accept() recomputes the
        // originating PO(s) receipt lifecycle exactly as acceptGRN() does,
        // minus the StockMove posting (services never move inventory).
        // Every endpoint is #[NoAdminRequired] with a per-administration IDOR
        // guard inside the controller (ADR-005). Static path segments precede
        // the {id} wildcard so Symfony's route ordering matches them first.
            ['name' => 'serviceReceipt#create', 'url' => '/api/service-receipts', 'verb' => 'POST'],
            ['name' => 'serviceReceipt#addLine', 'url' => '/api/service-receipts/{id}/lines', 'verb' => 'POST'],
            ['name' => 'serviceReceipt#confirm', 'url' => '/api/service-receipts/{id}/confirm', 'verb' => 'POST'],
            ['name' => 'serviceReceipt#accept', 'url' => '/api/service-receipts/{id}/accept', 'verb' => 'POST'],

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

        // Bookkeeping-purchase-order-3way slice 08 (REQ-PO3W-005) — the
        // three resolution dispositions of the exception workflow. Every
        // endpoint is #[NoAdminRequired] with a per-administration IDOR
        // guard in the controller (ADR-005). Static segments only — no
        // path wildcards — so Symfony route ordering vs. the SPA
        // catch-all is not at risk.
            ['name' => 'threeWayMatchException#accept', 'url' => '/api/three-way-match/exceptions/accept', 'verb' => 'POST'],
            ['name' => 'threeWayMatchException#dispute', 'url' => '/api/three-way-match/exceptions/dispute', 'verb' => 'POST'],
            ['name' => 'threeWayMatchException#reject', 'url' => '/api/three-way-match/exceptions/reject', 'verb' => 'POST'],

        // Bookkeeping-purchase-order-3way slice 11 (REQ-PO3W-010) — the
        // audit-trail export endpoints (lifecycle ledger + ZIP package)
        // and the approval-decision endpoint that records approver
        // identity + timestamp on the PurchaseOrder approval chain.
        // Every endpoint is #[NoAdminRequired] with a per-administration
        // IDOR guard in the controller (ADR-005). Static segments only —
        // no path wildcards — so Symfony route ordering vs. the SPA
        // catch-all is not at risk.
            ['name' => 'threeWayMatchAudit#ledger', 'url' => '/api/three-way-match/audit-trail', 'verb' => 'GET'],
            ['name' => 'threeWayMatchAudit#export', 'url' => '/api/three-way-match/audit-trail/export', 'verb' => 'POST'],
        // Revive-gl-tax-capabilities REQ-GLTAX-003 (shillinq#424) — the
        // GR/IR period-end reconciliation control. GRIRClearingService's
        // two POSTING methods were wired by grir-accrual-wiring, but
        // reconcileGRIRSaldoForPeriod() had no route, no controller and no
        // CLI command, so an operator could not run the period-end check
        // REQ-PO3W-009 requires. #[NoAdminRequired] with a
        // per-administration IDOR guard in the controller (ADR-005).
            ['name' => 'gRIRReconciliation#saldo', 'url' => '/api/gr-ir/saldo', 'verb' => 'GET'],
        // Bookkeeping-rekenkamer-audit-pack REQ-RAP-005 — RBAC-scoped
        // compliance export over the OR audit-trail (CSV / JSON;
        // PII fields stripped; auditor / admin group only; the
        // export request itself is recorded in the audit-trail).
            ['name' => 'complianceExport#export', 'url' => '/api/audit/export', 'verb' => 'GET'],
            ['name' => 'purchaseOrderApproval#decide', 'url' => '/api/purchase-orders/{id}/approval-decision', 'verb' => 'POST'],

        // Bookkeeping-waterschappen-bbv-variant slice 04 — JSON envelopes for
        // the waterschappen BBV chain: the compliance dashboard envelope that
        // member 05 (dashboard widgets) reads and the Budget Mapping
        // index/detail envelopes that members 06/07 (mapping UI) read. Every
        // endpoint is #[NoAdminRequired] in the controller; mutating writes go
        // through OpenRegister's object endpoints (admin-write per slice 01
        // permissions) so no per-object IDOR surface is introduced here.
        //
        // ⚠️ THESE MUST STAY UNDER `/api/`. Slice 04 originally registered them
        // at `/bbv-dashboard`, `/budget-mappings` and `/budget-mappings/{id}` —
        // the very paths its OWN manifest fragment
        // (src/manifest.d/bookkeeping-waterschappen-bbv-variant-04-manifest-routes.json)
        // declares as SPA *page* routes. An app route always wins over the SPA
        // catch-all, so all three pages were served by a JSONResponse
        // controller instead of the app shell; and because a page controller
        // returning JSON carries no `#[NoCSRFRequired]`, a browser NAVIGATION
        // (which sends no requesttoken header, unlike axios) was rejected by
        // SecurityMiddleware. Users clicking "BBV dashboard" or "Budget
        // mappings" in the navigation got Nextcloud's "Access forbidden — CSRF
        // check failed" page. The whole waterschappen BBV feature was
        // unreachable in a browser while the JSON API it fetches answered 200,
        // so nothing server-side looked broken.
        //
        // It stayed invisible because `tests/e2e/waterschappen-bbv-routes-smoke.spec.ts`
        // asserted `expect([200, 302, 401, 412].includes(status))` and hid every
        // envelope check behind `if (status === 200)` — an assertion that
        // accepts the failure it exists to catch. That spec now asserts 200
        // unconditionally AND navigates the three page routes.
        //
        // Keeping the data endpoints under `/api/` (as every other route in
        // this file already is) leaves the three manifest page routes free for
        // the SPA. Routes are registered only in appinfo/routes.php (ADR-016).
            ['name' => 'bBVDashboard#index', 'url' => '/api/bbv-dashboard', 'verb' => 'GET'],
            ['name' => 'budgetBBVMapping#index', 'url' => '/api/budget-mappings', 'verb' => 'GET'],
            ['name' => 'budgetBBVMapping#show', 'url' => '/api/budget-mappings/{id}', 'verb' => 'GET'],

        // Deposit payment webhook (bookings-deposits, REQ-DP-006). Public route
        // (gateways are unauthenticated callers) but signature-gated inside the
        // controller. The {gateway} placeholder selects mollie / stripe.
            ['name' => 'depositWebhook#handle', 'url' => '/api/webhooks/deposits/{gateway}', 'verb' => 'POST'],

        // Payroll webhook (bookkeeping-detachering-payroll-administratie,
        // REQ-PAY-009). Public, signature-gated. info() is a 501 GET for callers
        // probing the endpoint; receive() is the signed POST receiver.
            ['name' => 'payrollWebhook#info', 'url' => '/api/webhooks/payroll', 'verb' => 'GET'],
            ['name' => 'payrollWebhook#receive', 'url' => '/api/webhooks/payroll', 'verb' => 'POST'],

        // SEPA mandate audit-trail export (bookkeeping-sepa-direct-debit,
        // REQ-SDD-010). Returns a per-mandate ZIP dossier scoped server-side
        // to the caller's accessible administrations (IDOR-safe).
            ['name' => 'sepaAudit#exportMandate', 'url' => '/api/sepa/mandates/{mandateId}/audit-export', 'verb' => 'GET'],

        // 13-week cashflow PDF export (zzp-cashflow-13wk, REQ-CF-016 / #865).
        // POST because the Cashflow Dashboard's declarative `headerActions[]`
        // api-call action issues a POST through @nextcloud/axios, which carries
        // the request token — so the endpoint keeps CSRF protection rather than
        // opting out of it. It takes NO parameters: the horizon is resolved
        // server-side from the caller's AdministrationMembership set, so no
        // caller-supplied object identifier crosses the boundary.
            ['name' => 'cashflowExport#exportPdf', 'url' => '/api/cashflow/export-pdf', 'verb' => 'POST'],

        // Provincies-BBV dashboards (bookkeeping-provincies-bbv-variant,
        // REQ-BBC-001..003 / REQ-BBL-001 — #866/#862). Two read-only GETs the
        // manifest's declarative `endpointSource` widgets bind to. Like the
        // waterschappen endpoints above they MUST stay under `/api/`: the SPA
        // page routes `/bbv-provincie/compliance-dashboard` and
        // `/bbv-provincie/budget-to-programme` are declared by the same
        // manifest fragment, and an app route always beats the SPA catch-all.
        //
        // Neither takes an object identifier — the administration scope is
        // resolved server-side from the caller's AdministrationMembership set
        // (REQ-MA-001), so there is no per-object IDOR surface. The only
        // inputs are the three REQ-BBC-002 value filters, validated against a
        // closed vocabulary in the controller.
            ['name' => 'bbvProgrammeBudget#programmeBudgetVsActuals', 'url' => '/api/bbv-provincie/programme-budget-vs-actuals', 'verb' => 'GET'],
            ['name' => 'bbvProgrammeBudget#glLineFacets', 'url' => '/api/bbv-provincie/gl-line-facets', 'verb' => 'GET'],

        // Read-only inventory product catalog (inventory-product-catalog
        // REQ-IPC-008 / shillinq-product-vendor-to-pipelinq REQ-SPVP-004, #860).
        // The product master itself lives in pipelinq; these two GETs serve the
        // catalog projection shillinq renders over it, with the integration
        // contract's declared local-cache fallback. Deliberately GET-only:
        // REQ-SPVP-004 forbids any shillinq surface that accepts a product
        // definition, so there is no create/update/delete counterpart. Both take
        // no parameters — scope comes from the caller's AdministrationMembership
        // set, so nothing caller-supplied crosses the boundary.
            ['name' => 'productCatalog#products', 'url' => '/api/inventory/products', 'verb' => 'GET'],
            ['name' => 'productCatalog#productAttributes', 'url' => '/api/inventory/product-attributes', 'verb' => 'GET'],

        // BCF compensation calculator (bookkeeping-bcf-claim). Pure compute
        // surface returning a what-if compensation result.
            ['name' => 'bcfClaim#compensation', 'url' => '/api/bcf/compensation', 'verb' => 'GET'],

        // Bookkeeping-reconciliation-reports (T4) — REQ-REC-004 unmatched-item
        // resolution. POST one classification + reason at a time, or bulk-apply
        // across a list of matchIds (REQ-REC-008 Unmatched Items bulk workflow).
        // Both endpoints are #[NoAdminRequired] but IDOR-guarded: the service
        // verifies match.reconId matches the path reconId before writing.
            [
                'name' => 'reconciliationResolution#resolve',
                'url'  => '/api/reconciliations/{reconId}/matches/{matchId}/resolve',
                'verb' => 'POST',
            ],
            [
                'name' => 'reconciliationResolution#bulkResolve',
                'url'  => '/api/reconciliations/{reconId}/matches/bulk-resolve',
                'verb' => 'POST',
            ],

            // ICP opgaaf (bookkeeping-icp-opgaaf, REQ-ICP-002..010). Read-only ledger
            // / reconcile / periodicity / VIES lookup endpoints plus the correction
            // + audit-export write surface. All admin-scoped + IDOR-safe.
            ['name' => 'icp#ledger', 'url' => '/api/icp/ledger', 'verb' => 'GET'],
            ['name' => 'icp#reconcile', 'url' => '/api/icp/reconcile', 'verb' => 'GET'],
            ['name' => 'icp#periodicity', 'url' => '/api/icp/periodicity', 'verb' => 'GET'],
            ['name' => 'icp#lookupVatId', 'url' => '/api/icp/vat-id-lookup', 'verb' => 'POST'],
            ['name' => 'icp#correction', 'url' => '/api/icp/correction', 'verb' => 'POST'],
            ['name' => 'icp#auditExport', 'url' => '/api/icp/audit-export', 'verb' => 'GET'],
            ['name' => 'icp#renderInvoicePdf', 'url' => '/api/icp/invoice-pdf', 'verb' => 'GET'],

            // Aansluiting (tie-out) framework (bookkeeping-aansluitingen,
            // REQ-AANS-004, REQ-AANS-006). compute() resolves source A / source B
            // per the Aansluiting definition's aansluitingType and persists an
            // AansluitingResult; explain()/resolve()/reopen() drive its
            // open -> explained -> resolved lifecycle. All #[NoAdminRequired] +
            // IDOR-safe (each write re-fetches the target record server-side).
            [
                'name' => 'aansluiting#compute',
                'url'  => '/api/aansluitingen/{aansluitingId}/compute',
                'verb' => 'POST',
            ],
            [
                'name' => 'aansluiting#explain',
                'url'  => '/api/aansluitingen/results/{resultId}/explain',
                'verb' => 'POST',
            ],
            [
                'name' => 'aansluiting#resolve',
                'url'  => '/api/aansluitingen/results/{resultId}/resolve',
                'verb' => 'POST',
            ],
            [
                'name' => 'aansluiting#reopen',
                'url'  => '/api/aansluitingen/results/{resultId}/reopen',
                'verb' => 'POST',
            ],

            // IFRS 16 lease accounting (bookkeeping-ifrs-16-lease, REQ-LA-002,
            // REQ-LD-001). Per-contract amortization schedule + per-period
            // disclosure table. Read-only.
            ['name' => 'lease#schedule', 'url' => '/api/leases/schedule', 'verb' => 'GET'],
            ['name' => 'lease#disclosure', 'url' => '/api/leases/disclosure', 'verb' => 'GET'],

            // IFRS 16 lease remeasurement write surface (revive-lease-capabilities,
            // shillinq#446, REQ-LR-001..REQ-LR-004). Records indexation,
            // extension-option, modification and impairment events; each posts a
            // balanced RoU / lease-liability remeasurement.
            ['name' => 'leaseReassessment#indexation', 'url' => '/api/leases/reassessment/indexation', 'verb' => 'POST'],
            ['name' => 'leaseReassessment#extensionOption', 'url' => '/api/leases/reassessment/extension-option', 'verb' => 'POST'],
            ['name' => 'leaseReassessment#modification', 'url' => '/api/leases/reassessment/modification', 'verb' => 'POST'],
            ['name' => 'leaseReassessment#impairment', 'url' => '/api/leases/reassessment/impairment', 'verb' => 'POST'],

            // IFRS 15 revenue cut-off (bookkeeping-ifrs15-revenue, REQ-IFRS15-007/008).
            ['name' => 'revenue#cutoff', 'url' => '/api/revenue/cutoff', 'verb' => 'GET'],

            // Recognized recurring revenue (order-revenue-recognition-engine). Read-only
            // period-parameterized recognition over SalesOrder/SalesOrderLine: recognized
            // RECURRING revenue (whole-month overlap × frequency-normalized monthly rate),
            // ARR run-rate, currency and recurring line count. #[NoAdminRequired] with a
            // per-administrationId RBAC guard in the controller; reads are scoped via
            // OpenRegister's ObjectService so no cross-administration leak (ADR-005).
            ['name' => 'recognition#recurringRevenue', 'url' => '/api/recognition/recurring-revenue', 'verb' => 'GET'],

            // KOR drempel-bewaking (bookkeeping-kor-kleine-ondernemersregeling,
            // REQ-KOR-002, REQ-KOR-003). Read-only monitor endpoint.
            ['name' => 'kor#monitor', 'url' => '/api/kor/monitor', 'verb' => 'GET'],

            // Vpb corporate-tax statements (bookkeeping-vpb-corporate-tax,
            // REQ-VPB-009, REQ-VPB-012). Specific quarter/{quarter} segment
            // precedes the bare year route per Symfony route ordering.
            ['name' => 'taxReport#quarter', 'url' => '/api/tax-reports/{year}/{quarter}', 'verb' => 'GET'],
            ['name' => 'taxReport#annual', 'url' => '/api/tax-reports/{year}', 'verb' => 'GET'],

            // Vpb tax-payment reconciliation (bookkeeping-vpb-corporate-tax,
            // REQ-VPB-008). Per-payment {id} reconciliation against the GL.
            ['name' => 'taxPayment#reconcile', 'url' => '/api/tax-payments/{id}/reconcile', 'verb' => 'POST'],

            // Period close (bookkeeping-period-close, REQ-PC-002..008). Detail,
            // AI-flags, and the four lifecycle transitions (start, close, reopen,
            // audit-lock). The specific {action} segments precede the bare {periodId}
            // segment per Symfony route ordering.
            ['name' => 'periodClose#aiFlags', 'url' => '/api/period-close/{periodId}/ai-flags', 'verb' => 'GET'],
            ['name' => 'periodClose#startClose', 'url' => '/api/period-close/{periodId}/start-close', 'verb' => 'POST'],
            ['name' => 'periodClose#close', 'url' => '/api/period-close/{periodId}/close', 'verb' => 'POST'],
            ['name' => 'periodClose#reopen', 'url' => '/api/period-close/{periodId}/reopen', 'verb' => 'POST'],
            ['name' => 'periodClose#lockAudit', 'url' => '/api/period-close/{periodId}/lock-audit', 'verb' => 'POST'],
            ['name' => 'periodClose#show', 'url' => '/api/period-close/{periodId}', 'verb' => 'GET'],

            // Budget scenarios (budget-scenarios, REQ-BSC-002). isDefault is set
            // exclusively via this endpoint (a service call, atomic demotion of
            // the previous default) — never an x-openregister-lifecycle
            // transition (BudgetScenarioDefaultPromoter's own docblock).
            ['name' => 'budgetScenario#promote', 'url' => '/api/v1/budget-scenarios/{scenarioId}/promote', 'verb' => 'POST'],
            ['name' => 'budgetScenario#evaluate', 'url' => '/api/v1/budget-scenarios/{scenarioId}/evaluate', 'verb' => 'GET'],

            // Continuous close + flux analysis (bookkeeping-soft-close-flux,
            // REQ-CLS-002, REQ-CLS-005, REQ-CLS-007). On-demand soft-close trigger
            // per administratie + on-demand flux run + flux narrative export
            // (PDF / Markdown / JSON). All three are #[NoAdminRequired] with
            // server-side admin-id validation; writes are role-gated inside the
            // services and PeriodStatusGuard.
            ['name' => 'softClose#executeNow', 'url' => '/api/v2/soft-close/{administrationId}/execute-now', 'verb' => 'POST'],
            ['name' => 'softClose#executeFlux', 'url' => '/api/v2/flux-runs/execute', 'verb' => 'POST'],
            ['name' => 'softClose#narrative', 'url' => '/api/v2/flux-runs/{fluxRunId}/narrative', 'verb' => 'GET'],

            // Innovatiebox administratie (bookkeeping-innovatiebox-administratie,
            // REQ-IBA-004/006/009). Aggregation + scenario + doorsnijdingsverbod
            // year-end check. Read-only.
            ['name' => 'innovatiebox#aggregation', 'url' => '/api/innovatiebox/aggregation', 'verb' => 'GET'],
            ['name' => 'innovatiebox#scenario', 'url' => '/api/innovatiebox/scenario', 'verb' => 'GET'],
            ['name' => 'innovatiebox#doorsnijdingsverbod', 'url' => '/api/innovatiebox/doorsnijdingsverbod', 'verb' => 'GET'],
            ['name' => 'innovatiebox#export', 'url' => '/api/innovatiebox/export', 'verb' => 'GET'],

            // Programmabegroting exports (bookkeeping-programmabegroting,
            // REQ-011, REQ-012). Sluitend-status + iv3 + JSON exports.
            ['name' => 'programmabegroting#sluitend', 'url' => '/api/programmabegroting/sluitend', 'verb' => 'GET'],
            ['name' => 'programmabegroting#iv3', 'url' => '/api/programmabegroting/export/iv3', 'verb' => 'GET'],
            ['name' => 'programmabegroting#jsonExport', 'url' => '/api/programmabegroting/export/json', 'verb' => 'GET'],

            // BADO controleprotocol aggregation (bookkeeping-bado-controleprotocol).
            ['name' => 'badoControleprotocol#aggregation', 'url' => '/api/bado/controleprotocol/aggregation', 'verb' => 'GET'],
            // BADO accountantsdossier export (bookkeeping-bado-controleprotocol, REQ-010, Task 16).
            ['name' => 'badoControleprotocol#exportAccountantsdossier', 'url' => '/api/bado/controleprotocol/accountantsdossier', 'verb' => 'GET'],

            // CBS bestanden extended (bookkeeping-cbs-bestanden-extended,
            // REQ-CBS-001..009). RESTful CBSSubmission CRUD + generate endpoint.
            // Static segments precede the {id} wildcard per Symfony route ordering.
            ['name' => 'cBSSubmission#index', 'url' => '/api/cbs-submissions', 'verb' => 'GET'],
            ['name' => 'cBSSubmission#create', 'url' => '/api/cbs-submissions', 'verb' => 'POST'],
            ['name' => 'cBSSubmission#generate', 'url' => '/api/cbs-submissions/{id}/generate', 'verb' => 'POST'],
            ['name' => 'cBSSubmission#show', 'url' => '/api/cbs-submissions/{id}', 'verb' => 'GET'],
            ['name' => 'cBSSubmission#update', 'url' => '/api/cbs-submissions/{id}', 'verb' => 'PUT'],
            ['name' => 'cBSSubmission#destroy', 'url' => '/api/cbs-submissions/{id}', 'verb' => 'DELETE'],

            // Widget API-key admin (bookings-self-service-widget, REQ-WSW-009).
            // #[AuthorizedAdminSetting]-gated lifecycle of per-business widget keys.
            // `create` mints the FIRST key for a businessId; `rotate` replaces an
            // existing one and refuses when there is none, so without `create` no
            // business could ever be issued a key at all.
            ['name' => 'widgetSettings#create', 'url' => '/api/widget/admin/keys/create', 'verb' => 'POST'],
            ['name' => 'widgetSettings#rotate', 'url' => '/api/widget/admin/keys/rotate', 'verb' => 'POST'],
            ['name' => 'widgetSettings#revoke', 'url' => '/api/widget/admin/keys/revoke', 'verb' => 'POST'],

            // Inventory scanner server-authoritative API (inventory-mobile-scanner,
            // REQ-SKU-001/002, REQ-OFFLINE-002/003, REQ-PERM-001). The
            // InventoryMobileScannerController above mounts the original
            // /api/v1/inventory/* surface used by the PWA; this controller exposes
            // a parallel /api/inventory/* surface that performs role-gated batch
            // application + barcode resolution server-side. Static segments only.
            ['name' => 'inventoryScan#resolve', 'url' => '/api/inventory/resolve', 'verb' => 'GET'],
            ['name' => 'inventoryScan#sync', 'url' => '/api/inventory/sync', 'verb' => 'GET'],
            ['name' => 'inventoryScan#scan', 'url' => '/api/inventory/scan', 'verb' => 'POST'],

            // Bookkeeping-wbso-sno-administratie REQ-WBSO-001/002/003/005/006/007/008/009 —
            // server-authoritative REST surface for Account / Transaction / Document
            // registers. Static segments first; the {accountNumber}/{id} wildcards are
            // last per Symfony route ordering. Every endpoint is #[NoAdminRequired] and
            // authentication + role gating happens in the controller body.
            ['name' => 'wbsoAccountApi#hierarchy', 'url' => '/api/v1/accounts/hierarchy', 'verb' => 'GET'],

            // budget-grid-view REQ-BGV-001/002/003 — the begroting grid's own
            // single read endpoint. One request returns the whole tree +
            // column set pre-computed (design.md §1c: expand/collapse must
            // cost zero further requests).
            ['name' => 'budgetGrid#index', 'url' => '/api/budget-grid', 'verb' => 'GET'],
            ['name' => 'wbsoAccountApi#index', 'url' => '/api/v1/accounts', 'verb' => 'GET'],
            ['name' => 'wbsoAccountApi#create', 'url' => '/api/v1/accounts', 'verb' => 'POST'],
            ['name' => 'wbsoAccountApi#show', 'url' => '/api/v1/accounts/{accountNumber}', 'verb' => 'GET'],
            ['name' => 'wbsoAccountApi#update', 'url' => '/api/v1/accounts/{accountNumber}', 'verb' => 'PUT'],

            ['name' => 'wbsoTransactionApi#index', 'url' => '/api/v1/transactions', 'verb' => 'GET'],
            ['name' => 'wbsoTransactionApi#create', 'url' => '/api/v1/transactions', 'verb' => 'POST'],
            ['name' => 'wbsoTransactionApi#post', 'url' => '/api/v1/transactions/{id}/post', 'verb' => 'POST'],
            ['name' => 'wbsoTransactionApi#reverse', 'url' => '/api/v1/transactions/{id}/reverse', 'verb' => 'POST'],
            ['name' => 'wbsoTransactionApi#show', 'url' => '/api/v1/transactions/{id}', 'verb' => 'GET'],

            ['name' => 'wbsoDocumentApi#index', 'url' => '/api/v1/documents', 'verb' => 'GET'],
            ['name' => 'wbsoDocumentApi#create', 'url' => '/api/v1/documents', 'verb' => 'POST'],
            ['name' => 'wbsoDocumentApi#file', 'url' => '/api/v1/documents/{id}/file', 'verb' => 'POST'],
            ['name' => 'wbsoDocumentApi#archive', 'url' => '/api/v1/documents/{id}/archive', 'verb' => 'POST'],
            ['name' => 'wbsoDocumentApi#show', 'url' => '/api/v1/documents/{id}', 'verb' => 'GET'],

            // WBSO realisatie summary endpoint (REQ-WBSO-010) — read-only per-beschikking
            // granted-vs-realised S&O hours; scoped to one administration via query param.
            ['name' => 'wbsoAdministratie#realisatie', 'url' => '/api/wbso/realisatie', 'verb' => 'GET'],

            // DBA compliance marker endpoints (dba-compliance-marker, T19/T17/T21/T22/T23/T25/T26/T27/T28).
            // All marked NoAdminRequired; per-object IDOR check is in the controller body
            // (ensureAdministrationAccess) per ADR-005.
            ['name' => 'dBA#scoreIntake', 'url' => '/api/dba/intake/score', 'verb' => 'POST'],
            ['name' => 'dBA#saveIntake', 'url' => '/api/dba/intake', 'verb' => 'POST'],
            ['name' => 'dBA#vbarCheck', 'url' => '/api/dba/vbar/check', 'verb' => 'POST'],
            ['name' => 'dBA#uploadWba', 'url' => '/api/dba/wba/upload', 'verb' => 'POST'],
            ['name' => 'dBA#beeindigen', 'url' => '/api/dba/beeindiging', 'verb' => 'POST'],
            ['name' => 'dBA#setMode', 'url' => '/api/dba/mode', 'verb' => 'POST'],
            ['name' => 'dBA#setTussenkomstMode', 'url' => '/api/dba/tussenkomst', 'verb' => 'POST'],
            ['name' => 'dBA#evidenceConsent', 'url' => '/api/dba/evidence/consent', 'verb' => 'POST'],
            ['name' => 'dBA#inhuurIntake', 'url' => '/api/dba/inhuur-intake', 'verb' => 'POST'],
            ['name' => 'dBA#auditReport', 'url' => '/api/dba/audit-report/{opdrachtId}', 'verb' => 'GET'],

            // Supplier invoice import modal (shillinq-bill-import-modal,
            // REQ-BIM-001 / REQ-BIM-004). Accepts a multipart `file` upload
            // (or JSON contents+format) and ingests a UBL/e-invoice XML or CSV
            // supplier invoice through the deterministic SupplierInvoiceService
            // (no OCR). PDF uploads are honestly deferred (HTTP 422,
            // deferred: pdf-ocr) — no OCR engine is bundled with this change.
            // #[NoAdminRequired]; the administration is resolved server-side
            // (ADR-005 IDOR-safe). Declared before the SPA catch-all so
            // Symfony route ordering matches it first per ADR-016.
            ['name' => 'supplierInvoiceImport#import', 'url' => '/api/v1/supplier-invoices/import', 'verb' => 'POST'],

            // Receipt-extraction-consume (REQ-RXC-004 / REQ-RXC-005) — thin proxy
            // so the frontend never needs docudesk credentials directly. request()
            // forwards a (re-)extraction request to docudesk; the resulting
            // nl.conduction.docudesk.extraction.completed event flows back through
            // ExtractionCompletedListener. confirm() records an operator
            // correction on an existing extraction draft. Both #[NoAdminRequired],
            // IDOR-guarded server-side (ADR-005). Declared before the SPA
            // catch-all per ADR-016.
            ['name' => 'extractionRequest#request', 'url' => '/api/v1/extraction/request', 'verb' => 'POST'],
            ['name' => 'extractionRequest#confirm', 'url' => '/api/v1/extraction/drafts/{id}', 'verb' => 'PUT'],

            // Gl-account-suggestion-consume (REQ-GAC-003 / REQ-GAC-006) — proxies
            // a GL-account suggestion request to docudesk's already-shipped
            // ai-gl-account-suggestion endpoint, supplying shillinq's own chart
            // of accounts as candidates. #[NoAdminRequired], IDOR-guarded
            // server-side (ADR-005). Declared before the SPA catch-all per
            // ADR-016.
            ['name' => 'extractionRequest#suggestGlAccount', 'url' => '/api/v1/extraction/drafts/{id}/suggest-account', 'verb' => 'POST'],

            // Bank statement import (shillinq-bank-statement-wizard, REQ-BSW-004).
            // The real import endpoint behind the BankStatementWizard: accepts a
            // CAMT.053 / MT940 / CSV file (multipart upload or JSON body), parses it
            // with StatementParser, and creates one BankStatement + one
            // BankStatementLine per parsed line scoped to the server-resolved
            // administration. #[NoAdminRequired] with the administration resolved
            // server-side (IDOR-safe per ADR-005). Static segment only — declared
            // before the SPA catch-all so Symfony matches it first per ADR-016.
            ['name' => 'bankStatementImport#import', 'url' => '/api/v1/bank-statements/import', 'verb' => 'POST'],

            // Bank matching-rule preview + learning (bank-rule-automation-ux,
            // REQ-BR-011 / REQ-BR-012). preview dry-runs an unsaved rule against
            // recent unmatched lines (read-only, no ReconciliationMatch written);
            // suggest-account returns the GL account of the highest-priority active
            // rule matching one line; suggestions returns history-based proposals;
            // suggestions/accept is the ONLY write — persists an accepted proposal
            // as a MatchingRule. #[NoAdminRequired] with the administration resolved
            // server-side (IDOR-safe per ADR-005). Static segments only — declared
            // before the SPA catch-all so Symfony matches them first per ADR-016.
            ['name' => 'bankRule#preview', 'url' => '/api/v1/bank-rules/preview', 'verb' => 'POST'],
            ['name' => 'bankRule#suggestAccount', 'url' => '/api/v1/bank-rules/suggest-account', 'verb' => 'POST'],
            ['name' => 'bankRule#suggestions', 'url' => '/api/v1/bank-rules/suggestions', 'verb' => 'GET'],
            ['name' => 'bankRule#acceptSuggestion', 'url' => '/api/v1/bank-rules/suggestions/accept', 'verb' => 'POST'],

            // AR invoice payment-request webhook (ar-invoice-payment-links, REQ-APL-004).
            // The GENERALIZED, shared payment webhook surface: one route, one
            // signature-verification implementation, one PaymentReconciliationService,
            // serving BOTH PaymentRequest (AR invoice payment links) AND DepositPayment
            // (booking deposits) — never a fork. Public route (gateways are
            // unauthenticated callers) but signature-gated inside the controller
            // (#[PublicPage] + HMAC over the raw body, fail-closed). The {gateway}
            // placeholder selects mollie / stripe. Declared before the SPA catch-all so
            // Symfony matches it first per ADR-016.
            ['name' => 'paymentRequestWebhook#handle', 'url' => '/api/v1/payment-requests/webhook/{gateway}', 'verb' => 'POST'],

            // Portal payment initiation (portal-payment-initiation, ADR-046 contract
            // v2 A6). Receives portaliq's server-to-server forward of the `pay`
            // endpoint-forward action declared on the customer manifest
            // (PortalContributionProvider). #[PublicPage] because the caller is
            // portaliq's backend, not a browser — the X-Portal-Subject assertion IS
            // the authentication (PortalAssertionVerifier). Static URL, declared
            // before the SPA catch-all per ADR-016.
            ['name' => 'portalPaymentInitiation#initiate', 'url' => '/api/portal/payments/initiate', 'verb' => 'POST'],

            // Reporting & Compliance consolidation (reporting-compliance-consolidation).
            // The HTTP surface behind the unified "Reporting & Compliance" section:
            // types() returns the static report catalogue grouped by category (overview
            // cards); generate() renders a chosen report and stores + tags + records it
            // via ReportGenerationService; generated() lists the recorded GeneratedReport
            // objects; download({id}) streams a stored report file. Every endpoint is
            // #[NoAdminRequired] with an explicit anonymous-rejection guard in the
            // controller (ADR-005); file access is scoped to the caller's Files home.
            // Static segments precede the {id} wildcard, and all are declared before the
            // SPA catch-all so Symfony matches them first per ADR-016.
            ['name' => 'reporting#types', 'url' => '/api/reporting/types', 'verb' => 'GET'],
            ['name' => 'reporting#generate', 'url' => '/api/reporting/generate', 'verb' => 'POST'],
            ['name' => 'reporting#generated', 'url' => '/api/reporting/generated', 'verb' => 'GET'],
            ['name' => 'reporting#download', 'url' => '/api/reporting/download/{id}', 'verb' => 'GET'],

            // Payment-run-sepa-export (REQ-SEPA-006 / REQ-SEPA-007). The HTTP surface
            // behind the "Export to bank" and "Reconcile / import statement" actions
            // on the PaymentRun detail page. export({id}) generates the SEPA
            // pain.001 / CSV bank file, stores + tags it, sets exportedFileRef /
            // exportedAt and drives approved → exported through the OR lifecycle
            // engine; reconcile({id}) imports a CAMT.053 statement, matches its
            // booked entries to the run's lines and drives exported → reconciled on a
            // full match (a partial match stays exported with a mismatch note). Both
            // are #[NoAdminRequired] with an explicit user-session guard plus an
            // ADR-005 per-administration authorisation guard in the controller body
            // (cross-tenant ids masked as 404). The {id} wildcard is preceded by the
            // static /export and /reconcile suffixes per Symfony route ordering, and
            // both are declared before the SPA catch-all per ADR-016.
            ['name' => 'paymentRun#export', 'url' => '/api/v1/payment-runs/{id}/export', 'verb' => 'POST'],
            ['name' => 'paymentRun#reconcile', 'url' => '/api/v1/payment-runs/{id}/reconcile', 'verb' => 'POST'],

            // Compliance-deadline-calendar (REQ-CDC-006). Per-user category
            // toggles + reminder lead times for the deadline calendar. Both
            // endpoints are #[NoAdminRequired] and STRICTLY current-user scoped
            // (the acting user comes from the session only — no user id is
            // accepted from the request, so there is no IDOR surface). Static
            // URLs declared before the SPA catch-all per ADR-016.
            ['name' => 'deadlineCalendarSettings#index', 'url' => '/api/deadline-calendar/settings', 'verb' => 'GET'],
            ['name' => 'deadlineCalendarSettings#update', 'url' => '/api/deadline-calendar/settings', 'verb' => 'POST'],

            // Accountant portal (accountant-portal, REQ-ACP-001/002/004).
            // These two were MISSING from this table entirely while every other
            // piece of the feature shipped: the page is registered by
            // src/manifest.d/accountant-portal.json, rendered by
            // src/views/AccountantPortalDashboard.vue, and called by
            // src/api/accountantApi.js — which requested
            // `/apps/shillinq/api/accountant/dashboard` and
            // `/apps/shillinq/api/accountant/administrations/{id}/handover-pack`
            // against a router that had no entry for either, so the portal
            // 404'd on open for every user. AccountantPortalController::dashboard()
            // and ::handoverPack() existed and were correct; only the wiring was
            // absent. Both are #[NoAdminRequired] and scoped server-side to the
            // caller's AdministrationMembership records (a non-granted
            // administration is masked as 404, never 403). The static
            // /dashboard URL and the static /handover-pack suffix are declared
            // before the SPA catch-all per ADR-016.
            ['name' => 'accountantPortal#dashboard', 'url' => '/api/accountant/dashboard', 'verb' => 'GET'],
            ['name' => 'accountantPortal#handoverPack', 'url' => '/api/accountant/administrations/{id}/handover-pack', 'verb' => 'GET'],

            // MUST BE THE LAST ENTRY IN $extra. Routes::standard() does
            // `array_merge($canonical, $extra)` and only then appends the
            // `/{path}` SPA catch-all, so this sits after every genuine API
            // route and before the catch-all: only a true miss reaches it.
            //
            // Without it an unmatched `/apps/shillinq/api/...` fell through to
            // the SPA and was answered with HTTP 200 + ~39KB of HTML. A browser
            // deep link wants that; an `axios.get()` does not — the promise
            // RESOLVES, `data.results` is undefined, and the caller renders an
            // empty list. Measured on a live instance, a real schema, a retired
            // schema and pure nonsense all returned the identical 200 + HTML.
            //
            // That is how eleven components ended up fetching
            // `/api/openregister/objects/...`, a route this app has never
            // declared, and silently rendering nothing (issue #1209). This
            // entry makes that class of mistake fail visibly.
            ['name' => 'apiFallback#notFound', 'url' => '/api/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+']],
        ]
        );
