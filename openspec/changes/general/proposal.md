---
status: proposed
source: specter
features: [strategic-advisory, financial-analytics-dashboard, client-supplier-portal, process-automation, expense-management, bulk-operations]
---

# General — Shillinq

## Summary

Implements cross-cutting business intelligence and operational efficiency features for Shillinq: a strategic advisory analytics dashboard for CFOs and financial controllers, a client/supplier self-service portal, a process automation and workflow engine, employee expense management, and bulk operations with quick actions. These features address the highest-demand general capabilities identified in the Specter intelligence model and build directly on the core infrastructure change.

## Demand Evidence

Top features by market demand score:

- **strategic-advisory** (demand: 7) — highest-demand feature in this batch; CFOs and financial controllers need real-time KPIs, trend analysis, and forward-looking insights to replace manual spreadsheet consolidation.
- **job-transformation / process-automation** (demand: 2) — finance teams need automated approval chains, recurring invoice schedules, and rule-based routing to reduce manual hand-offs.
- **Website Builder (Community) / client-supplier-portal** (demand: 2) — organisations need a self-service portal where clients can view invoices and suppliers can track payment status without contacting the AP team.
- **People Experience suite / expense-management** (demand: 2) — employees need to submit and track expense claims; financial controllers need to review and approve them against budget.
- **efficiency / bulk-operations** (demand: 1) — high-volume AP/AR clerks need bulk approve, bulk export, and contextual quick actions to process batches without opening individual records.

Features with demand score 1 and priority `wont` (industry-4.0, enhanced-flexibility, can-make-you-want-them-all) are deferred to a follow-up spec.

Key stakeholder pain points addressed:

- **CFO**: lacks real-time financial visibility and relies on manual consolidation — the strategic advisory dashboard delivers live KPIs and trend charts.
- **Financial Controller**: spreadsheet-based reporting and data silos — the analytics dashboard and portal provide a single source of truth.
- **External Auditor**: fragmented audit trail across systems — structured activity logs on every automated workflow event support audit requirements.

## What Shillinq Already Has

After the core change:

- OpenRegister schemas for `Organization`, `AppSettings`, `Dashboard`, `DataJob`
- Entity list, detail, and form views using `CnIndexPage` / `CnDetailPage` / `CnFormDialog`
- Global search, admin settings, CSV import/export, sidebar navigation, breadcrumbs
- Nextcloud notification integration and user preferences

### What Is Missing

- No financial KPI or trend analytics views
- No configurable advisory dashboard with chart widgets
- No client/supplier self-service portal
- No process automation rules or workflow triggers
- No expense claim submission or approval flow
- No bulk select and bulk action support on list views
- No quick-action shortcuts panel

## Scope

### In Scope

1. **Strategic Advisory Dashboard** — configurable KPI dashboard (`src/views/analytics/`) with chart widgets (line, bar, donut) for financial metrics (total receivables, payables, cash position, budget vs. actual). Widgets are backed by OpenRegister aggregate queries. CFO and Financial Controller stakeholders are the primary consumers. Pinia store `src/store/modules/analytics.js`.

2. **Financial KPI Schemas** — OpenRegister schemas for `KpiWidget` and `AnalyticsReport` to persist dashboard configuration and saved report snapshots. Registered via `lib/Settings/shillinq_register.json`.

3. **Client/Supplier Self-Service Portal** — read-only OCS API endpoints (`lib/Controller/PortalController.php`) allowing authenticated external users to view their invoices and payment status. Vue views at `src/views/portal/` with token-based access. `PortalToken` schema stores access credentials.

4. **Process Automation Engine** — `AutomationRule` schema for defining trigger/action rules (e.g., "when invoice age > 30 days → send reminder", "when purchase order total > 10 000 EUR → escalate to CFO"). Rule evaluation runs in a scheduled background job (`lib/BackgroundJob/AutomationRuleJob.php`).

5. **Employee Expense Management** — `ExpenseClaim` and `ExpenseItem` schemas; submission form, approval workflow backed by existing `ApprovalWorkflow` entity; integration with `Budget` entity for budget-check on approval. Views at `src/views/expenseClaim/`.

6. **Bulk Operations** — bulk checkbox select on all `CnIndexPage` list views; bulk actions menu: Bulk Approve, Bulk Delete, Bulk Export, Bulk Assign. Implemented via a shared `BulkActionBar.vue` component and OCS batch endpoints.

7. **Quick Actions Panel** — collapsible floating action panel (`src/components/QuickActionsPanel.vue`) listing the 5 most-used actions per entity type, configurable via `AppSettings`.

8. **Seed data** — demo `KpiWidget`, `AnalyticsReport`, `AutomationRule`, `ExpenseClaim`, and `PortalToken` objects loaded via the repair step (ADR-016).

### Out of Scope

- Real-time WebSocket push for KPI updates — polling is sufficient for v1
- PDF report generation — separate change
- Full SCIM-based external user provisioning for portal access — deferred
- Payroll processing — out of Shillinq's scope
- Lower-demand features: industry-4.0 integrations, enhanced-flexibility custom fields, can-make-you-want-them-all upsell tooling

## Acceptance Criteria

1. GIVEN a CFO opens the analytics dashboard WHEN it loads THEN at least four KPI cards are displayed (total receivables, total payables, cash position, overdue invoices) each with a trend indicator vs. the previous period
2. GIVEN a Financial Controller configures a KPI widget WHEN they save the configuration THEN the widget is persisted as a `KpiWidget` OpenRegister object and reloads correctly on next login
3. GIVEN a supplier is assigned a portal token WHEN they access the portal URL THEN they can view only their own invoices and payment statuses, not any other entity's data
4. GIVEN an `AutomationRule` is configured with trigger "invoice_age > 30 days" WHEN the nightly job runs THEN matching invoices receive a payment reminder notification and an audit trail entry is created
5. GIVEN an employee submits an expense claim for 250 EUR WHEN the claim is submitted THEN the `ExpenseClaim` object is created with status `pending` and the approver receives a Nextcloud notification
6. GIVEN a financial controller is on the invoice list view WHEN they select 10 invoices and choose "Bulk Approve" THEN all 10 invoices are approved in a single API call and a summary toast confirms the count
7. GIVEN the quick actions panel is visible WHEN the user clicks "New Expense Claim" THEN the expense claim form dialog opens pre-populated with the current user's ID
8. GIVEN an automation rule fires WHEN the action completes THEN an `AuditTrail` entry is created with the rule ID, trigger details, affected object ID, and timestamp

## Risks and Dependencies

- **Core change prerequisite**: This change depends on the `core` change being merged first; `Organization`, `AppSettings`, and `DataJob` schemas must exist.
- **`ApprovalWorkflow` entity**: Referenced from Other Entities list — must exist in OpenRegister before the expense approval flow can link to it.
- **`Budget` entity**: Referenced for expense budget-check — must be defined before approval integration.
- **Chart library**: NL Design System and `@conduction/nextcloud-vue` do not include a chart library; use Nextcloud's bundled Chart.js instance (`OC.Util.chartjs`) rather than introducing a new dependency.
- **Portal token security**: Portal tokens must be hashed at rest (using PHP's `password_hash`), scoped to a single organisation, and time-limited.
- **Automation rule complexity**: Rule evaluation must be kept simple for v1 — support only field comparison triggers and notification/status-change actions; complex CEL expressions are deferred.
