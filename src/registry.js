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

import MobileScannerHome from './views/inventory/MobileScannerHome.vue'
import ReceivePage from './views/inventory/ReceivePage.vue'
import TransferPage from './views/inventory/TransferPage.vue'
import StandardsPolicyEditor from './views/settings/StandardsPolicyEditor.vue'
import PickPage from './views/inventory/PickPage.vue'
import CountPage from './views/inventory/CountPage.vue'
// bookings-confirm-flow (REQ-BCF-007): the customer-facing confirmation
// portal at /confirm/:appointmentId is an imperative Vue component — it
// owns its own URL-parameter parsing (token + appointmentId), runs a
// dry-run validation request on mount, switches between loading /
// error / form / success states, and renders the appointment time in
// the customer's local timezone via Intl.DateTimeFormat. None of those
// concerns fit any built-in index/detail/dashboard/settings page type,
// so the portal is registered as a kind:"page" custom component.
import BookingsConfirmationPortal from './views/bookings/ConfirmationPortal.vue'

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

// receipt-extraction-consume (REQ-RXC-003) — custom detail page for a single
// Receipt record: prefills from a docudesk extraction draft with per-field
// confidence + correction + re-request, none of which fits the built-in
// `detail` page type. Registered kind:"page" for
// src/manifest.d/receipt-extraction-consume.json's `type: "custom"` entry.
import ReceiptCapture from './views/ReceiptCapture.vue'

// invoice-from-time-and-expense (issue #111): drafting form + admin list
// + detail page are imperative because the generator combines multi-source
// dynamic look-ups (time entries + expenses + rate card + retainer) into
// a single editable preview, which does not fit `index` / `detail`.
import InvoiceGenerator from './components/invoice/InvoiceGenerator.vue'
import AdminInvoiceList from './views/invoice/AdminInvoiceList.vue'
import AdminInvoiceDetail from './views/invoice/AdminInvoiceDetail.vue'

// bookkeeping-purchase-order-3way slice 02 (REQ-PO3W-001): the create form
// previews the server-determined approval chain as the line total changes
// and POSTs the normalised payload to /api/purchase-orders; the detail
// view renders the materialised approval chain with per-approver
// signature timestamps and gates the 'Send to supplier' action on the
// server-authoritative blockSendUntilApproved guard. Neither view fits a
// built-in `index` / `detail` page type, so both are kind:"page" custom
// components.
import PurchaseOrderForm from './components/purchase-order/PurchaseOrderForm.vue'
import PurchaseOrderDetail from './components/purchase-order/PurchaseOrderDetail.vue'

// bookkeeping-purchase-order-3way slice 04 (REQ-GRN-001 / REQ-PO3W-003):
// the GRN capture form is a mobile-optimised multi-PO line-by-line receipt
// flow with rejection-reason picker + delivery-photo upload, and the detail
// view drives the quality-check + accept transitions (accept posts a
// StockMove credit per accepted line and updates the originating PO(s)
// lifecycle via the server-authoritative GoodsReceiptNoteService). Neither
// fits a built-in `index` / `detail` page type, so both are kind:"page"
// custom components.
import GoodsReceiptNoteForm from './components/goods-receipt-note/GoodsReceiptNoteForm.vue'
import GoodsReceiptNoteDetail from './components/goods-receipt-note/GoodsReceiptNoteDetail.vue'

// bookkeeping-purchase-order-3way slice 05 (REQ-PO3W-004 / REQ-PO3W-007):
// the supplier-invoice detail view renders the OCR-confidence indicator,
// the Peppol/UBL provenance block and the link to the related
// ThreeWayMatch. None of those concerns fit a built-in `detail` page
// type (the OCR meter is a conditional bespoke block), so the view is
// registered as a kind:"page" custom component.
import SupplierInvoiceDetail from './components/supplier-invoice/SupplierInvoiceDetail.vue'

// bookkeeping-purchase-order-3way slice 06 (REQ-PO3W-004 / REQ-PO3W-006):
// the three-way-match index renders per-row match-status pills and a
// "Re-evaluate" quick-action button that calls back into the matching
// engine endpoint. The bespoke status pill + retrigger button do not
// fit the built-in `index` page type, so the view is registered as a
// kind:"page" custom component.
import ThreeWayMatchIndex from './components/three-way-match/ThreeWayMatchIndex.vue'

// bookkeeping-purchase-order-3way slice 08 (REQ-PO3W-005): the
// ThreeWayMatchExceptionPanel renders the side-by-side PO ↔ GRN ↔
// Invoice comparison, the human-readable divergence breakdown and the
// three resolution dispositions (accept-with-motivation / file-dispute /
// reject-and-block-payment). Neither the comparison block nor the
// inline resolution form fits the built-in `detail` page type, so the
// panel is registered as a kind:"page" custom component overlaid onto
// the ThreeWayMatchDetail manifest page slice 01 declares.
import ThreeWayMatchExceptionPanel from './components/three-way-match/ThreeWayMatchExceptionPanel.vue'

// bookkeeping-purchase-order-3way slice 10 (REQ-PO3W-008 / REQ-VP-001 /
// REQ-VP-005): the VendorPerformance index + detail render the monthly
// scorecard with basis-point rate pills, the weighted overall score, the
// period-over-period trend pill and the auto-review eligibility badge.
// Neither view fits a built-in `index` or `detail` page type because the
// rate pills, score colour band and trend indicator are bespoke, so both
// are registered as kind:"page" custom components.
import VendorPerformanceIndex from './components/vendor-performance/VendorPerformanceIndex.vue'
import VendorPerformanceDetail from './components/vendor-performance/VendorPerformanceDetail.vue'

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

// bookkeeping-provincies-bbv-variant (REQ-BBC-001..004 / REQ-BBL-001..005):
// the provincies BBV pages. Three kind:"page" components, each justified
// against a built-in page type:
//
//   * BbvComplianceDashboard — a fixed statutory layout (four named KPI
//     cards → two charts → an overspend exceptions block) whose Remaining
//     figure spans two sources and whose exceptions block is a domain rule
//     (Remaining < 0), not a widget. `type: "dashboard"` renders a
//     GridStack canvas of library-owned widgets, which is a different
//     surface. Same call the waterschappen variant made.
//   * BudgetToProgrammeLinker — the built-in `index` page has no vocabulary
//     for the three affordances the spec requires: the mapping-status badge
//     (REQ-BBL-004), the declared filter facets, and the bulk "Link to
//     Programme" action with its dialog (REQ-BBL-001). It still wraps
//     CnIndexPage for the table itself.
//   * BudgetToProgrammeLinkerDetail — the ledger fields are read-only and
//     only the two assignment fields are editable, which the schema-driven
//     `detail` form cannot express.
//
// ADR-024 / ADR-036.
import BbvProvincieComplianceDashboard from './components/BbvProvincie/BbvProvincieComplianceDashboard.vue'
import BudgetToProgrammeLinker from './components/BbvProvincie/BudgetToProgrammeLinker.vue'
import BudgetToProgrammeLinkerDetail from './components/BbvProvincie/BudgetToProgrammeLinkerDetail.vue'

// bookkeeping-multi-administratie (Task 13): the in-session administration
// switcher is a custom page hosting the AdministratieSwitcher dropdown. It is
// genuinely custom (NOT a declarative index/detail over a register) because it
// reads `/api/administrations/context`, posts to `/api/administrations/switch`
// and triggers a session-level reload — none of which is expressible in a
// built-in page type. See AdministratieSwitcher docblock for the kind-page
// justification per ADR-024 / ADR-036.
import AdministrationSwitcherPage from './views/AdministrationSwitcherPage.vue'

// bookings-resource-calendar (#117, REQ-006/REQ-007): the multi-resource
// CalendarView + BookingForm pages live under src/views/bookings/. They target
// the /api/v2/calendars REST surface (Resource / Calendar / Booking schemas in
// the shillinq register). Justified per ADR-024 (imperative time grid +
// inline conflict dialog).
//
// ⚠️ CORRECTION. This comment used to say that the old "Verkoop → Bookings
// calendar" host page (CalendarPage.vue) "wrapped this same CalendarView" and
// was therefore removed as duplicate navigation under
// shillinq-manifest-boot-payload-reduction (REQ-MBP-002). That is not what it
// wrapped. `git show 0c23c1e4^:src/views/bookings/CalendarPage.vue` imports
// `../../components/CalendarView.vue`, whereas the line below imports
// `./views/bookings/CalendarView.vue` — two different components with two
// different sets of test ids. Removing the host did not delete a duplicate; it
// deleted the ONLY listener for the grid's `slot:clicked` event, leaving the
// manifest to mount the grid bare and REQ-007's "click a slot to create a
// booking" with nothing on the other end.
//
// CalendarPage below restores that host on the canonical component pair. It is
// the page component for the single `BookingsCalendarView` manifest entry, so
// REQ-MBP-002 ("one navigation entry per feature") still holds — the feature
// went from zero navigation entries to exactly one.
// CalendarView itself is no longer imported here — CalendarPage imports it, so
// it is still bundled, but the registry now resolves the manifest page to the
// host rather than to the bare grid.
import CalendarPage from './views/bookings/CalendarPage.vue'
import BookingForm from './views/bookings/BookingForm.vue'

// bookkeeping-wbso-sno-administratie (REQ-WBSO-006/002/003): the three
// bookkeeping foundation views are imperative — the Chart of Accounts is a
// hierarchical RGS tree (no built-in tree page type), and the Transactions /
// Documents tables fan out to dedicated REST surfaces that filter by status,
// type, and date range. Registered as kind:"page" custom components per
// ADR-024 / ADR-036.
import WbsoChartOfAccountsView from './views/bookkeeping/ChartOfAccountsView.vue'
import WbsoTransactionsView from './views/bookkeeping/TransactionsView.vue'
import WbsoDocumentsView from './views/bookkeeping/DocumentsView.vue'

// bookings-self-service-widget (REQ-WSW-009): per-business widget API-key
// admin view. The page is wired through src/manifest.d/30-bookings-self-service-widget.json
// and gated by #[AuthorizedAdminSetting] on the WidgetSettingsController
// — it cannot be exposed publicly via the in-app router unless the
// server-side attribute is dropped. ADR-004 compliant.
import BookingWidgetKeys from './views/BookingWidgetKeys.vue'

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

// add-shillinq-multi-currency Task 14: the FxRatesAdmin page wraps the
// declarative FxRate index grid with a cron-status overlay (last-run
// timestamp + TreasuryRateAdapter dormancy flag) read from
// /api/admin/fx-rate-import-status. The header strip + dormancy hint do
// not fit any built-in `index` / `detail` / `dashboard` page type, so the
// page is registered as a kind:"page" custom component per ADR-024.
// Admin-only — the controller is gated by
// #[AuthorizedAdminSetting(Application::class)].
import FxRatesAdmin from './views/bookkeeping/multi-currency/FxRatesAdmin.vue'

// bookkeeping-cost-centers-dimensions Task 14 (W6): the SegmentPnLDashboard
// composes server-side aggregations declared on GLLine
// (byCostCenter / byCostCenterHierarchy / byProject /
// byAnalyticalDimension) into one operator-facing P&L drill-down. The
// dashboard owns segment selection, hierarchical roll-up rendering, and a
// CSV export — none of which fit any built-in declarative page type, so
// the page is registered as a kind:"page" custom component per ADR-024.
import SegmentPnLDashboard from './views/bookkeeping/dimensions/SegmentPnLDashboard.vue'

// verplichtingen-commitment-accounting Task 4 (REQ-VPL-011): the
// BudgetLineCommitments drilldown composes the declarative
// committedVsRealisedPerBudgetLine aggregation declared on
// Verplichtingsregel into a per-budget-line report with a commitment
// drilldown. Registered as a kind:"page" custom component per ADR-024
// (same pattern as SegmentPnLDashboard).
import BudgetLineCommitments from './views/BudgetLineCommitments.vue'

// compliance-deadline-calendar (REQ-CDC-006): per-user category toggles
// + reminder lead times for the compliance deadline calendar. Talks to
// the strictly current-user-scoped /api/deadline-calendar/settings
// endpoints; registered as a kind:"page" custom component per ADR-024.
import DeadlineCalendarSettings from './views/DeadlineCalendarSettings.vue'

// Shillinq W8 (external-adapters admin UIs): the External Connections
// section renders an operator roll-up + per-adapter activation panel
// for the 14 dormant external-API adapter ports (Digipoort/SBR,
// Salarisbureau, RvO, IB47, CBS Bestanden, CBS Iv3, BZK SiSa,
// Mollie, Bunq, KvK, UWV, Treasury Rates, CCM Rule Engine,
// CSRD ESRS XBRL, DepositPayment). Each page reads
// /api/admin/external-adapters and surfaces the dormancy badge +
// activation steps (config keys, openconnector source slug, feature
// flag). Neither view fits a built-in `index` / `detail` page type:
// the data is not an OR register (it's an in-controller registry of
// adapter metadata), and the detail page renders an ordered
// activation checklist + per-row code blocks. Both are kind:"page"
// custom components per ADR-024 / ADR-036. The detail page is
// reused for all 14 families by passing a slug via the manifest
// page entry's `props.adapterId`.
import ExternalAdaptersStatus from './views/external-adapters/ExternalAdaptersStatus.vue'
import ExternalAdapterDetail from './views/external-adapters/ExternalAdapterDetail.vue'

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

// add-invoice-pdf-export-with-ubl-peppol-support (REQ-EINV-007): the Send
// e-invoice action + delivery-status indicator on the manifest-driven
// ARInvoiceDetail page. Resolved as the page's `actionsComponent` (ADR-036 —
// any registry kind is eligible for slot/actions resolution, only page
// dispatch itself requires kind:"page"), mounted into CnDetailPage's actions
// header slot with `{ object, objectId, schema, objectType, store }`.
import AREInvoiceActions from './components/ar-invoice/AREInvoiceActions.vue'

// recurring-invoicing (REQ-RIN-008, Task 15): the create/edit modal for a
// RecurringInvoiceProfile is a bespoke multi-line form with period-token
// preview and an inline next-invoice projection — none of which the
// generic schema-driven create form can author. It is launched from the
// Recurring Invoices index page's "New recurring profile" action
// (src/manifest.d/recurring-invoicing.json, type:"modal") so it must be
// resolvable by component id; registered here as a kind:"modal" and
// modal-isolated under src/modals/ (hydra gate-13).
import RecurringInvoiceProfileModal from './modals/RecurringInvoiceProfileModal.vue'

// ADR-049 Phase-4 dissolution: modals formerly launched by the imperative
// FinancialDashboardActions / PaymentRunDetailActions widgets. Those action
// ribbons are now declarative `config.headerActions[]` (type:"open-modal");
// each modal is registered here as a kind:"modal" so the manifest action's
// `target` resolves it. Modal-isolated under src/modals/ (hydra gate-13).
import InvoiceQuickDraftModal from './modals/InvoiceQuickDraftModal.vue'
import BillImportModal from './modals/BillImportModal.vue'
import PaymentRunReconcileModal from './modals/PaymentRunReconcileModal.vue'

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
import GeneratedReportsIndex from './components/reporting/GeneratedReportsIndex.vue'

// Import bank statements lives under the Configuratie (settings) group as a
// page that hosts BankStatementWizard — moved off the Financial overview
// dashboard so it sits with the other one-time setup tasks. manifest fragment
// src/manifest.d/bank-import-settings.json declares the route + menu entry.
import BankImportPage from './components/settings/BankImportPage.vue'

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

export default {
	RecurringInvoiceProfileModal: { kind: 'modal', component: RecurringInvoiceProfileModal },

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

	BookingsConfirmationPortal: { kind: 'page', component: BookingsConfirmationPortal },
	AfspraakDetail: { kind: 'page', component: AfspraakDetail },
	ReceiptCapture: { kind: 'page', component: ReceiptCapture },

	PurchaseOrderForm: { kind: 'page', component: PurchaseOrderForm },
	PurchaseOrderDetail: { kind: 'page', component: PurchaseOrderDetail },

	GoodsReceiptNoteForm: { kind: 'page', component: GoodsReceiptNoteForm },
	GoodsReceiptNoteDetail: { kind: 'page', component: GoodsReceiptNoteDetail },

	SupplierInvoiceDetail: { kind: 'page', component: SupplierInvoiceDetail },

	ThreeWayMatchIndex: { kind: 'page', component: ThreeWayMatchIndex },

	ThreeWayMatchExceptionPanel: { kind: 'page', component: ThreeWayMatchExceptionPanel },

	VendorPerformanceIndex: { kind: 'page', component: VendorPerformanceIndex },
	VendorPerformanceDetail: { kind: 'page', component: VendorPerformanceDetail },

	BBVComplianceDashboard: { kind: 'page', component: BBVComplianceDashboard },

	BudgetBBVMappingIndex: { kind: 'page', component: BudgetBBVMappingIndex },

	BudgetBBVMappingDetail: { kind: 'page', component: BudgetBBVMappingDetail },
	BudgetMappingDetail: { kind: 'page', component: BudgetBBVMappingDetail },

	// bookkeeping-provincies-bbv-variant. Note the ids differ from the
	// waterschappen trio above — the two BBV variants have separate
	// programme taxonomies, separate routes and separate dashboards.
	BbvProvincieComplianceDashboard: { kind: 'page', component: BbvProvincieComplianceDashboard },
	BudgetToProgrammeLinker: { kind: 'page', component: BudgetToProgrammeLinker },
	BudgetToProgrammeLinkerDetail: { kind: 'page', component: BudgetToProgrammeLinkerDetail },

	AdministrationSwitcherPage: { kind: 'page', component: AdministrationSwitcherPage },

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

	// compliance-deadline-calendar (REQ-CDC-006): deadline calendar settings.
	DeadlineCalendarSettings: { kind: 'page', component: DeadlineCalendarSettings },

	// Shillinq W8: External Connections admin pages (index + per-adapter detail).
	ExternalAdaptersStatus: { kind: 'page', component: ExternalAdaptersStatus },
	ExternalAdapterDetail: { kind: 'page', component: ExternalAdapterDetail },

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

	// add-invoice-pdf-export-with-ubl-peppol-support (REQ-EINV-007).
	AREInvoiceActions: {
		kind: 'widget',
		component: AREInvoiceActions,
		_note: 'Header actionsComponent, not a data widget: pairs a stateful Send e-invoice action (POST + optimistic status update + inline validation errors) with the delivery-status chip — no built-in widget performs writes.',
	},

	// reporting-compliance-consolidation: Reporting & Compliance pages.
	ReportingComplianceOverview: { kind: 'page', component: ReportingComplianceOverview },
	GeneratedReportsIndex: { kind: 'page', component: GeneratedReportsIndex },
	BankImportPage: { kind: 'page', component: BankImportPage },

	// accountant-portal: scoped multi-client dashboard + handover-pack export.
	AccountantPortalDashboard: { kind: 'page', component: AccountantPortalDashboard },
}
