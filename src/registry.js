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
import PickPage from './views/inventory/PickPage.vue'
import CountPage from './views/inventory/CountPage.vue'
// bookings-resource-calendar exception (#117, per design.md):
//   The booking month/week/day grid and the conflict-aware booking form are
//   imperative Vue components — they own a custom calendar shell, modal
//   isolation for the conflict dialog, and an inline POST → 409 retry path
//   that does not fit any built-in index/detail/dashboard/settings page
//   type. Registered here as a kind:"page" custom component so the
//   manifest router still owns the URL → component mapping.
import BookingsCalendarPage from './views/bookings/CalendarPage.vue'

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

// bookkeeping-multi-administratie (Task 13): the in-session administration
// switcher is a custom page hosting the AdministratieSwitcher dropdown. It is
// genuinely custom (NOT a declarative index/detail over a register) because it
// reads `/api/administrations/context`, posts to `/api/administrations/switch`
// and triggers a session-level reload — none of which is expressible in a
// built-in page type. See AdministratieSwitcher docblock for the kind-page
// justification per ADR-024 / ADR-036.
import AdministrationSwitcherPage from './views/AdministrationSwitcherPage.vue'

// bookings-resource-calendar (#117, REQ-006/REQ-007): the legacy multi-resource
// CalendarView + BookingForm pages live under src/views/bookings/. They target
// the /api/v2/calendars REST surface (Resource / Calendar / Booking schemas in
// the shillinq register) — distinct from the customer-confirmation
// BookingsCalendarPage above. Both are justified per ADR-024 (imperative time
// grid + inline conflict dialog).
import CalendarView from './views/bookings/CalendarView.vue'
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

// financial-dashboard-graphs: the Financial overview widgets are
// imperative — the KPI strip, the four charts and the two
// open-invoice tables all derive their series client-side from a
// shared fetch-once data layer (GL classification by accountType,
// month bucketing, CashflowWeek roll-up), and margin / billable
// carry a €/% resp. total/% view toggle. None of that fits the
// declarative `stats-block` / `chart` / `table` widget types, so
// they are registered as kind:"widget" components and wired into
// the Dashboard page through its `slots` map (ADR-024 / ADR-036).
import FinanceKpisWidget from './components/dashboard/financial/FinanceKpisWidget.vue'
import TurnoverChartWidget from './components/dashboard/financial/TurnoverChartWidget.vue'
import MarginChartWidget from './components/dashboard/financial/MarginChartWidget.vue'
import CashflowChartWidget from './components/dashboard/financial/CashflowChartWidget.vue'
import BillableHoursChartWidget from './components/dashboard/financial/BillableHoursChartWidget.vue'
import OpenInvoicesTableWidget from './components/dashboard/financial/OpenInvoicesTableWidget.vue'
import FinancialDashboardActions from './components/dashboard/financial/FinancialDashboardActions.vue'

export default {
	MobileScannerHome: { kind: 'page', component: MobileScannerHome },
	MobileScannerReceive: { kind: 'page', component: ReceivePage },
	MobileScannerTransfer: { kind: 'page', component: TransferPage },
	MobileScannerPick: { kind: 'page', component: PickPage },
	MobileScannerCount: { kind: 'page', component: CountPage },

	InvoiceGenerator: { kind: 'page', component: InvoiceGenerator },
	AdminInvoiceList: { kind: 'page', component: AdminInvoiceList },
	AdminInvoiceDetail: { kind: 'page', component: AdminInvoiceDetail },

	BookingsCalendarPage: { kind: 'page', component: BookingsCalendarPage },
	BookingsConfirmationPortal: { kind: 'page', component: BookingsConfirmationPortal },
	AfspraakDetail: { kind: 'page', component: AfspraakDetail },

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
	AdministrationSwitcherPage: { kind: 'page', component: AdministrationSwitcherPage },

	// bookings-resource-calendar custom pages (REQ-006/REQ-007).
	BookingsCalendar: { kind: 'page', component: CalendarView },
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

	// Shillinq W8: External Connections admin pages (index + per-adapter detail).
	ExternalAdaptersStatus: { kind: 'page', component: ExternalAdaptersStatus },
	ExternalAdapterDetail: { kind: 'page', component: ExternalAdapterDetail },

	// financial-dashboard-graphs: Financial overview dashboard widgets.
	FinanceKpisWidget: { kind: 'widget', component: FinanceKpisWidget },
	TurnoverChartWidget: { kind: 'widget', component: TurnoverChartWidget },
	MarginChartWidget: { kind: 'widget', component: MarginChartWidget },
	CashflowChartWidget: { kind: 'widget', component: CashflowChartWidget },
	BillableHoursChartWidget: { kind: 'widget', component: BillableHoursChartWidget },
	OpenInvoicesTableWidget: { kind: 'widget', component: OpenInvoicesTableWidget },

	// financial-dashboard-actions: quick-access header buttons (Import bill /
	// Create invoice / Import bank) registered as kind:"widget" so the manifest
	// actionsComponent field resolves to this entry via CnPageRenderer.
	FinancialDashboardActions: { kind: 'widget', component: FinancialDashboardActions },
}
