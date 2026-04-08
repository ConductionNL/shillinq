# Design: General — Shillinq

## Architecture Overview

This change layers five capability groups on top of the core infrastructure. All new entities follow the same OpenRegister thin-client pattern: no own database tables, all data via `ObjectService`. New PHP components handle portal authentication, automation rule evaluation, and batch operations; the Vue 2.7 + Pinia frontend handles all rendering.

```
Browser (Vue 2.7 + Pinia)
    │
    ├─ OpenRegister REST API  (KpiWidget, AnalyticsReport, AutomationRule,
    │                          ExpenseClaim, ExpenseItem, PortalToken CRUD)
    │
    └─ Shillinq OCS API
            ├─ PortalController       (token auth, scoped invoice/payment reads)
            ├─ AnalyticsController    (aggregate queries, report snapshots)
            ├─ BulkActionController   (batch approve / delete / assign)
            └─ AutomationRuleController (manual trigger, rule preview)
                    │
                    └─ PHP Services
                            ├─ PortalService
                            ├─ AnalyticsService
                            ├─ BulkActionService
                            └─ AutomationRuleEvaluator
```

## Data Model

### KpiWidget (`schema:Thing`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| title | string | Yes | — | Widget display name |
| metricKey | string | Yes | — | Identifier, e.g. `total_receivables` |
| chartType | string | Yes | number | number / line / bar / donut |
| schemaRef | string | No | — | OpenRegister schema to aggregate |
| filterJson | string | No | — | JSON-encoded filter expression |
| compareWith | string | No | previous_period | previous_period / previous_year / budget |
| userId | string | No | — | Owner; null = shared |
| sortOrder | integer | No | 0 | Position on dashboard |

### AnalyticsReport (`schema:Report`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| title | string | Yes | — | Report name |
| description | string | No | — | |
| reportType | string | Yes | — | debtors_ageing / budget_vs_actual / cash_flow / custom |
| parameters | string | No | — | JSON-encoded report parameters |
| snapshotData | string | No | — | JSON snapshot of last run result |
| lastRunAt | datetime | No | — | |
| scheduledCron | string | No | — | Cron expression for auto-run |
| userId | string | No | — | Owner |

### PortalToken (`schema:DigitalDocument`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| tokenHash | string | Yes | — | `password_hash()` of the raw token |
| organizationId | string | Yes | — | OpenRegister object ID of the linked `Organization` |
| description | string | No | — | Human label, e.g. "Supplier ABC access" |
| expiresAt | datetime | No | — | Null = never expires |
| lastUsedAt | datetime | No | — | |
| isActive | boolean | Yes | true | |
| permissions | array | No | [] | Scoped permissions: invoice_read / payment_read |

### AutomationRule (`schema:Action`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| name | string | Yes | — | Rule display name |
| triggerSchema | string | Yes | — | Schema monitored, e.g. `Invoice` |
| triggerField | string | Yes | — | Field to watch, e.g. `ageInDays` |
| triggerOperator | string | Yes | — | gt / lt / eq / gte / lte |
| triggerValue | string | Yes | — | Comparison value (stored as string) |
| actionType | string | Yes | — | send_notification / change_status / escalate |
| actionParams | string | No | — | JSON-encoded action parameters |
| isActive | boolean | Yes | true | |
| lastEvaluatedAt | datetime | No | — | |
| matchCount | integer | No | 0 | Cumulative count of rule matches |

### ExpenseClaim (`schema:Invoice`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| claimNumber | string | Yes | — | Auto-generated, e.g. `EXP-2026-0042` |
| employeeId | string | Yes | — | Nextcloud userId of the claimant |
| description | string | Yes | — | Purpose of claim |
| status | string | Yes | draft | draft / submitted / under_review / approved / rejected / paid |
| totalAmount | number | No | 0 | Sum of all `ExpenseItem.amount` |
| currency | string | No | EUR | ISO 4217 |
| budgetId | string | No | — | OpenRegister object ID of linked `Budget` |
| approvalWorkflowId | string | No | — | OpenRegister object ID of `ApprovalWorkflow` |
| submittedAt | datetime | No | — | |
| decidedAt | datetime | No | — | |
| rejectionReason | string | No | — | |

### ExpenseItem (`schema:Thing`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| expenseClaimId | string | Yes | — | Parent `ExpenseClaim` object ID |
| category | string | Yes | — | travel / accommodation / meals / equipment / other |
| description | string | Yes | — | Item description |
| amount | number | Yes | — | Item amount |
| currency | string | No | EUR | ISO 4217 |
| receiptDate | datetime | No | — | Date on the receipt |
| receiptFile | file | No | — | Attached receipt (Nextcloud file reference) |
| vatAmount | number | No | 0 | VAT portion |
| vatRate | number | No | 0 | VAT percentage |

## OpenRegister Register Updates

New schemas to add to `lib/Settings/shillinq_register.json`:

- `KpiWidget`
- `AnalyticsReport`
- `PortalToken`
- `AutomationRule`
- `ExpenseClaim`
- `ExpenseItem`

## Backend Components

### `lib/Controller/PortalController.php`
OCS API controller with token-based authentication middleware. Routes:
- `POST /apps/shillinq/api/v1/portal/auth` — validates raw token, returns scoped session cookie
- `GET /apps/shillinq/api/v1/portal/invoices` — returns invoices scoped to the token's `organizationId`
- `GET /apps/shillinq/api/v1/portal/payments` — returns payment records scoped to the organisation

Token validation uses `password_verify()` against stored `tokenHash`. All portal routes check `PortalToken.isActive` and `expiresAt`.

### `lib/Controller/AnalyticsController.php`
OCS API controller. Routes:
- `GET /apps/shillinq/api/v1/analytics/kpi/{metricKey}` — returns current value + trend for a metric key
- `POST /apps/shillinq/api/v1/analytics/reports/{reportType}/run` — executes a report and returns snapshot data
- `GET /apps/shillinq/api/v1/analytics/reports/{id}/snapshot` — returns last saved snapshot

### `lib/Controller/BulkActionController.php`
OCS API controller. Routes:
- `POST /apps/shillinq/api/v1/bulk/{schema}/approve` — accepts `{ ids: [] }`, approves all matching objects
- `POST /apps/shillinq/api/v1/bulk/{schema}/delete` — accepts `{ ids: [] }`, deletes all
- `POST /apps/shillinq/api/v1/bulk/{schema}/assign` — accepts `{ ids: [], assigneeId: string }`

### `lib/BackgroundJob/AutomationRuleJob.php`
Extends `OC\BackgroundJob\TimedJob`. Runs every 15 minutes. Fetches all active `AutomationRule` objects, evaluates each against OpenRegister objects of `triggerSchema`, and executes the configured `actionType`.

### `lib/Service/PortalService.php`
Handles token generation (raw 32-byte random token + `password_hash` storage), token validation, and scoped data retrieval.

### `lib/Service/AnalyticsService.php`
Computes KPI values by querying OpenRegister's aggregate/filter API. Handles period comparison calculations (vs. previous period or budget).

### `lib/Service/BulkActionService.php`
Executes batch operations against OpenRegister `ObjectService`. Validates each object before acting; collects per-object errors; returns a `{ succeeded: N, failed: N, errors: [] }` summary.

### `lib/Service/AutomationRuleEvaluator.php`
Evaluates a single `AutomationRule` against a set of objects. Supported operators: `gt`, `lt`, `eq`, `gte`, `lte`. Supported actions: `send_notification` (via `NotificationService`), `change_status` (updates object field via `ObjectService`), `escalate` (creates an escalation entry and notifies the CFO).

## Frontend Components

### Directory Structure

```
src/
  views/
    analytics/
      AnalyticsDashboard.vue      # Configurable KPI dashboard
      KpiWidgetCard.vue           # Single KPI card with chart
      AnalyticsReportList.vue     # CnIndexPage for saved reports
      AnalyticsReportDetail.vue   # CnDetailPage for a report
      AnalyticsReportRun.vue      # Run dialog with parameters
    portal/
      PortalInvoiceList.vue       # Token-authenticated invoice list
      PortalTokenList.vue         # Admin: manage portal tokens
      PortalTokenDetail.vue       # CnDetailPage for a token
    automationRule/
      AutomationRuleList.vue      # CnIndexPage for rules
      AutomationRuleDetail.vue    # CnDetailPage
      AutomationRuleForm.vue      # Create/edit dialog
    expenseClaim/
      ExpenseClaimList.vue        # CnIndexPage for claims
      ExpenseClaimDetail.vue      # CnDetailPage with items sub-list
      ExpenseClaimForm.vue        # Multi-step submission dialog
      ExpenseItemForm.vue         # Add/edit single item
  components/
    BulkActionBar.vue             # Bulk select + action menu
    QuickActionsPanel.vue         # Floating quick-action shortcuts
    KpiChart.vue                  # Chart.js wrapper (line/bar/donut)
  store/
    modules/
      analytics.js                # createObjectStore('KpiWidget') + report state
      portal.js                   # createObjectStore('PortalToken')
      automationRule.js           # createObjectStore('AutomationRule')
      expenseClaim.js             # createObjectStore('ExpenseClaim')
      expenseItem.js              # createObjectStore('ExpenseItem')
```

### Analytics Dashboard Pattern

```vue
<!-- AnalyticsDashboard.vue -->
<template>
  <div class="analytics-dashboard">
    <draggable v-model="widgets" @end="persistOrder">
      <KpiWidgetCard
        v-for="widget in widgets"
        :key="widget.id"
        :widget="widget"
        :data="kpiData[widget.metricKey]"
      />
    </draggable>
    <NcButton @click="openWidgetDialog">Add Widget</NcButton>
  </div>
</template>
```

### Bulk Action Bar Pattern

```vue
<!-- BulkActionBar.vue — used inside CnIndexPage slot -->
<template>
  <div v-if="selectedIds.length > 0" class="bulk-action-bar">
    <span>{{ selectedIds.length }} selected</span>
    <NcActions>
      <NcActionButton @click="bulkApprove">Approve</NcActionButton>
      <NcActionButton @click="bulkExport">Export</NcActionButton>
      <NcActionButton @click="bulkDelete" class="destructive">Delete</NcActionButton>
    </NcActions>
  </div>
</template>
```

### Expense Claim Multi-Step Form

Steps: (1) Claim Details → (2) Add Items → (3) Attach Receipts → (4) Review & Submit

```vue
<!-- ExpenseClaimForm.vue -->
<NcDialog :name="t('shillinq', 'New Expense Claim')">
  <NcStepper :steps="['Details', 'Items', 'Receipts', 'Review']" :current="step">
    <template #step-1><ClaimDetailsFields /></template>
    <template #step-2><ExpenseItemsTable /></template>
    <template #step-3><ReceiptUpload /></template>
    <template #step-4><ClaimReviewSummary /></template>
  </NcStepper>
</NcDialog>
```

## Seed Data

Location: extend `lib/Repair/CreateDefaultConfiguration.php`.

```php
// KpiWidget seed — default CFO dashboard
$this->seedObject('KpiWidget', 'metricKey', 'total_receivables', [
    'title'      => 'Total Receivables',
    'metricKey'  => 'total_receivables',
    'chartType'  => 'number',
    'compareWith' => 'previous_period',
    'sortOrder'  => 1,
]);

$this->seedObject('KpiWidget', 'metricKey', 'overdue_invoices', [
    'title'      => 'Overdue Invoices',
    'metricKey'  => 'overdue_invoices',
    'chartType'  => 'number',
    'compareWith' => 'previous_period',
    'sortOrder'  => 2,
]);

$this->seedObject('KpiWidget', 'metricKey', 'cash_position', [
    'title'      => 'Cash Position',
    'metricKey'  => 'cash_position',
    'chartType'  => 'line',
    'compareWith' => 'previous_year',
    'sortOrder'  => 3,
]);

// AutomationRule seed — 30-day invoice reminder
$this->seedObject('AutomationRule', 'name', 'Invoice 30-day Reminder', [
    'name'            => 'Invoice 30-day Reminder',
    'triggerSchema'   => 'Invoice',
    'triggerField'    => 'ageInDays',
    'triggerOperator' => 'gte',
    'triggerValue'    => '30',
    'actionType'      => 'send_notification',
    'actionParams'    => '{"subject":"Invoice overdue","template":"invoice_reminder"}',
    'isActive'        => true,
    'matchCount'      => 0,
]);

// ExpenseClaim seed — demo submitted claim
$this->seedObject('ExpenseClaim', 'claimNumber', 'EXP-DEMO-0001', [
    'claimNumber'  => 'EXP-DEMO-0001',
    'employeeId'   => 'admin',
    'description'  => 'Demo conference travel expenses',
    'status'       => 'approved',
    'totalAmount'  => 345.50,
    'currency'     => 'EUR',
]);
```

## Affected Files

**New PHP**
- `lib/Controller/PortalController.php`
- `lib/Controller/AnalyticsController.php`
- `lib/Controller/BulkActionController.php`
- `lib/BackgroundJob/AutomationRuleJob.php`
- `lib/Service/PortalService.php`
- `lib/Service/AnalyticsService.php`
- `lib/Service/BulkActionService.php`
- `lib/Service/AutomationRuleEvaluator.php`

**New Vue / JS**
- `src/views/analytics/AnalyticsDashboard.vue`
- `src/views/analytics/KpiWidgetCard.vue`
- `src/views/analytics/AnalyticsReportList.vue`
- `src/views/analytics/AnalyticsReportDetail.vue`
- `src/views/analytics/AnalyticsReportRun.vue`
- `src/views/portal/PortalInvoiceList.vue`
- `src/views/portal/PortalTokenList.vue`
- `src/views/portal/PortalTokenDetail.vue`
- `src/views/automationRule/AutomationRuleList.vue`
- `src/views/automationRule/AutomationRuleDetail.vue`
- `src/views/automationRule/AutomationRuleForm.vue`
- `src/views/expenseClaim/ExpenseClaimList.vue`
- `src/views/expenseClaim/ExpenseClaimDetail.vue`
- `src/views/expenseClaim/ExpenseClaimForm.vue`
- `src/views/expenseClaim/ExpenseItemForm.vue`
- `src/components/BulkActionBar.vue`
- `src/components/QuickActionsPanel.vue`
- `src/components/KpiChart.vue`
- `src/store/modules/analytics.js`
- `src/store/modules/portal.js`
- `src/store/modules/automationRule.js`
- `src/store/modules/expenseClaim.js`
- `src/store/modules/expenseItem.js`

**Modified**
- `lib/Settings/shillinq_register.json` — add 6 new schemas
- `lib/Repair/CreateDefaultConfiguration.php` — add seed objects
- `appinfo/routes.php` — add portal, analytics, bulk routes
- `appinfo/info.xml` — register `AutomationRuleJob` background job
- `lib/AppInfo/Application.php` — register new services
- `src/navigation/MainMenu.vue` — add Analytics, Portal, Automation, Expenses sections
- `src/router/index.js` — add new routes
