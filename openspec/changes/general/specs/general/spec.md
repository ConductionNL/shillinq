---
status: proposed
---

# General — Shillinq

## Purpose

Defines functional requirements for Shillinq's cross-cutting business intelligence and operational efficiency features: strategic advisory analytics, a client/supplier self-service portal, a process automation engine, employee expense management, and bulk operations. These capabilities build on the core infrastructure and are consumed primarily by the CFO, Financial Controller, and External Auditor stakeholders.

Stakeholders: CFO, Financial Controller, External Auditor.

User stories addressed: View outstanding debtors dashboard, Track all open escalation requests, Approve matched invoice for payment, Archive supplier record.

## Requirements

### REQ-GEN-001: KPI Widget Schema and Registration [must]

The app MUST register `KpiWidget` and `AnalyticsReport` schemas in OpenRegister via `lib/Settings/shillinq_register.json`. Each schema MUST use a `schema.org` vocabulary annotation and include all properties from the data model.

**Scenarios:**

1. **GIVEN** Shillinq's repair step runs **WHEN** it completes **THEN** schemas `KpiWidget` and `AnalyticsReport` exist in the `shillinq` OpenRegister register with all specified properties and types.

2. **GIVEN** a `KpiWidget` is created without the required `metricKey` property **WHEN** the request is submitted to OpenRegister **THEN** a 422 validation error is returned specifying the missing field.

3. **GIVEN** a `KpiWidget` with `chartType: "donut"` **WHEN** retrieved via the OpenRegister API **THEN** the `chartType` field value is `"donut"` and `chartType` enum validation prevents values outside `["number","line","bar","donut"]`.

4. **GIVEN** an `AnalyticsReport` with `scheduledCron: "0 6 * * 1"` **WHEN** the background job evaluates the cron **THEN** the report runs every Monday at 06:00 and updates `snapshotData` and `lastRunAt`.

### REQ-GEN-002: Strategic Advisory Analytics Dashboard [must]

The app MUST provide a configurable KPI dashboard at `src/views/analytics/AnalyticsDashboard.vue`. The dashboard MUST display KPI widget cards with trend indicators, support adding and reordering widgets, and use Chart.js for graphical widgets.

**Scenarios:**

1. **GIVEN** a CFO navigates to the Analytics section **WHEN** the dashboard loads **THEN** at least three default KPI cards are displayed: Total Receivables, Overdue Invoices, and Cash Position, each showing the current value and a trend indicator (up/down/neutral) vs. the previous period.

2. **GIVEN** a KPI widget has `chartType: "line"` **WHEN** the card renders **THEN** a line chart is drawn using Nextcloud's bundled Chart.js instance showing at least 6 data points over the selected time range.

3. **GIVEN** a user clicks "Add Widget" on the analytics dashboard **WHEN** the widget configuration dialog opens **THEN** they can select a `metricKey` from a pre-defined list, choose a `chartType`, and set a `compareWith` value; saving creates a `KpiWidget` OpenRegister object and renders the new card immediately.

4. **GIVEN** a user drags a widget card to a new position **WHEN** the drag ends **THEN** the `sortOrder` values of affected widgets are updated in OpenRegister and the new order persists on page reload.

5. **GIVEN** the Financial Controller filters the debtors ageing report by cost centre **WHEN** the filter is applied **THEN** only receivables belonging to the selected department appear (user story: View outstanding debtors dashboard).

### REQ-GEN-003: Client/Supplier Self-Service Portal [should]

The app MUST provide token-authenticated portal access allowing external organisations to view their invoices and payment status without a Nextcloud account.

**Scenarios:**

1. **GIVEN** an admin generates a portal token for organisation "Acme BV" **WHEN** the token is created **THEN** a `PortalToken` object is stored with a hashed token, `organizationId` set to Acme BV's object ID, `isActive: true`, and the raw token is shown once to the admin.

2. **GIVEN** a supplier presents a valid portal token **WHEN** the portal endpoint validates the token **THEN** `password_verify()` succeeds, `lastUsedAt` is updated, and only invoices with `organizationId` matching the token's scope are returned.

3. **GIVEN** a portal token has passed its `expiresAt` datetime **WHEN** a request is made with that token **THEN** the API returns a 401 Unauthorized response and no invoice data is disclosed.

4. **GIVEN** a portal token has `isActive: false` **WHEN** used in an API request **THEN** the request is rejected with 401 regardless of expiry.

5. **GIVEN** a supplier views the portal invoice list **WHEN** the list renders **THEN** invoice columns show: invoice number, issue date, due date, amount, currency, and payment status — and no other organisation's data is visible.

### REQ-GEN-004: Process Automation Engine [must]

The app MUST provide an `AutomationRule` schema and a background job that evaluates active rules against OpenRegister objects on a scheduled interval and executes configured actions.

**Scenarios:**

1. **GIVEN** an `AutomationRule` with `triggerSchema: "Invoice"`, `triggerField: "ageInDays"`, `triggerOperator: "gte"`, `triggerValue: "30"`, `actionType: "send_notification"` **WHEN** the `AutomationRuleJob` runs **THEN** all invoices with `ageInDays >= 30` and no existing reminder sent in the last 7 days receive a Nextcloud notification.

2. **GIVEN** an `AutomationRule` with `actionType: "change_status"` and `actionParams: { "newStatus": "overdue" }` **WHEN** a matching invoice is found **THEN** the invoice's `status` field is updated to `"overdue"` via `ObjectService::updateObject()` and an `AuditTrail` entry is created.

3. **GIVEN** an `AutomationRule` with `actionType: "escalate"` **WHEN** a purchase order exceeding the threshold is found **THEN** an escalation record is created and the CFO receives a Nextcloud notification (user story: Track all open escalation requests).

4. **GIVEN** an `AutomationRule` has `isActive: false` **WHEN** the background job runs **THEN** the rule is skipped and no action is taken.

5. **GIVEN** the automation rule overview page loads **WHEN** an escalation has been open for more than 5 working days **THEN** it is highlighted with a warning indicator in the list view (user story: Track all open escalation requests).

6. **GIVEN** an `AutomationRule` is evaluated **WHEN** it matches at least one object **THEN** `matchCount` is incremented and `lastEvaluatedAt` is set to the current timestamp.

### REQ-GEN-005: Employee Expense Management [must]

The app MUST provide `ExpenseClaim` and `ExpenseItem` schemas with a multi-step submission form, an approval workflow linked to existing `ApprovalWorkflow` objects, and budget validation.

**Scenarios:**

1. **GIVEN** an employee opens the "New Expense Claim" form **WHEN** they complete all four steps (Details, Items, Receipts, Review) and click Submit **THEN** an `ExpenseClaim` object is created with `status: "submitted"`, `submittedAt` set to the current timestamp, and the assigned approver receives a Nextcloud notification.

2. **GIVEN** an expense claim is submitted for 250 EUR **WHEN** the approver reviews it **THEN** they can click "Approve" or "Reject"; approving sets `status: "approved"` and `decidedAt`; rejecting requires a `rejectionReason` and sets `status: "rejected"`.

3. **GIVEN** an expense claim is linked to a `Budget` object **WHEN** the approver clicks "Approve" **THEN** the system checks remaining budget; if insufficient, a warning is shown and the approver must confirm before proceeding.

4. **GIVEN** a `ExpenseItem` with `receiptFile` attached **WHEN** the item is saved **THEN** the file is stored as a Nextcloud file reference and accessible from the `ExpenseClaimDetail` view.

5. **GIVEN** a financial controller opens the expense claim list **WHEN** they filter by `status: "submitted"` **THEN** only claims awaiting review are shown, sorted by `submittedAt` ascending (oldest first).

6. **GIVEN** an approved expense claim **WHEN** a financial controller clicks "Approve for Payment" **THEN** the claim status changes to `paid` and a payment instruction reference is recorded (user story: Approve matched invoice for payment).

### REQ-GEN-006: Bulk Operations on List Views [must]

All entity list views MUST support multi-row selection and a bulk action bar offering at minimum Bulk Approve, Bulk Delete, and Bulk Export. Bulk actions MUST be executed via a single API call to the batch endpoint.

**Scenarios:**

1. **GIVEN** a financial controller is on the invoice list view **WHEN** they check the header checkbox **THEN** all visible rows on the current page are selected and the `BulkActionBar` appears with action options.

2. **GIVEN** 10 invoices are selected and the controller clicks "Bulk Approve" **WHEN** the OCS batch endpoint processes the request **THEN** all 10 invoices are approved atomically and a toast shows "10 invoices approved".

3. **GIVEN** a bulk delete is attempted on 5 records **WHEN** one record has a dependency preventing deletion **THEN** the remaining 4 are deleted, the failed record's error is listed in the response, and the toast shows "4 deleted, 1 failed".

4. **GIVEN** a filtered list of 30 expense claims is displayed **WHEN** the controller selects all and clicks "Bulk Export" **THEN** a CSV is downloaded containing exactly those 30 records with all visible columns (user story: Approve matched invoice for payment).

5. **GIVEN** no rows are selected **WHEN** the list renders **THEN** the `BulkActionBar` is hidden and does not occupy layout space.

### REQ-GEN-007: Quick Actions Panel [could]

The app MUST provide a collapsible `QuickActionsPanel` component listing the 5 most-used actions per entity context, configurable via `AppSettings`.

**Scenarios:**

1. **GIVEN** a user is on the expense claims list **WHEN** they click the quick actions toggle **THEN** the panel slides in showing shortcuts: New Expense Claim, Approve Selected, Export CSV, View Analytics, and Settings.

2. **GIVEN** an admin updates the `AppSettings` key `quickActions.expenseClaim` **WHEN** a user reopens the panel **THEN** the new action order is reflected without a page reload.

3. **GIVEN** the quick actions panel is open **WHEN** the user clicks "New Expense Claim" **THEN** the expense claim form dialog opens pre-populated with `employeeId` set to the current user's ID.

### REQ-GEN-008: Automation and Expense Seed Data [must]

The app MUST load seed data for `KpiWidget`, `AnalyticsReport`, `AutomationRule`, and `ExpenseClaim` entities during the repair step. Seed loading MUST be idempotent.

**Scenarios:**

1. **GIVEN** a fresh Shillinq installation **WHEN** the repair step runs **THEN** at least 3 `KpiWidget` objects, 1 `AnalyticsReport`, 1 `AutomationRule`, and 1 `ExpenseClaim` are created.

2. **GIVEN** the repair step has already run **WHEN** it runs again **THEN** no duplicate seed objects are created; the check uses `metricKey` for `KpiWidget`, `name` for `AutomationRule`, and `claimNumber` for `ExpenseClaim` as unique keys.

3. **GIVEN** the seeded `AutomationRule` "Invoice 30-day Reminder" **WHEN** the `AutomationRuleJob` runs **THEN** the rule is evaluated and its `lastEvaluatedAt` is updated.
