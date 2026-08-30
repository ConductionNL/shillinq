// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// 5-kind component registry for v2 manifest (per hydra ADR-036).
//
// Mostly empty by policy. Every shillinq page is a declarative manifest
// page type — `dashboard` and `settings` today, `index` / `detail` for the
// business-administration domain pages. An entry here means a page (or
// sidebar tab / widget / modal) that does NOT fit a built-in type; adding
// one requires an explicit justification in the design doc of the change
// that introduces it. Removing entries is the right direction (ADR-024).
//
// Supported kinds: "page" | "widget" | "sidebarTab" | "modal" | "settingsSection"
//
// inventory-mobile-scanner exception (per design.md / proposal.md):
//   The warehouse PWA's four operations are imperative Vue components that
//   own a live camera stream, the IndexedDB layer and an optimistic-update
//   pipeline. None of those concerns fit any built-in manifest page type
//   (`index`, `detail`, `dashboard`, `settings`), and a barcode-scanning
//   form does not benefit from manifest-driven generation. Each page is
//   registered as a `kind:"page"` custom component so the manifest router
//   still owns the URL → component mapping.

// add-invoice-pdf-export-with-ubl-peppol-support (REQ-EINV-007): the Send
// e-invoice action + delivery-status indicator on the manifest-driven
// ARInvoiceDetail page. Resolved as the page's `actionsComponent` (ADR-036 —
// any registry kind is eligible for slot/actions resolution, only page
// dispatch itself requires kind:"page"), mounted into CnDetailPage's actions
// header slot with `{ object, objectId, schema, objectType, store }`.
import AREInvoiceActions from './components/ar-invoice/AREInvoiceActions.vue'
import BankingCashflowOverview from './components/banking-cashflow/BankingCashflowOverview.vue'
// bookkeeping-provincies-bbv-variant (#866/#862, REQ-BBL-001): the
// Budget-to-Programme Linker's three declared filter facets. The page stays a
// built-in `type:"index"` — this is a SLOT component, mounted into
// CnIndexPage's `below-header` slot via the page's `slots` map, not a
// replacement page. It exists because CnIndexPage has no `filters` prop at
// all: the manifest's `config.filters[]` rendered nowhere, so the index
// fetched the right rows and offered no way to narrow them. It writes the
// selection into `$route.query`, which CnIndexPage's own self-fetch merges
// into `fixedFilters` and re-fetches on — so the list stays the library's.
import BbvLinkerFilterBar from './components/bbv-provincie/BbvLinkerFilterBar.vue'
// nav-six-clusters: ADR-097 Decision-4 cluster landing pages, one per
// top-level cluster (design.md §3). Each is a thin wrapper around the shared
// ClusterOverview card grid (src/components/cluster-overview/
// ClusterOverview.vue) — kind:"page" custom components, same mechanism
// ReportingComplianceOverview above already uses; Reporting & Compliance
// itself reuses that existing page rather than getting a new one.
import BookkeepingOverview from './components/bookkeeping/BookkeepingOverview.vue'
// budget-charts (REQ-BCH-007): the shared actual/projected/begroot
// trend+cumulative chart, registered ONCE and consumed from TWO placements —
// BudgetGrid's per-row inline chart (a direct .vue import, no registry
// lookup needed there) and ChartOfAccountsDetail's sidebar tab, which DOES
// need a registry lookup: CnObjectSidebar's `tabs[].widgets[].type`
// resolves any non-built-in type against the flat customComponents map
// main.js derives from EVERY entry here (regardless of `kind`), so this one
// `kind:"widget"` entry — matching CashflowChartWidget's own kind exactly,
// per design.md §6 — is sufficient for both placements; no separate
// `kind:"sidebarTab"` entry is needed (verified by reading
// CnObjectSidebar.vue's `resolveWidgetComponent()`/`resolveTabComponent()`
// and main.js's `customComponentsProp` derivation — the budget-charts
// design.md §1b spike's own finding, recorded there in full).
import BudgetTrendChart from './components/budget-charts/BudgetTrendChart.vue'
// bookkeeping-waterschappen-bbv-variant slice 07 (REQ-BBVW-004): the
// Budget Mapping detail page composes two bespoke autocomplete pickers
// (Chart of Accounts + BBVProgramme), a live per-account allocation
// sum projection that warns when projected total exceeds the slice-03
// ±0.1 % tolerance, an audit-trail sidebar and a confirm-gated delete
// dialog. None of those concerns fit the built-in `detail` page type
// slice 04 declared, so the page is re-registered as a kind:"page"
// custom component. Slice 07's manifest fragment overlays slice 04's
// detail page entry to point at this component id, and a second
// legacy id (`BudgetMappingDetail`) is wired to the same component so
// the slice-05 BBVProgrammeTable row drill-through continues to
// resolve.
import BudgetBBVMappingDetail from './components/BudgetBBVMapping/BudgetBBVMappingDetail.vue'
// bookkeeping-waterschappen-bbv-variant slice 06 (REQ-BBVW-004): the
// Budget Mapping index page wraps CnIndexPage with bespoke filter
// chrome (search by GL account or programme code, fiscal-year +
// allocation-range + effective-date-range filters) and a custom
// allocation/status cell renderer. The built-in `index` page type
// cannot author the four-facet filter row or the percentage / status
// pill renderers, so the page is registered as a kind:"page" custom
// component. Slice 04's manifest fragment is updated in lock-step to
// switch the BudgetBBVMappings page from type=index to
// type=custom + component=BudgetBBVMappingIndex.
import BudgetBBVMappingIndex from './components/BudgetBBVMapping/BudgetBBVMappingIndex.vue'
// bookkeeping-waterschappen-bbv-variant slice 05 (REQ-BBVW-003 /
// REQ-BBVW-005): the BBV Compliance Dashboard composes four bespoke
// widgets — KPI counts, a four-bucket compliance pie, a YTD cumulative
// spend line chart and a per-programme utilization table with emoji
// status badges and a drill-through to the mapping detail page. The
// built-in `dashboard` page type can author static stats-block grids
// but cannot host the YTD timeline transform, the at-risk tooltip
// vocabulary or the emoji status badge palette, so the page is
// registered as a kind:"page" custom component. Slice 04 registers the
// route + manifest entry pointing at this component id.
import BBVComplianceDashboard from './components/Dashboard/BBVComplianceDashboard.vue'
// financial-dashboard-graphs (ADR-049 Phase-4 dissolution): the Financial
// overview KPI strip, the turnover / margin / billable charts and the two
// open-invoice tables are now DECLARATIVE built-in dashboard widgets —
// `stat` (endpointSource /api/dashboard/financial-summary), `chart`
// (endpointSource /api/dashboard/financial-series + a €/% resp. hours/%
// `views[]` switcher) and `object-table` (OpenRegister source). The
// dashboard / payment-run action ribbons are now `config.headerActions[]`
// (open-modal / api-call). See src/manifest.json Dashboard page +
// src/manifest.d/bookkeeping-accounts-payable-core.json PaymentRunDetail.
//
// CashflowChartWidget stays a custom kind:"widget": it merges TWO sources
// (realized GL lines + the 13-week CashflowWeek forecast) into one chart
// with dimmed projection columns — a genuinely custom two-source transform
// (nextcloud-vue#91 Wave-4) that no single declarative endpoint expresses.
import CashflowChartWidget from './components/dashboard/financial/CashflowChartWidget.vue'
import GoodsReceiptNoteDetail from './components/goods-receipt-note/GoodsReceiptNoteDetail.vue'
// bookkeeping-purchase-order-3way slice 04 (REQ-GRN-001 / REQ-PO3W-003):
// the GRN capture form is a mobile-optimised multi-PO line-by-line receipt
// flow with rejection-reason picker + delivery-photo upload, and the detail
// view drives the quality-check + accept transitions (accept posts a
// StockMove credit per accepted line and updates the originating PO(s)
// lifecycle via the server-authoritative GoodsReceiptNoteService). Neither
// fits a built-in `index` / `detail` page type, so both are kind:"page"
// custom components.
import GoodsReceiptNoteForm from './components/goods-receipt-note/GoodsReceiptNoteForm.vue'
// inventory-product-catalog (#860, REQ-IPC-004 / REQ-IPC-008 +
// shillinq-product-vendor-to-pipelinq REQ-SPVP-004): the two read-only
// catalog surfaces. A declarative `type: "index"` page binds to ONE
// OpenRegister register + schema, and the product master these two pages
// show is not in shillinq's register at all — REQ-SPVP-004 deleted the local
// `Product` register and moved ownership to pipelinq. Binding an index page
// to `register: "pipelinq"` instead would render an empty table on every
// install without pipelinq while satisfying every "the page mounted"
// assertion; these components call shillinq's own catalog endpoint, which
// resolves the master first and falls back to the local denormalised cache
// the ADR-019 contract declares, and renders WHICH OF THE TWO answered.
// Neither page can write: no create affordance and no write route exists,
// per REQ-SPVP-004.
import ProductAttributeCatalogIndex from './components/inventory/ProductAttributeCatalogIndex.vue'
import ProductCatalogIndex from './components/inventory/ProductCatalogIndex.vue'
// invoice-from-time-and-expense (issue #111): drafting form + admin list
// + detail page are imperative because the generator combines multi-source
// dynamic look-ups (time entries + expenses + rate card + retainer) into
// a single editable preview, which does not fit `index` / `detail`.
import InvoiceGenerator from './components/invoice/InvoiceGenerator.vue'
// recurring-invoicing (REQ-RIN-008, Task 15): the create/edit modal for a
// RecurringInvoiceProfile is a bespoke multi-line form with period-token
// preview and an inline next-invoice projection — none of which the
// generic schema-driven create form can author. Modal-isolated under
// src/modals/ (hydra gate-13) and registered below as a kind:"modal".
//
// Its LAUNCHER sits next to it. The index page's `config.headerActions[]`
// entry could never open the modal: CnIndexPage renders a manifest header
// action inside the collapsed ⋯ overflow, and its click only `$emit`s
// `header-action`, which CnPageRenderer does not listen for (the gap the
// fragment's own `_note_open_modal_gap` recorded). The launcher is
// kind:"widget" so the page can declare it in CnPageRenderer's
// `header-actions` widget slot, which CnWidgetGrid resolves against THIS
// registry before its built-in widget table.
import RecurringInvoiceProfileLauncher from './components/invoice/RecurringInvoiceProfileLauncher.vue'
// bookkeeping-period-close (REQ-PC-005, REQ-PC-006, REQ-PC-007 / Task 9 + 10):
// the PeriodCloseDetail page composes the FiscalPeriod metadata header, the
// close-task checklist (AP / AR / bank / expense claims) with inline
// close-assistant flags, the four lifecycle action buttons (Start close /
// Close period / Reopen / Lock for audit) and the reopen-history audit
// trail. The reopen flow is gated behind an isolated ReopenPeriodDialog
// (modal isolation per hydra gate-13) that captures the mandatory
// closeReason. Neither the lifecycle action ribbon, the bespoke AI-flag
// pill renderer nor the reopen-history timeline fit the built-in
// declarative `detail` page type — so the page is registered as a
// kind:"page" custom component per ADR-024 / ADR-036.
import PeriodCloseDetail from './components/period-close/PeriodCloseDetail.vue'
import PurchaseOrderDetail from './components/purchase-order/PurchaseOrderDetail.vue'
// bookkeeping-purchase-order-3way slice 02 (REQ-PO3W-001): the create form
// previews the server-determined approval chain as the line total changes
// and POSTs the normalised payload to /api/purchase-orders; the detail
// view renders the materialised approval chain with per-approver
// signature timestamps and gates the 'Send to supplier' action on the
// server-authoritative blockSendUntilApproved guard. Neither view fits a
// built-in `index` / `detail` page type, so both are kind:"page" custom
// components.
import PurchaseOrderForm from './components/purchase-order/PurchaseOrderForm.vue'
import PurchasingOverview from './components/purchasing/PurchasingOverview.vue'
import GeneratedReportsIndex from './components/reporting/GeneratedReportsIndex.vue'
// reporting-compliance-consolidation: the Reporting & Compliance section is
// two custom pages. The overview renders the static ReportCatalogue
// (lib/Reporting/ReportCatalogue.php) as category-grouped cards with a
// per-card format picker and an isolated GenerateReportDialog that POSTs
// /api/reporting/generate — none of which the declarative dashboard/index
// page types can author. The generated-reports index renders the persisted
// GeneratedReport records with a per-row download link + a three-facet
// filter row that does not fit the built-in `index` page type. Both are
// kind:"page" custom components per ADR-024 / ADR-036; the manifest
// fragment src/manifest.d/reporting-compliance.json declares the routes.
import ReportingComplianceOverview from './components/reporting/ReportingComplianceOverview.vue'
import SalesOverview from './components/sales/SalesOverview.vue'
// Import bank statements lives under the Configuratie (settings) group as a
// page that hosts BankStatementWizard — moved off the Financial overview
// dashboard so it sits with the other one-time setup tasks. manifest fragment
// src/manifest.d/bank-import-settings.json declares the route + menu entry.
import BankImportPage from './components/settings/BankImportPage.vue'
// spend-analytics-ui: the panel behind the SpendAnalytics dashboard page's
// `widget-spend-analysis` slot — see the registry entry below for why the
// declarative chart/stat widgets cannot render this endpoint's failure state.
import SpendAnalyticsPanel from './components/spend-analytics/SpendAnalyticsPanel.vue'
// bookkeeping-purchase-order-3way slice 05 (REQ-PO3W-004 / REQ-PO3W-007):
// the supplier-invoice detail view renders the OCR-confidence indicator,
// the Peppol/UBL provenance block and the link to the related
// ThreeWayMatch. None of those concerns fit a built-in `detail` page
// type (the OCR meter is a conditional bespoke block), so the view is
// registered as a kind:"page" custom component.
import SupplierInvoiceDetail from './components/supplier-invoice/SupplierInvoiceDetail.vue'
import TaxesOverview from './components/taxes/TaxesOverview.vue'
// bookkeeping-purchase-order-3way slice 08 (REQ-PO3W-005): the
// ThreeWayMatchExceptionPanel renders the side-by-side PO ↔ GRN ↔
// Invoice comparison, the human-readable divergence breakdown and the
// three resolution dispositions (accept-with-motivation / file-dispute /
// reject-and-block-payment). Neither the comparison block nor the
// inline resolution form fits the built-in `detail` page type, so the
// panel is registered as a kind:"page" custom component overlaid onto
// the ThreeWayMatchDetail manifest page slice 01 declares.
import ThreeWayMatchExceptionPanel from './components/three-way-match/ThreeWayMatchExceptionPanel.vue'
// bookkeeping-purchase-order-3way slice 06 (REQ-PO3W-004 / REQ-PO3W-006):
// the three-way-match index renders per-row match-status pills and a
// "Re-evaluate" quick-action button that calls back into the matching
// engine endpoint. The bespoke status pill + retrigger button do not
// fit the built-in `index` page type, so the view is registered as a
// kind:"page" custom component.
import ThreeWayMatchIndex from './components/three-way-match/ThreeWayMatchIndex.vue'
import VendorPerformanceDetail from './components/vendor-performance/VendorPerformanceDetail.vue'
// bookkeeping-purchase-order-3way slice 10 (REQ-PO3W-008 / REQ-VP-001 /
// REQ-VP-005): the VendorPerformance index + detail render the monthly
// scorecard with basis-point rate pills, the weighted overall score, the
// period-over-period trend pill and the auto-review eligibility badge.
// Neither view fits a built-in `index` or `detail` page type because the
// rate pills, score colour band and trend indicator are bespoke, so both
// are registered as kind:"page" custom components.
import VendorPerformanceIndex from './components/vendor-performance/VendorPerformanceIndex.vue'
import BillImportModal from './modals/BillImportModal.vue'
// ADR-049 Phase-4 dissolution: modals formerly launched by the imperative
// FinancialDashboardActions / PaymentRunDetailActions widgets. Those action
// ribbons are now declarative `config.headerActions[]` (type:"open-modal");
// each modal is registered here as a kind:"modal" so the manifest action's
// `target` resolves it. Modal-isolated under src/modals/ (hydra gate-13).
import InvoiceQuickDraftModal from './modals/InvoiceQuickDraftModal.vue'
import PaymentRunReconcileModal from './modals/PaymentRunReconcileModal.vue'
import RecurringInvoiceProfileModal from './modals/RecurringInvoiceProfileModal.vue'
// accountant-portal: the multi-client dashboard composes a per-card status
// (period-close state, BTW filing + deadline, missing documents, open items
// from PeriodCloseAssistantService) plus a per-card "Download handover pack"
// action. The built-in `dashboard` page type's widgets (summary/table/chart)
// bind to a single register+schema; this page aggregates four signals across
// AccountantDashboardService and triggers a file download per card, neither
// of which fits that vocabulary (mirrors the reporting-compliance-consolidation
// precedent above). kind:"page" custom component per ADR-024 / ADR-036; the
// manifest fragment src/manifest.d/accountant-portal.json declares the route.
import AccountantPortalDashboard from './views/AccountantPortalDashboard.vue'
// bookkeeping-multi-administratie (Task 13): the in-session administration
// switcher is a custom page hosting the AdministratieSwitcher dropdown. It is
// genuinely custom (NOT a declarative index/detail over a register) because it
// reads `/api/administrations/context`, posts to `/api/administrations/switch`
// and triggers a session-level reload — none of which is expressible in a
// built-in page type. See AdministratieSwitcher docblock for the kind-page
// justification per ADR-024 / ADR-036.
import AdministrationSwitcherPage from './views/AdministrationSwitcherPage.vue'
// bookings-pipelinq-customer-bridge slice 06 (profile-card-ui): the
// AfspraakDetail page fans out to /api/v1/bookings/{id} which returns
// the Appointment AND the linked pipelinq Contact + klantbeeld history
// in one hop (slice 05). The page then composes a read-only profile
// card (PipelinqProfileCard) and a paginated transaction-history
// timeline (KlantbeeldTimeline) around the standard appointment
// summary. Neither concern fits the built-in `detail` page type
// (single-object fetch from OR), so the page is registered as a
// kind:"page" custom component with the same id (`AfspraakDetail`)
// the manifest fragment now points at.
import AfspraakDetail from './views/bookings/AfspraakDetail.vue'
import BookingForm from './views/bookings/BookingForm.vue'
// bookings-resource-calendar (#117, REQ-006/REQ-007): the multi-resource
// CalendarView + BookingForm pages live under src/views/bookings/. They target
// the /api/v2/calendars REST surface (Resource / Calendar / Booking schemas in
// the shillinq register). Justified per ADR-024 (imperative time grid +
// inline conflict dialog). The older single-dropdown "Verkoop → Bookings
// calendar" shortcut page (CalendarPage.vue, manifest.d/
// bookings-resource-calendar.json) that wrapped this same CalendarView was
// removed as dead-weight duplicate navigation — see
// shillinq-manifest-boot-payload-reduction (REQ-MBP-002).
// `CalendarPage` is the HOST: CalendarView is a presentation grid that only
// EMITS slot:clicked / booking:selected, so pointing the BookingsCalendar page
// straight at it (as REQ-MBP-002 left it when it deleted the old host) made
// REQ-007 — click a free slot, fill the form, resolve a 409 conflict —
// unreachable in the shipped app. The host restores that wiring without
// restoring the removed `/verkoop/boekingen-kalender` nav entry.
import CalendarPage from './views/bookings/CalendarPage.vue'
// bookings-confirm-flow (REQ-BCF-007): the customer-facing confirmation
// portal at /confirm/:appointmentId is an imperative Vue component — it
// owns its own URL-parameter parsing (token + appointmentId), runs a
// dry-run validation request on mount, switches between loading /
// error / form / success states, and renders the appointment time in
// the customer's local timezone via Intl.DateTimeFormat. None of those
// concerns fit any built-in index/detail/dashboard/settings page type,
// so the portal is registered as a kind:"page" custom component.
import BookingsConfirmationPortal from './views/bookings/ConfirmationPortal.vue'
// bookings-self-service-widget (REQ-WSW-009): per-business widget API-key
// admin view. The page is wired through src/manifest.d/30-bookings-self-service-widget.json
// and gated by #[AuthorizedAdminSetting] on the WidgetSettingsController
// — it cannot be exposed publicly via the in-app router unless the
// server-side attribute is dropped. ADR-004 compliant.
import BookingWidgetKeys from './views/BookingWidgetKeys.vue'
// bookkeeping-wbso-sno-administratie (REQ-WBSO-006/002/003): the three
// bookkeeping foundation views are imperative — the Chart of Accounts is a
// hierarchical RGS tree (no built-in tree page type), and the Transactions /
// Documents tables fan out to dedicated REST surfaces that filter by status,
// type, and date range. Registered as kind:"page" custom components per
// ADR-024 / ADR-036.
import WbsoChartOfAccountsView from './views/bookkeeping/ChartOfAccountsView.vue'
// bookkeeping-cost-centers-dimensions Task 14 (W6): the SegmentPnLDashboard
// composes server-side aggregations declared on GLLine
// (byCostCenter / byCostCenterHierarchy / byProject /
// byAnalyticalDimension) into one operator-facing P&L drill-down. The
// dashboard owns segment selection, hierarchical roll-up rendering, and a
// CSV export — none of which fit any built-in declarative page type, so
// the page is registered as a kind:"page" custom component per ADR-024.
import SegmentPnLDashboard from './views/bookkeeping/dimensions/SegmentPnLDashboard.vue'
import WbsoDocumentsView from './views/bookkeeping/DocumentsView.vue'
// add-shillinq-multi-currency Task 14: the FxRatesAdmin page wraps the
// declarative FxRate index grid with a cron-status overlay (last-run
// timestamp + TreasuryRateAdapter dormancy flag) read from
// /api/admin/fx-rate-import-status. The header strip + dormancy hint do
// not fit any built-in `index` / `detail` / `dashboard` page type, so the
// page is registered as a kind:"page" custom component per ADR-024.
// Admin-only — the controller is gated by
// #[AuthorizedAdminSetting(Application::class)].
import FxRatesAdmin from './views/bookkeeping/multi-currency/FxRatesAdmin.vue'
import WbsoTransactionsView from './views/bookkeeping/TransactionsView.vue'
// budget-grid-view (REQ-BGV-001..009): the year-basis begroting grid —
// verzamelpost rows (LedgerGroup tree, expandable to child groups or
// resolved grootboek accounts), a caller-selected period range/granularity,
// past-column actuals-vs-budget deviation, and a TOTAAL cumulative column.
// None of that fits `index`/`detail`/`dashboard`, so it is registered as a
// kind:"page" custom component per ADR-024 (same pattern as
// BudgetLineCommitments/SegmentPnLDashboard above).
import BudgetGrid from './views/BudgetGrid.vue'
// verplichtingen-commitment-accounting Task 4 (REQ-VPL-011): the
// BudgetLineCommitments drilldown composes the declarative
// committedVsRealisedPerBudgetLine aggregation declared on
// CommitmentLine into a per-budget-line report with a commitment
// drilldown. Registered as a kind:"page" custom component per ADR-024
// (same pattern as SegmentPnLDashboard).
import BudgetLineCommitments from './views/BudgetLineCommitments.vue'
// budget-scenarios (REQ-BSC-008, design.md §9): the standalone scenario
// comparison page composes an administration/fiscal-year/scenario PICKER
// with a bespoke base/scenario/delta table calling
// BudgetScenarioController::evaluate() — none of the declarative page types
// (index/detail/dashboard) fit a picker-driven, non-register-bound table.
// kind:"page" custom component; the manifest fragment
// src/manifest.d/budget-scenarios.json declares the route.
import BudgetScenarioComparison from './views/BudgetScenarioComparison.vue'
// compliance-deadline-calendar (REQ-CDC-006): per-user category toggles
// + reminder lead times for the compliance deadline calendar. Talks to
// the strictly current-user-scoped /api/deadline-calendar/settings
// endpoints; registered as a kind:"page" custom component per ADR-024.
import DeadlineCalendarSettings from './views/DeadlineCalendarSettings.vue'
// integration-config-to-openconnector (formerly Shillinq W8): the
// External Connections page renders one roster row per external-API
// adapter family (15 — Digipoort/SBR, Salarisbureau, RvO, IB47, CBS
// Bestanden, CBS Iv3, BZK SiSa, Mollie, Bunq, KvK, UWV, Treasury
// Rates, CCM Rule Engine, CSRD ESRS XBRL, DepositPayment), sourced
// from /api/admin/external-adapters: dormancy badge + declared
// sourceSlug + live openconnector-provisioning status + a deep link
// to openconnector's Source admin. The per-family detail page +
// route this file used to also register are gone — integration
// configuration (credentials, endpoints, protocol mapping) lives in
// openconnector per ADR-067/ADR-091/ADR-022; this app holds only the
// slug reference, so there is nothing left for a per-adapter page to
// render. Does not fit a built-in `index` / `detail` page type: the
// data is not an OR register, it's an in-controller registry of
// adapter metadata plus a live per-row provisioning lookup. kind:
// "page" custom component per ADR-024 / ADR-036.
import ExternalAdaptersStatus from './views/external-adapters/ExternalAdaptersStatus.vue'
import FlowDetailSidebar from './views/flows/FlowDetailSidebar.vue'
import CountPage from './views/inventory/CountPage.vue'
import MobileScannerHome from './views/inventory/MobileScannerHome.vue'
import PickPage from './views/inventory/PickPage.vue'
import ReceivePage from './views/inventory/ReceivePage.vue'
import TransferPage from './views/inventory/TransferPage.vue'
import AdminInvoiceDetail from './views/invoice/AdminInvoiceDetail.vue'
import AdminInvoiceList from './views/invoice/AdminInvoiceList.vue'
// receipt-extraction-consume (REQ-RXC-003) — custom detail page for a single
// Receipt record: prefills from a docudesk extraction draft with per-field
// confidence + correction + re-request, none of which fits the built-in
// `detail` page type. Registered kind:"page" for
// src/manifest.d/receipt-extraction-consume.json's `type: "custom"` entry.
import ReceiptCapture from './views/ReceiptCapture.vue'
import StandardsPolicyEditor from './views/settings/StandardsPolicyEditor.vue'

export default {
	// --- Flows (ADR-110 Decision 4). Only the SIDEBAR is an app component;
	//     the list and the canvas are the shared `flows` / `flow-detail`
	//     manifest page types. CnFlowSidebar has to mount in the NC app
	//     sidebar for the canvas to keep full width. ---
	FlowDetailSidebar: { kind: 'page', component: FlowDetailSidebar },

	RecurringInvoiceProfileModal: {
		kind: 'modal',
		component: RecurringInvoiceProfileModal,
	},
	RecurringInvoiceProfileLauncher: {
		kind: 'widget',
		component: RecurringInvoiceProfileLauncher,
		_note: 'Not a data widget: it is the create affordance for RecurringInvoiceProfileModal. CnIndexPage has no declarative way to open a registry modal (its headerActions[] click is emit-only and CnPageRenderer does not listen), so the button and the modal live together in one header-actions slot widget.',
	},

	// ADR-049 Phase-4: modals launched by declarative headerActions (open-modal).
	InvoiceQuickDraftModal: { kind: 'modal', component: InvoiceQuickDraftModal },
	BillImportModal: { kind: 'modal', component: BillImportModal },
	PaymentRunReconcileModal: { kind: 'modal', component: PaymentRunReconcileModal },

	StandardsPolicyEditor: { kind: 'page', component: StandardsPolicyEditor },
	MobileScannerHome: { kind: 'page', component: MobileScannerHome },
	MobileScannerReceive: { kind: 'page', component: ReceivePage },
	MobileScannerTransfer: { kind: 'page', component: TransferPage },
	MobileScannerPick: { kind: 'page', component: PickPage },
	MobileScannerCount: { kind: 'page', component: CountPage },

	InvoiceGenerator: { kind: 'page', component: InvoiceGenerator },
	AdminInvoiceList: { kind: 'page', component: AdminInvoiceList },
	AdminInvoiceDetail: { kind: 'page', component: AdminInvoiceDetail },

	BookingsConfirmationPortal: {
		kind: 'page',
		component: BookingsConfirmationPortal,
	},
	AfspraakDetail: { kind: 'page', component: AfspraakDetail },
	ReceiptCapture: { kind: 'page', component: ReceiptCapture },

	PurchaseOrderForm: { kind: 'page', component: PurchaseOrderForm },
	PurchaseOrderDetail: { kind: 'page', component: PurchaseOrderDetail },

	GoodsReceiptNoteForm: { kind: 'page', component: GoodsReceiptNoteForm },
	GoodsReceiptNoteDetail: { kind: 'page', component: GoodsReceiptNoteDetail },

	SupplierInvoiceDetail: { kind: 'page', component: SupplierInvoiceDetail },

	ThreeWayMatchIndex: { kind: 'page', component: ThreeWayMatchIndex },

	ThreeWayMatchExceptionPanel: {
		kind: 'page',
		component: ThreeWayMatchExceptionPanel,
	},

	VendorPerformanceIndex: { kind: 'page', component: VendorPerformanceIndex },
	VendorPerformanceDetail: { kind: 'page', component: VendorPerformanceDetail },

	BBVComplianceDashboard: { kind: 'page', component: BBVComplianceDashboard },

	BudgetBBVMappingIndex: { kind: 'page', component: BudgetBBVMappingIndex },

	BudgetBBVMappingDetail: { kind: 'page', component: BudgetBBVMappingDetail },
	BudgetMappingDetail: { kind: 'page', component: BudgetBBVMappingDetail },

	// bookkeeping-provincies-bbv-variant (#866/#862, REQ-BBL-001).
	BbvLinkerFilterBar: {
		kind: 'widget',
		component: BbvLinkerFilterBar,
		_note: "Slot component for CnIndexPage's `below-header` slot, not a page: the Linker index stays a built-in type:\"index\" so its self-fetch, paging and empty state remain the library's. CnIndexPage has no `filters` prop, so the page's declared config.filters[] had no renderer; this one writes the selection into $route.query, which the self-fetch merges into fixedFilters.",
	},
	AdministrationSwitcherPage: {
		kind: 'page',
		component: AdministrationSwitcherPage,
	},

	// bookings-resource-calendar custom pages (REQ-006/REQ-007).
	BookingsCalendar: { kind: 'page', component: CalendarPage },
	BookingsForm: { kind: 'page', component: BookingForm },

	// bookkeeping-wbso-sno-administratie foundation views.
	WbsoChartOfAccountsView: { kind: 'page', component: WbsoChartOfAccountsView },
	WbsoTransactionsView: { kind: 'page', component: WbsoTransactionsView },
	WbsoDocumentsView: { kind: 'page', component: WbsoDocumentsView },

	// bookings-self-service-widget admin (REQ-WSW-009).
	BookingWidgetKeys: { kind: 'page', component: BookingWidgetKeys },

	// bookkeeping-period-close detail page (REQ-PC-005, REQ-PC-006).
	PeriodCloseDetail: { kind: 'page', component: PeriodCloseDetail },

	// add-shillinq-multi-currency Task 14: FxRates admin overlay (cron status).
	FxRatesAdmin: { kind: 'page', component: FxRatesAdmin },

	// bookkeeping-cost-centers-dimensions Task 14: segment P&L drill-down.
	SegmentPnLDashboard: { kind: 'page', component: SegmentPnLDashboard },
	BudgetLineCommitments: { kind: 'page', component: BudgetLineCommitments },
	BudgetGrid: { kind: 'page', component: BudgetGrid },

	// compliance-deadline-calendar (REQ-CDC-006): deadline calendar settings.
	DeadlineCalendarSettings: { kind: 'page', component: DeadlineCalendarSettings },

	// integration-config-to-openconnector: External Connections roster page.
	ExternalAdaptersStatus: { kind: 'page', component: ExternalAdaptersStatus },

	// financial-dashboard-graphs (ADR-049 Phase-4): the only surviving custom
	// financial widget. Cashflow merges realized GL lines with the 13-week
	// forecast (two sources) into one chart — see the import docblock above.
	// The KPIs / turnover / margin / billable charts / open-invoice tables are
	// now declarative built-in stat / chart / object-table dashboard widgets.
	CashflowChartWidget: {
		kind: 'widget',
		component: CashflowChartWidget,
		_note: 'Merges realized GL lines with the 13-week forecast (two data sources) into one chart — no built-in chart widget joins two sources (ADR-049 Phase-4 survivor).',
	},

	// budget-charts (REQ-BCH-007): actual/projected/begroot trend+cumulative
	// chart, shared by BudgetGrid's inline placement and
	// ChartOfAccountsDetail's sidebar tab. Custom kind:"widget" because the
	// declarative type:"chart" dialect has no per-series dashed/dimmed
	// styling, no two-source (GL actuals + growth-rate projection) merge,
	// and no point-level `unprojectable` marker+tooltip — see the import
	// docblock above.
	BudgetTrendChart: {
		kind: 'widget',
		component: BudgetTrendChart,
		_note: 'Actual/projected/begroot trend+cumulative chart with a dashed-not-colour-only projected seam and a point-level unprojectable marker — no declarative series[].path array can express either.',
	},

	// spend-analytics-ui: the first frontend consumer of
	// GET /api/analytics/spend, rendered on the SpendAnalytics dashboard
	// page through the slot `widget-spend-analysis`.
	SpendAnalyticsPanel: {
		// @custom-widget-ratchet exclude the four spend views must distinguish "unavailable" from "no rows", and no built-in dashboard widget can: CnChartWidget subscribes to useEndpointSource but keeps only ep.data/ep.refetch and DISCARDS ep.error, so glline-administration-scope REQ-GLS-003's deliberate HTTP 500 would render as its emptyLabel ("no data") — the silent-zero reading that requirement exists to forbid — while CnStatWidget surfaces it only as a bare em dash behind a title tooltip, with no test id and nothing a keyboard user reaches. The alternative to this entry is a UI that reports a shut completeness gate as an absence of spend.
		kind: 'widget',
		component: SpendAnalyticsPanel,
		_note: 'Renders all four /api/analytics/spend dimensions with four distinct states (loading / unavailable / no-rows / rows) and prints no figure for a view that did not answer. No built-in widget surfaces an endpoint error as anything but "no data" (CnChartWidget discards ep.error) or a bare em dash (CnStatWidget), which would report REQ-GLS-003\'s deliberate raise as a zero total.',
	},

	// add-invoice-pdf-export-with-ubl-peppol-support (REQ-EINV-007).
	AREInvoiceActions: {
		kind: 'widget',
		component: AREInvoiceActions,
		_note: 'Header actionsComponent, not a data widget: pairs a stateful Send e-invoice action (POST + optimistic status update + inline validation errors) with the delivery-status chip — no built-in widget performs writes.',
	},

	// reporting-compliance-consolidation: Reporting & Compliance pages.
	ReportingComplianceOverview: {
		kind: 'page',
		component: ReportingComplianceOverview,
	},

	// nav-six-clusters: the 5 new cluster landing pages (design.md §3).
	BookkeepingOverview: { kind: 'page', component: BookkeepingOverview },
	SalesOverview: { kind: 'page', component: SalesOverview },
	PurchasingOverview: { kind: 'page', component: PurchasingOverview },
	BankingCashflowOverview: { kind: 'page', component: BankingCashflowOverview },
	TaxesOverview: { kind: 'page', component: TaxesOverview },
	GeneratedReportsIndex: { kind: 'page', component: GeneratedReportsIndex },
	BankImportPage: { kind: 'page', component: BankImportPage },

	// accountant-portal: scoped multi-client dashboard + handover-pack export.
	AccountantPortalDashboard: {
		kind: 'page',
		component: AccountantPortalDashboard,
	},

	// budget-scenarios: standalone base/scenario/delta comparison page.
	BudgetScenarioComparison: {
		kind: 'page',
		component: BudgetScenarioComparison,
	},

	// inventory-product-catalog (#860). See the import docblock above for why
	// these are custom pages rather than declarative `type: "index"` ones.
	ProductCatalogIndex: { kind: 'page', component: ProductCatalogIndex },
	ProductAttributeCatalogIndex: {
		kind: 'page',
		component: ProductAttributeCatalogIndex,
	},
}
