# Tasks: general

## 1. OpenRegister Schema Definitions

- [ ] 1.1 Add `KpiWidget` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN `shillinq_register.json` is processed by OpenRegister
    - THEN schema `KpiWidget` MUST be registered with properties: `title` (required), `metricKey` (required), `chartType` (required, enum), `schemaRef`, `filterJson`, `compareWith`, `userId`, `sortOrder`
    - AND `chartType` enum MUST be `["number","line","bar","donut"]`
    - AND `x-schema-org` annotation MUST be `schema:Thing`

- [ ] 1.2 Add `AnalyticsReport` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is registered
    - THEN properties `title` (required), `reportType` (required), `parameters`, `snapshotData`, `lastRunAt`, `scheduledCron`, `userId` MUST exist
    - AND `reportType` MUST have enum `["debtors_ageing","budget_vs_actual","cash_flow","custom"]`
    - AND `lastRunAt` MUST be `format: date-time`

- [ ] 1.3 Add `PortalToken` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-003`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is registered
    - THEN properties `tokenHash` (required), `organizationId` (required), `isActive` (required, default true), `expiresAt`, `lastUsedAt`, `description`, `permissions` MUST exist
    - AND `permissions` MUST be type `array`
    - AND `x-schema-org` MUST be `schema:DigitalDocument`

- [ ] 1.4 Add `AutomationRule` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-004`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is registered
    - THEN properties `name` (required), `triggerSchema` (required), `triggerField` (required), `triggerOperator` (required, enum), `triggerValue` (required), `actionType` (required, enum), `actionParams`, `isActive` (required), `lastEvaluatedAt`, `matchCount` MUST exist
    - AND `triggerOperator` enum MUST be `["gt","lt","eq","gte","lte"]`
    - AND `actionType` enum MUST be `["send_notification","change_status","escalate"]`

- [ ] 1.5 Add `ExpenseClaim` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-005`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is registered
    - THEN properties `claimNumber` (required), `employeeId` (required), `description` (required), `status` (required, enum), `totalAmount`, `currency`, `budgetId`, `approvalWorkflowId`, `submittedAt`, `decidedAt`, `rejectionReason` MUST exist
    - AND `status` enum MUST be `["draft","submitted","under_review","approved","rejected","paid"]`

- [ ] 1.6 Add `ExpenseItem` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-005`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is registered
    - THEN properties `expenseClaimId` (required), `category` (required, enum), `description` (required), `amount` (required), `currency`, `receiptDate`, `receiptFile`, `vatAmount`, `vatRate` MUST exist
    - AND `category` enum MUST be `["travel","accommodation","meals","equipment","other"]`
    - AND `receiptFile` MUST be type `file`

## 2. Seed Data

- [ ] 2.1 Add KpiWidget seed objects to `lib/Repair/CreateDefaultConfiguration.php`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-008`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - GIVEN the repair step runs on a fresh install
    - THEN 3 KpiWidget objects MUST be created: `total_receivables`, `overdue_invoices`, `cash_position`
    - AND idempotency check MUST use `metricKey` as the unique field
    - AND re-running MUST NOT create duplicates

- [ ] 2.2 Add AutomationRule seed object to Repair step
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-008`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - GIVEN a fresh install
    - THEN 1 AutomationRule object MUST be seeded: "Invoice 30-day Reminder" with `triggerSchema: Invoice`, `triggerField: ageInDays`, `triggerOperator: gte`, `triggerValue: 30`, `actionType: send_notification`, `isActive: true`
    - AND idempotency check MUST use `name` as the unique field

- [ ] 2.3 Add ExpenseClaim and AnalyticsReport seed objects to Repair step
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-008`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - GIVEN a fresh install
    - THEN 1 ExpenseClaim seed with `claimNumber: EXP-DEMO-0001`, `status: approved` MUST be created
    - AND 1 AnalyticsReport seed with `reportType: debtors_ageing` MUST be created
    - AND idempotency checks MUST use `claimNumber` and `title` as unique fields respectively

## 3. Pinia Stores

- [ ] 3.1 Create `src/store/modules/analytics.js`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-002`
  - **files**: `src/store/modules/analytics.js`
  - **acceptance_criteria**:
    - GIVEN the store is initialized
    - THEN `useAnalyticsStore` MUST be created via `createObjectStore('KpiWidget')`
    - AND the store MUST expose a `kpiData` map keyed by `metricKey`
    - AND the store MUST be registered in `src/store/store.js`

- [ ] 3.2 Create `src/store/modules/portal.js`
  - **files**: `src/store/modules/portal.js`
  - **acceptance_criteria**:
    - GIVEN the store is initialized
    - THEN `usePortalStore` MUST be created via `createObjectStore('PortalToken')`

- [ ] 3.3 Create `src/store/modules/automationRule.js`
  - **files**: `src/store/modules/automationRule.js`
  - **acceptance_criteria**:
    - GIVEN the store is initialized
    - THEN `useAutomationRuleStore` MUST be created via `createObjectStore('AutomationRule')`

- [ ] 3.4 Create `src/store/modules/expenseClaim.js` and `src/store/modules/expenseItem.js`
  - **files**: `src/store/modules/expenseClaim.js`, `src/store/modules/expenseItem.js`
  - **acceptance_criteria**:
    - GIVEN the stores are initialized
    - THEN `useExpenseClaimStore` MUST be created via `createObjectStore('ExpenseClaim')`
    - AND `useExpenseItemStore` MUST be created via `createObjectStore('ExpenseItem')`

## 4. Analytics Dashboard

- [ ] 4.1 Implement `src/views/analytics/AnalyticsDashboard.vue`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-002`
  - **files**: `src/views/analytics/AnalyticsDashboard.vue`
  - **acceptance_criteria**:
    - GIVEN the dashboard loads
    - THEN all `KpiWidget` objects for the current user MUST be fetched from `useAnalyticsStore()` and rendered as cards
    - AND an "Add Widget" button MUST open a configuration dialog
    - AND widgets MUST be sortable by drag-and-drop with `sortOrder` persisted on drop

- [ ] 4.2 Implement `src/views/analytics/KpiWidgetCard.vue`
  - **files**: `src/views/analytics/KpiWidgetCard.vue`
  - **acceptance_criteria**:
    - GIVEN a widget with `chartType: "number"` is rendered THEN a large metric value and trend indicator (↑ / ↓ / —) MUST be shown
    - GIVEN a widget with `chartType: "line"` or `"bar"` THEN a Chart.js chart using `OC.Util.chartjs` MUST be rendered with at least 6 data points
    - AND the trend indicator color MUST be green for positive, red for negative, grey for neutral

- [ ] 4.3 Implement `src/views/analytics/AnalyticsReportList.vue` and `AnalyticsReportDetail.vue`
  - **files**: `src/views/analytics/AnalyticsReportList.vue`, `src/views/analytics/AnalyticsReportDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the report list renders THEN `CnIndexPage` MUST list reports with columns: title, reportType, lastRunAt, scheduledCron
    - GIVEN a report detail renders THEN `CnDetailPage` MUST show all properties and a "Run Now" action button
    - AND clicking "Run Now" MUST call `POST /api/v1/analytics/reports/{reportType}/run` and update `snapshotData`

## 5. Portal Views

- [ ] 5.1 Implement `src/views/portal/PortalTokenList.vue` and `PortalTokenDetail.vue`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-003`
  - **files**: `src/views/portal/PortalTokenList.vue`, `src/views/portal/PortalTokenDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the token list renders THEN `CnIndexPage` MUST show columns: description, organizationId, isActive, expiresAt, lastUsedAt
    - AND a "Generate Token" action MUST call `PortalService::generateToken()`, store the hash, and display the raw token once in a modal with a copy button
    - GIVEN a token detail renders THEN `CnDetailPage` MUST show all properties and a "Deactivate" action that sets `isActive: false`

- [ ] 5.2 Implement `src/views/portal/PortalInvoiceList.vue`
  - **files**: `src/views/portal/PortalInvoiceList.vue`
  - **acceptance_criteria**:
    - GIVEN a valid portal token is present in the session
    - THEN invoice list MUST show only invoices scoped to the token's `organizationId`
    - AND columns MUST include: invoice number, issue date, due date, amount, currency, payment status
    - AND no Nextcloud authentication MUST be required — portal token auth only

## 6. Automation Rule Views and Background Job

- [ ] 6.1 Create `src/views/automationRule/AutomationRuleList.vue` and `AutomationRuleDetail.vue`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-004`
  - **files**: `src/views/automationRule/AutomationRuleList.vue`, `src/views/automationRule/AutomationRuleDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the rule list renders THEN `CnIndexPage` MUST show columns: name, triggerSchema, triggerField, actionType, isActive (toggle), matchCount, lastEvaluatedAt
    - AND rules with `isActive: false` MUST be visually dimmed
    - GIVEN a rule detail renders THEN `CnDetailPage` MUST show all properties and a "Test Rule" action that previews matching objects without executing the action

- [ ] 6.2 Create `src/views/automationRule/AutomationRuleForm.vue`
  - **files**: `src/views/automationRule/AutomationRuleForm.vue`
  - **acceptance_criteria**:
    - GIVEN the form opens THEN fields for all required AutomationRule properties MUST be shown
    - AND `triggerOperator` MUST render as a select with options: gt, lt, eq, gte, lte
    - AND `actionType` MUST render as a select with options: send_notification, change_status, escalate
    - AND `actionParams` MUST render as a JSON textarea with syntax validation

- [ ] 6.3 Implement `lib/BackgroundJob/AutomationRuleJob.php`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-004`
  - **files**: `lib/BackgroundJob/AutomationRuleJob.php`
  - **acceptance_criteria**:
    - GIVEN the job runs every 15 minutes
    - THEN all `AutomationRule` objects with `isActive: true` MUST be fetched
    - AND for each rule, `AutomationRuleEvaluator::evaluate()` MUST be called
    - AND after evaluation, `matchCount` MUST be incremented and `lastEvaluatedAt` updated
    - AND the job MUST be registered in `appinfo/info.xml`

- [ ] 6.4 Implement `lib/Service/AutomationRuleEvaluator.php`
  - **files**: `lib/Service/AutomationRuleEvaluator.php`
  - **acceptance_criteria**:
    - GIVEN a rule with `triggerOperator: "gte"` and `triggerValue: "30"`
    - THEN objects with `triggerField >= 30` MUST be returned as matches
    - AND for `actionType: "send_notification"` THEN `NotificationService::notify()` MUST be called for each match
    - AND for `actionType: "change_status"` THEN `ObjectService::updateObject()` MUST be called with the new status
    - AND for `actionType: "escalate"` THEN an escalation record MUST be created and CFO notified

## 7. Expense Claim Views

- [ ] 7.1 Create `src/views/expenseClaim/ExpenseClaimList.vue`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-005`
  - **files**: `src/views/expenseClaim/ExpenseClaimList.vue`
  - **acceptance_criteria**:
    - GIVEN the list renders THEN `CnIndexPage` MUST show columns: claimNumber, employeeId, description, status (color-coded), totalAmount, currency, submittedAt
    - AND status color coding: green (approved/paid), yellow (submitted/under_review), grey (draft), red (rejected)
    - AND `filtersFromSchema('ExpenseClaim')` MUST generate filter chips including status and employeeId

- [ ] 7.2 Create `src/views/expenseClaim/ExpenseClaimDetail.vue`
  - **files**: `src/views/expenseClaim/ExpenseClaimDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a claim detail renders THEN `CnDetailPage` MUST show tabs: Details, Items, History
    - AND the Items tab MUST list all `ExpenseItem` objects with `expenseClaimId` matching the current claim
    - AND the action bar MUST show: Edit (for draft), Submit (for draft), Approve / Reject (for submitted, controller role only), "Approve for Payment" (for approved, controller role only)

- [ ] 7.3 Create `src/views/expenseClaim/ExpenseClaimForm.vue` — multi-step
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-005`
  - **files**: `src/views/expenseClaim/ExpenseClaimForm.vue`, `src/views/expenseClaim/ExpenseItemForm.vue`
  - **acceptance_criteria**:
    - GIVEN the form opens THEN 4 steps MUST be shown: Details, Items, Receipts, Review
    - AND the Details step MUST prefill `employeeId` with the current user's ID
    - AND the Items step MUST allow adding multiple `ExpenseItem` rows with inline add/remove
    - AND the Receipts step MUST allow uploading a file per item using Nextcloud's file picker
    - AND the Review step MUST show a read-only summary with total amount calculated
    - AND submitting MUST create an `ExpenseClaim` with `status: "submitted"` and all `ExpenseItem` objects

## 8. Bulk Operations

- [ ] 8.1 Implement `src/components/BulkActionBar.vue`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-006`
  - **files**: `src/components/BulkActionBar.vue`
  - **acceptance_criteria**:
    - GIVEN one or more rows are selected THEN the bar MUST appear with: "{N} selected", Approve, Export CSV, Delete actions
    - AND clicking Delete MUST show a confirmation dialog before proceeding
    - AND when no rows are selected THEN the bar MUST be hidden (v-if, not v-show)

- [ ] 8.2 Implement `lib/Controller/BulkActionController.php`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-006`
  - **files**: `lib/Controller/BulkActionController.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/v1/bulk/{schema}/approve` receives `{ ids: ["a","b","c"] }`
    - THEN each object MUST be updated to `status: "approved"` via `ObjectService`
    - AND the response MUST be `{ succeeded: N, failed: N, errors: [{ id, message }] }`
    - AND partial failures MUST NOT roll back successful items

- [ ] 8.3 Integrate `BulkActionBar` into all entity list views
  - **files**: `src/views/expenseClaim/ExpenseClaimList.vue`, `src/views/automationRule/AutomationRuleList.vue`, `src/views/analytics/AnalyticsReportList.vue`
  - **acceptance_criteria**:
    - GIVEN any entity list view THEN a checkbox column MUST appear as the first column
    - AND the header checkbox MUST select/deselect all rows on the current page
    - AND `BulkActionBar` MUST receive `selectedIds` as a prop and emit `bulk-action` events

## 9. Quick Actions Panel

- [ ] 9.1 Implement `src/components/QuickActionsPanel.vue`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-007`
  - **files**: `src/components/QuickActionsPanel.vue`
  - **acceptance_criteria**:
    - GIVEN the panel renders THEN it MUST be positioned as a collapsible floating panel
    - AND actions MUST be loaded from `AppSettings` key `quickActions.{entityContext}`
    - AND clicking "New Expense Claim" MUST open `ExpenseClaimForm` pre-populated with `employeeId`
    - AND collapsed state MUST be persisted in `localStorage`

## 10. Navigation Updates

- [ ] 10.1 Update `src/navigation/MainMenu.vue` with new sections
  - **files**: `src/navigation/MainMenu.vue`
  - **acceptance_criteria**:
    - GIVEN the sidebar renders THEN 4 new sections MUST be added: Analytics, Portal, Automation, Expenses
    - AND the Expenses section MUST show a badge with count of submitted + under_review claims
    - AND the Automation section MUST show a badge with count of active rules

- [ ] 10.2 Update `src/router/index.js` with new routes
  - **files**: `src/router/index.js`
  - **acceptance_criteria**:
    - GIVEN the router is configured THEN routes MUST be registered for: `#/analytics`, `#/portal`, `#/portal/:tokenId`, `#/automation`, `#/automation/:ruleId`, `#/expenses`, `#/expenses/:claimId`
    - AND each route MUST include breadcrumb meta matching the component hierarchy

## 11. Backend Services and API Routes

- [ ] 11.1 Implement `lib/Service/PortalService.php`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-003`
  - **files**: `lib/Service/PortalService.php`
  - **acceptance_criteria**:
    - GIVEN `generateToken(organizationId)` is called THEN a 32-byte random token MUST be generated via `random_bytes(32)`, base64-encoded for display, and stored as `password_hash()` in the `PortalToken` object
    - GIVEN `validateToken(rawToken)` is called THEN `password_verify()` MUST be used against all active, non-expired tokens; the matching `PortalToken` object MUST be returned or null

- [ ] 11.2 Implement `lib/Service/AnalyticsService.php`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-002`
  - **files**: `lib/Service/AnalyticsService.php`
  - **acceptance_criteria**:
    - GIVEN `getKpiValue('total_receivables')` is called THEN the service MUST query OpenRegister for all invoice objects and sum `totalAmount` where `status` is not `paid`
    - GIVEN `getKpiValue('overdue_invoices')` is called THEN the service MUST count invoices where `ageInDays > 0` and `status` is not `paid`
    - AND each KPI response MUST include `current`, `previous`, and `trend` (`up`/`down`/`neutral`) fields

- [ ] 11.3 Implement `lib/Service/BulkActionService.php`
  - **spec_ref**: `specs/general/spec.md#REQ-GEN-006`
  - **files**: `lib/Service/BulkActionService.php`
  - **acceptance_criteria**:
    - GIVEN `bulkApprove(schema, ids)` is called THEN each ID MUST be processed individually; failures MUST be collected without stopping processing of remaining IDs
    - AND the return value MUST be `['succeeded' => N, 'failed' => N, 'errors' => []]`

- [ ] 11.4 Register new routes in `appinfo/routes.php`
  - **files**: `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN the app boots THEN OCS routes MUST be registered for all new controllers: `PortalController`, `AnalyticsController`, `BulkActionController`
    - AND portal routes MUST NOT require Nextcloud session authentication — they use `PortalService::validateToken()` instead

## 12. i18n

- [ ] 12.1 Add English translations for all new UI strings
  - **files**: `l10n/en.json`
  - **acceptance_criteria**:
    - GIVEN the app renders in English THEN all new labels, action buttons, step titles, and notification subjects MUST be translated
    - AND no hardcoded English strings MUST appear outside `t('shillinq', '...')` calls

- [ ] 12.2 Add Dutch translations
  - **files**: `l10n/nl.json`
  - **acceptance_criteria**:
    - GIVEN the Nextcloud instance language is Dutch THEN all new UI strings MUST render in Dutch
    - AND translation keys MUST match `en.json`

## 13. Unit Tests

- [ ] 13.1 Add unit tests for `AutomationRuleEvaluator.php`
  - **files**: `tests/Unit/Service/AutomationRuleEvaluatorTest.php`
  - **acceptance_criteria**:
    - GIVEN a rule with `triggerOperator: "gte"` and `triggerValue: "30"` THEN objects with field value 30 and above MUST match; objects below 30 MUST not match
    - AND each supported action type MUST have a test case verifying the correct service method is called

- [ ] 13.2 Add unit tests for `PortalService.php`
  - **files**: `tests/Unit/Service/PortalServiceTest.php`
  - **acceptance_criteria**:
    - GIVEN `generateToken()` runs THEN the stored hash MUST be verifiable via `password_verify()`
    - GIVEN an expired token THEN `validateToken()` MUST return null
    - GIVEN an inactive token THEN `validateToken()` MUST return null

- [ ] 13.3 Add unit tests for `BulkActionService.php`
  - **files**: `tests/Unit/Service/BulkActionServiceTest.php`
  - **acceptance_criteria**:
    - GIVEN 5 IDs where 1 fails THEN the result MUST show `succeeded: 4`, `failed: 1`, and the failed ID in the errors array
    - AND the service MUST not abort on first failure
