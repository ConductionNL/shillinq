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

        // add-shillinq-multi-currency Task 14: FxRate admin status — read-only
        // endpoint that surfaces the last-run timestamp of FxRateImportJob plus
        // the TreasuryRateAdapter dormancy flag. Drives the FxRatesAdmin Vue
        // page "Import status" header strip. Gated by
        // #[AuthorizedAdminSetting(Application::class)].
        ['name' => 'fxRateAdmin#status', 'url' => '/api/admin/fx-rate-import-status', 'verb' => 'GET'],

        // Shillinq W8 (external-adapters admin UIs): read-only admin
        // roll-up + per-adapter detail over the 14 dormant external-API
        // adapter ports (Digipoort/SBR, Salarisbureau, RvO, IB47, CBS x2,
        // BZK SiSa, Mollie, Bunq, KvK, UWV, Treasury Rates, CCM Rule
        // Engine, CSRD ESRS XBRL, DepositPayment). Drives
        // ExternalAdaptersStatus.vue (index) +
        // ExternalAdapterDetail.vue (per-adapter activation panel).
        // Both endpoints gated by
        // #[AuthorizedAdminSetting(Application::class)] — the
        // activation steps reveal configuration keys which are
        // admin-only data.
        ['name' => 'externalAdaptersAdmin#index', 'url' => '/api/admin/external-adapters', 'verb' => 'GET'],
        ['name' => 'externalAdaptersAdmin#show', 'url' => '/api/admin/external-adapters/{id}', 'verb' => 'GET'],

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
        // bookkeeping-rekenkamer-audit-pack REQ-RAP-005 — RBAC-scoped
        // compliance export over the OR audit-trail (CSV / JSON;
        // PII fields stripped; auditor / admin group only; the
        // export request itself is recorded in the audit-trail).
        ['name' => 'complianceExport#export', 'url' => '/api/audit/export', 'verb' => 'GET'],
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

        // BCF compensation calculator (bookkeeping-bcf-claim). Pure compute
        // surface returning a what-if compensation result.
        ['name' => 'bcfClaim#compensation', 'url' => '/api/bcf/compensation', 'verb' => 'GET'],

        // bookkeeping-reconciliation-reports (T4) — REQ-REC-004 unmatched-item
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

        // IFRS 16 lease accounting (bookkeeping-ifrs-16-lease, REQ-LA-002,
        // REQ-LD-001). Per-contract amortization schedule + per-period
        // disclosure table. Read-only.
        ['name' => 'lease#schedule', 'url' => '/api/leases/schedule', 'verb' => 'GET'],
        ['name' => 'lease#disclosure', 'url' => '/api/leases/disclosure', 'verb' => 'GET'],

        // IFRS 15 revenue cut-off (bookkeeping-ifrs15-revenue, REQ-IFRS15-007/008).
        ['name' => 'revenue#cutoff', 'url' => '/api/revenue/cutoff', 'verb' => 'GET'],

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

        // bookkeeping-wbso-sno-administratie REQ-WBSO-001/002/003/005/006/007/008/009 —
        // server-authoritative REST surface for Account / Transaction / Document
        // registers. Static segments first; the {accountNumber}/{id} wildcards are
        // last per Symfony route ordering. Every endpoint is #[NoAdminRequired] and
        // authentication + role gating happens in the controller body.
        ['name' => 'wbsoAccountApi#hierarchy', 'url' => '/api/v1/accounts/hierarchy', 'verb' => 'GET'],
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

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
