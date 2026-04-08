# Tasks: scheduling

## 1. OpenRegister Schema Definitions

- [ ] 1.1 Add `liquidityForecast` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN `shillinq_register.json` is processed by OpenRegister
    - THEN schema `liquidityForecast` MUST be registered with all properties from the data model
    - AND `bankAccountId`, `generatedAt`, `openingBalance`, `forecastWeeks` MUST be marked required
    - AND `status` MUST have enum `["current","shortfall","stale"]` with default `"current"`
    - AND `forecastWeeks` MUST be type array with default `[]`
    - AND `shortfallWeeks` MUST be type array with default `[]`
    - AND `horizonWeeks` MUST be type integer with default `13`
    - AND `x-schema-org` annotation MUST be `schema:FinancialProduct`

- [ ] 1.2 Add `delegationRule` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-002`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `delegationRule` MUST exist with `delegatorId` (required), `deputyId` (required), `startDate` (required), `endDate` (required), `scope` (required), `createdBy` (required)
    - AND `scope` MUST have enum `["all","specific_workflow_type"]` with default `"all"`
    - AND `startDate` and `endDate` MUST have `format: date-time`
    - AND `isActive` MUST be type boolean with default `true`
    - AND `notifyDelegator` MUST be type boolean with default `true`
    - AND `x-schema-org` MUST be `schema:Action`

- [ ] 1.3 Add `tenderDeadline` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-003`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `tenderDeadline` MUST exist with `referenceObjectType` (required), `referenceObjectId` (required), `deadlineType` (required), `dueDate` (required), `responsibleOfficerId` (required)
    - AND `deadlineType` MUST have enum `["award_notice","objection_response","standstill_expiry","bid_submission_close","clarification_deadline"]`
    - AND `status` MUST have enum `["pending","met","missed","waived"]` with default `"pending"`
    - AND `alertLeadDays` MUST be type array with default `[5,1]`
    - AND `dueDate` MUST have `format: date-time`
    - AND `x-schema-org` MUST be `schema:Event`

- [ ] 1.4 Add `digitalLockbox` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-004`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `digitalLockbox` MUST exist with `tenderReferenceId` (required), `unlockAfter` (required), `createdBy` (required)
    - AND `status` MUST have enum `["locked","unlocked","voided"]` with default `"locked"`
    - AND `encryptedBidIds` MUST be type array with default `[]`
    - AND `auditLog` MUST be type array with default `[]`
    - AND `keyShardStored` MUST be type boolean with default `false`
    - AND `unlockAfter` MUST have `format: date-time`
    - AND `x-schema-org` MUST be `schema:Thing`

- [ ] 1.5 Add `procurementPlan` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-005`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `procurementPlan` MUST exist with `title` (required), `fiscalYear` (required)
    - AND `status` MUST have enum `["draft","approved","active","closed"]` with default `"draft"`
    - AND `planLines` MUST be type array with default `[]`
    - AND `fiscalYear` MUST be type integer
    - AND `x-schema-org` MUST be `schema:CreativeWork`

- [ ] 1.6 Add `deliverySchedule` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-006`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `deliverySchedule` MUST exist with `callOffOrderId` (required), `responsibleBuyerId` (required)
    - AND `deliveryLines` MUST be type array with default `[]`
    - AND `alertLeadDays` MUST be type integer with default `3`
    - AND `x-schema-org` MUST be `schema:Schedule`

- [ ] 1.7 Add `scheduledInvoice` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-007`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `scheduledInvoice` MUST exist with `invoiceId` (required), `dispatchAt` (required), `recipientEmail` (required), `createdBy` (required)
    - AND `status` MUST have enum `["scheduled","dispatched","cancelled","failed"]` with default `"scheduled"`
    - AND `dispatchAt` MUST have `format: date-time`
    - AND `x-schema-org` MUST be `schema:Invoice`

- [ ] 1.8 Add `paymentSchedule` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-008`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `paymentSchedule` MUST exist with `invoiceId` (required), `amount` (required), `scheduledDate` (required), `createdBy` (required)
    - AND `priority` MUST have enum `["urgent","high","normal","deferred"]` with default `"normal"`
    - AND `status` MUST have enum `["scheduled","executed","cancelled"]` with default `"scheduled"`
    - AND `currency` MUST default to `"EUR"`
    - AND `scheduledDate` MUST have `format: date-time`
    - AND `x-schema-org` MUST be `schema:PaymentChargeSpecification`

- [ ] 1.9 Add `paymentReminder` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-009`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `paymentReminder` MUST exist with `invoiceId` (required), `reminderSteps` (required, array)
    - AND `status` MUST have enum `["active","paused","resolved","escalated_to_legal"]` with default `"active"`
    - AND `activeStep` MUST be type integer with default `0`
    - AND `reminderSteps` MUST default to `[]`
    - AND `x-schema-org` MUST be `schema:Action`

- [ ] 1.10 Add `multiYearPlan` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-010`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `multiYearPlan` MUST exist with `title` (required), `startYear` (required), `endYear` (required)
    - AND `status` MUST have enum `["draft","approved","active","superseded"]` with default `"draft"`
    - AND `planYears` MUST be type array with default `[]`
    - AND `startYear` and `endYear` MUST be type integer
    - AND `x-schema-org` MUST be `schema:CreativeWork`

- [ ] 1.11 Add `contractClosure` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-011`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `contractClosure` MUST exist with `contractId` (required), `closureDate` (required), `declineReason` (required), `createdBy` (required)
    - AND `status` MUST have enum `["initiated","in_progress","complete","cancelled"]` with default `"initiated"`
    - AND `closureTasks` MUST be type array with default `[]`
    - AND `closureDate` MUST have `format: date-time`
    - AND `x-schema-org` MUST be `schema:Action`

## 2. Seed Data

- [ ] 2.1 Add LiquidityForecast seed object to `lib/Repair/CreateDefaultConfiguration.php`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-012`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - GIVEN the repair step runs on a fresh install
    - THEN 1 LiquidityForecast object MUST be created with `bankAccountId: seed-bank-account-1`, `horizonWeeks: 13`, `minimumBalanceThreshold: 50000`, `openingBalance: 120000`, `status: current`, and 13 ForecastWeek nested objects with synthetic data
    - AND idempotency check MUST use `LiquidityForecast.bankAccountId` as the unique key

- [ ] 2.2 Add DelegationRule seed objects to repair step
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-012`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 2 DelegationRule objects MUST be created: Rule 1 (scope: all, start: 2026-07-01, end: 2026-07-14, reason: "Annual leave") and Rule 2 (scope: specific_workflow_type, workflowType: purchase_order_approval, start: 2026-08-01, end: 2026-08-07)
    - AND idempotency key MUST be composite `(delegatorId, startDate)`

- [ ] 2.3 Add TenderDeadline seed objects to repair step
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-012`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 2 TenderDeadline objects MUST be created with deadlineTypes `award_notice` (dueDate 2026-05-20) and `objection_response` (dueDate 2026-06-03), both with status `pending` and alertLeadDays `[5,1]`
    - AND idempotency key MUST be composite `(referenceObjectId, deadlineType)`

- [ ] 2.4 Add remaining seed objects (DigitalLockbox, ProcurementPlan, DeliverySchedule, ScheduledInvoice, PaymentSchedules, PaymentReminder, MultiYearPlan, ContractClosure) to repair step
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-012`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN all remaining seed objects are created as specified in the design.md seed data tables
    - AND each uses the idempotency key specified in REQ-SCH-012 scenario 2
    - AND the MultiYearPlan seed covers 4 years (2026–2029) with planYears array containing one entry per year

## 3. Backend Services

- [ ] 3.1 Create `lib/Service/LockboxService.php`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-004`
  - **files**: `lib/Service/LockboxService.php`
  - **acceptance_criteria**:
    - GIVEN a bid is submitted before `unlockAfter`
    - THEN `LockboxService::encrypt(bidId, content)` encrypts the bid content using Nextcloud `ICrypto::encrypt()` and stores the encrypted payload in the `Bid` object via OpenRegister; the key shard is stored in the `DigitalLockbox` object
    - AND `LockboxService::unlock(lockboxId)` is called only when `DigitalLockbox.unlockAfter ≤ now`; decrypts all bids in `encryptedBidIds` and sets `DigitalLockbox.status: unlocked`
    - AND every encrypt and decrypt operation appends a `{timestamp, userId, action}` entry to `DigitalLockbox.auditLog`
    - AND raw encryption keys are NEVER written to the Nextcloud log at any level

- [ ] 3.2 Create `lib/Service/LiquidityForecastService.php`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-001`
  - **files**: `lib/Service/LiquidityForecastService.php`
  - **acceptance_criteria**:
    - GIVEN a `bankAccountId` is passed
    - THEN the service queries all `CashFlow` objects with `status: expected` for the account
    - AND queries all `PaymentSchedule` objects with `status: scheduled` for the account
    - AND aggregates inflows and outflows per ISO week up to `horizonWeeks` weeks forward
    - AND computes a running balance starting from `openingBalance`
    - AND sets `isShortfall: true` on each week where `projectedBalance < minimumBalanceThreshold`
    - AND returns the updated `LiquidityForecast` data without persisting (persistence is done by the job)

- [ ] 3.3 Create `lib/Service/DelegationResolverService.php`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-002`
  - **files**: `lib/Service/DelegationResolverService.php`
  - **acceptance_criteria**:
    - GIVEN a `userId` and `datetime` are passed
    - THEN the service queries active `DelegationRule` objects where `delegatorId = userId` and `startDate ≤ datetime ≤ endDate`
    - AND if multiple rules match, the most specific (scope: specific_workflow_type) takes precedence over `scope: all`
    - AND if the resolved deputy also has an active delegation rule, the service recursively resolves up to the `fallbackId`; maximum depth is 2
    - AND circular delegation (A→B→A) returns a `DelegationCircularException` and routes to the original delegator
    - AND the delegation event is logged to the OpenRegister audit trail

- [ ] 3.4 Create `lib/Service/DeadlineAlertService.php`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-003`
  - **files**: `lib/Service/DeadlineAlertService.php`
  - **acceptance_criteria**:
    - GIVEN a `TenderDeadline` object is passed with `alertLeadDays` and `dueDate`
    - THEN the service computes alert trigger dates by subtracting each lead day from `dueDate`
    - AND for each alert date ≤ today that has not yet been notified, sends a Nextcloud notification via `INotifier` to `responsibleOfficerId`
    - AND uses a deduplication key `"tender-deadline-alert-{deadlineId}-{leadDay}"` on `INotificationManager` to prevent duplicate notifications
    - AND if `dueDate` has passed and `status` is still `pending`, marks `status: missed` and sends a missed-deadline notification

- [ ] 3.5 Create `lib/Service/ReminderEscalationService.php`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-009`
  - **files**: `lib/Service/ReminderEscalationService.php`
  - **acceptance_criteria**:
    - GIVEN a `PaymentReminder` and the linked invoice's `dueDate` are passed
    - THEN the service computes the number of days the invoice is overdue
    - AND finds the next `ReminderStep` where `daysAfterDue ≤ daysOverdue` and `sentAt` is null
    - AND if found, sends the email using the step's `templateKey` via Nextcloud `IMailer` to the `recipientRole`'s email address resolved from the invoice
    - AND sets `sentAt` on the step and advances `PaymentReminder.activeStep`
    - AND does NOT process steps if `PaymentReminder.status` is `paused` and `pausedUntil ≥ today`

## 4. Background Jobs

- [ ] 4.1 Create `lib/BackgroundJob/LiquidityForecastJob.php`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-001`
  - **files**: `lib/BackgroundJob/LiquidityForecastJob.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the job is registered via `ITimedJobList` with an interval of 86400 seconds (daily)
    - WHEN the job runs THEN it calls `LiquidityForecastService` for each active `BankAccount`
    - AND updates the corresponding `LiquidityForecast` object in OpenRegister with the recalculated forecast weeks
    - AND if `status` changes to `shortfall`, notifies the bank account owner via `INotifier`
    - AND sets `status: stale` if the forecast update fails, without throwing an unhandled exception

- [ ] 4.2 Create `lib/BackgroundJob/DeadlineAlertJob.php`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-003`
  - **files**: `lib/BackgroundJob/DeadlineAlertJob.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the job is registered with an interval of 86400 seconds (daily)
    - WHEN the job runs THEN it queries all `TenderDeadline` objects with `status: pending`
    - AND calls `DeadlineAlertService` for each deadline
    - AND marks deadlines as `missed` where `dueDate < now` and `status` is still `pending`

- [ ] 4.3 Create `lib/BackgroundJob/LockboxExpiryJob.php`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-004`
  - **files**: `lib/BackgroundJob/LockboxExpiryJob.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the job is registered with an interval of 900 seconds (15 minutes)
    - WHEN the job runs THEN it queries all `DigitalLockbox` objects with `status: locked` and `unlockAfter ≤ now`
    - AND calls `LockboxService::unlock()` for each; sets `status: unlocked` and `unlockedAt` on success
    - AND logs a failure entry in `auditLog` and sets `status: voided` if decryption fails after 3 retries

- [ ] 4.4 Create `lib/BackgroundJob/ScheduledInvoiceJob.php`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-007`
  - **files**: `lib/BackgroundJob/ScheduledInvoiceJob.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the job is registered with an interval of 900 seconds (15 minutes)
    - WHEN the job runs THEN it queries `ScheduledInvoice` objects with `status: scheduled` and `dispatchAt ≤ now`
    - AND for each, dispatches the invoice email via Nextcloud `IMailer` using the linked invoice template
    - AND sets `status: dispatched` and `dispatchedAt` on success, or `status: failed` with `failureReason` on error
    - AND uses an optimistic lock (`ILockingProvider`) on the `ScheduledInvoice` object to prevent concurrent double-dispatch

- [ ] 4.5 Create `lib/BackgroundJob/PaymentReminderJob.php`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-009`
  - **files**: `lib/BackgroundJob/PaymentReminderJob.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the job is registered with an interval of 86400 seconds (daily)
    - WHEN the job runs THEN it queries all `PaymentReminder` objects with `status: active`
    - AND calls `ReminderEscalationService` for each with the linked invoice's `dueDate`
    - AND skips reminders where `status: paused` and `pausedUntil ≥ today`
    - AND sets `status: resolved` for reminders where the linked invoice has `status: paid`

- [ ] 4.6 Create `lib/BackgroundJob/ClosureTaskAlertJob.php`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-011`
  - **files**: `lib/BackgroundJob/ClosureTaskAlertJob.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the job is registered with an interval of 86400 seconds (daily)
    - WHEN the job runs THEN it queries all `ContractClosure` objects with `status` in `[initiated, in_progress]`
    - AND for each open `ClosureTask` (completedAt is null) where `dueDate` is within `alertLeadDays` of now, sends a notification to `assigneeId`
    - AND uses a deduplication key `"closure-task-alert-{taskId}-{date}"` to prevent duplicate daily notifications

## 5. Backend Controllers

- [ ] 5.1 Create `lib/Controller/SchedulingController.php`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-003, #REQ-SCH-004, #REQ-SCH-011`
  - **files**: `lib/Controller/SchedulingController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/v1/tender-deadlines/{id}/met` is called
    - THEN `TenderDeadline.status` changes to `met`, `metAt` and `metBy` are set; 422 if already `met` or `missed`
    - GIVEN `POST /api/v1/lockboxes/{id}/submit-bid` is called with a bid payload before `unlockAfter`
    - THEN `LockboxService::encrypt()` is called; bid is added to `encryptedBidIds`; 422 if lockbox `unlockAfter` has already passed
    - GIVEN `PATCH /api/v1/contract-closures/{id}/tasks/{taskId}` is called with `{completedAt}`
    - THEN `ClosureTask.completedAt` is set; if all tasks are complete, `ContractClosure.status` changes to `complete` and `completedAt` is set
    - GIVEN `POST /api/v1/contract-closures` is called with `contractId`, `closureDate`, `declineReason`
    - THEN a `ContractClosure` is created with `status: initiated`; 422 if a non-cancelled closure already exists for the same `contractId`

- [ ] 5.2 Create `lib/Controller/DelegationController.php`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-002`
  - **files**: `lib/Controller/DelegationController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `GET /api/v1/delegation/resolve?userId={id}&datetime={iso}` is called
    - THEN `DelegationResolverService::resolve()` is called and the effective delegate userId is returned; if no active rule exists, `{delegate: null}` is returned
    - GIVEN `POST /api/v1/delegation-rules` is called with `delegatorId === deputyId`
    - THEN the API returns 422 "Delegator and deputy cannot be the same user"
    - AND the `ApprovalWorkflow` engine MUST call `GET /api/v1/delegation/resolve` before routing each approval

- [ ] 5.3 Create `lib/Controller/ProcurementPlanController.php`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-005`
  - **files**: `lib/Controller/ProcurementPlanController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/v1/procurement-plans/{id}/submit` is called
    - THEN the plan enters the `ApprovalWorkflow`; `status` changes to `pending_approval`; plan lines become read-only
    - GIVEN `GET /api/v1/procurement-plans/{id}/budget-utilisation` is called
    - THEN the response returns `{approvedBudget, totalPlannedValue, utilisationPercent, variance}` computed from the linked `Budget` and `planLines`

## 6. Pinia Stores

- [ ] 6.1 Create all 11 scheduling Pinia stores
  - **files**: `src/store/modules/liquidityForecast.js`, `src/store/modules/delegationRule.js`, `src/store/modules/tenderDeadline.js`, `src/store/modules/digitalLockbox.js`, `src/store/modules/procurementPlan.js`, `src/store/modules/deliverySchedule.js`, `src/store/modules/scheduledInvoice.js`, `src/store/modules/paymentSchedule.js`, `src/store/modules/paymentReminder.js`, `src/store/modules/multiYearPlan.js`, `src/store/modules/contractClosure.js`
  - **acceptance_criteria**:
    - THEN each store MUST be created via `createObjectStore('{schemaName}')` using the camelCase schema name
    - AND each store MUST be registered in `src/store/store.js`
    - AND store names: `useLiquidityForecastStore`, `useDelegationRuleStore`, `useTenderDeadlineStore`, `useDigitalLockboxStore`, `useProcurementPlanStore`, `useDeliveryScheduleStore`, `useScheduledInvoiceStore`, `usePaymentScheduleStore`, `usePaymentReminderStore`, `useMultiYearPlanStore`, `useContractClosureStore`

## 7. Frontend Views — Liquidity Forecast

- [ ] 7.1 Create `src/views/liquidityForecast/LiquidityForecastIndex.vue`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-001`
  - **files**: `src/views/liquidityForecast/LiquidityForecastIndex.vue`
  - **acceptance_criteria**:
    - GIVEN the list page loads THEN it MUST use `CnIndexPage` with `columnsFromSchema('liquidityForecast')`
    - AND filter chips for `status` (current / shortfall / stale) MUST be present
    - AND rows with `status: shortfall` MUST display an amber warning badge

- [ ] 7.2 Create `src/views/liquidityForecast/LiquidityForecastDetail.vue`
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-001`
  - **files**: `src/views/liquidityForecast/LiquidityForecastDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the detail page renders THEN it MUST use `CnDetailPage` with a `LiquidityCashFlowChart.vue` component showing a weekly bar chart of inflows (green), outflows (red), and running balance (line)
    - AND weeks where `isShortfall: true` MUST be visually highlighted with an amber background on the chart
    - AND the threshold line MUST be drawn on the balance axis

- [ ] 7.3 Create `src/views/liquidityForecast/LiquidityForecastForm.vue`
  - **files**: `src/views/liquidityForecast/LiquidityForecastForm.vue`
  - **acceptance_criteria**:
    - GIVEN the form opens THEN it MUST use `CnFormDialog` with fields: `bankAccountId` (select from BankAccount objects), `horizonWeeks` (number input, min 4, max 52), `minimumBalanceThreshold` (number input), `currency` (select)

## 8. Frontend Views — Delegation Rules

- [ ] 8.1 Create `src/views/delegationRule/` views (Index, Detail, Form)
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-002`
  - **files**: `src/views/delegationRule/DelegationRuleIndex.vue`, `src/views/delegationRule/DelegationRuleDetail.vue`, `src/views/delegationRule/DelegationRuleForm.vue`
  - **acceptance_criteria**:
    - GIVEN the index page renders THEN it uses `CnIndexPage` with filter chips for `isActive` and `scope`
    - AND currently active rules (today within startDate–endDate) MUST show a green "Active" badge
    - GIVEN the form opens THEN `startDate` and `endDate` MUST use a date range picker
    - AND `workflowType` field MUST only be shown when `scope: specific_workflow_type` is selected (conditional field)
    - AND the form MUST prevent submission when `delegatorId === deputyId` with inline error "Delegator and deputy must be different users"

## 9. Frontend Views — Tender Deadlines

- [ ] 9.1 Create `src/views/tenderDeadline/` views (Index, Detail, Form)
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-003`
  - **files**: `src/views/tenderDeadline/TenderDeadlineIndex.vue`, `src/views/tenderDeadline/TenderDeadlineDetail.vue`, `src/views/tenderDeadline/TenderDeadlineForm.vue`
  - **acceptance_criteria**:
    - GIVEN the index page renders THEN it uses `CnIndexPage` with filter chips for `deadlineType` and `status`
    - AND rows with `dueDate` within 5 days MUST display a red urgency badge; within 15 days an amber badge
    - AND `status: missed` rows MUST display with a red NL Design System alert colour token
    - GIVEN the detail page renders THEN a "Mark as Met" button is shown for `status: pending` deadlines; clicking it calls `POST /api/v1/tender-deadlines/{id}/met` with a confirmation dialog

## 10. Frontend Views — Digital Lockbox

- [ ] 10.1 Create `src/views/digitalLockbox/` views (Index, Detail, Form)
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-004`
  - **files**: `src/views/digitalLockbox/DigitalLockboxIndex.vue`, `src/views/digitalLockbox/DigitalLockboxDetail.vue`, `src/views/digitalLockbox/DigitalLockboxForm.vue`
  - **acceptance_criteria**:
    - GIVEN the detail page renders THEN it shows `status` badge (locked / unlocked / voided), `unlockAfter` datetime, bid count from `encryptedBidIds.length`, and the `auditLog` as a read-only timeline
    - AND when `status: locked`, bid content details MUST NOT be shown; only bid count is visible
    - AND when `status: unlocked`, a list of decrypted `Bid` objects linked to the lockbox MUST be displayed

## 11. Frontend Views — Procurement Plan

- [ ] 11.1 Create `src/views/procurementPlan/` views (Index, Detail, Form)
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-005`
  - **files**: `src/views/procurementPlan/ProcurementPlanIndex.vue`, `src/views/procurementPlan/ProcurementPlanDetail.vue`, `src/views/procurementPlan/ProcurementPlanForm.vue`
  - **acceptance_criteria**:
    - GIVEN the index page renders THEN filter chips for `fiscalYear` and `status` MUST be present
    - GIVEN the detail page renders THEN it uses `CnDetailPage` with tabs: Plan Lines, Budget Utilisation
    - AND the Budget Utilisation tab embeds `ProcurementPlanLineTable.vue` and calls `GET /api/v1/procurement-plans/{id}/budget-utilisation`
    - AND the utilisation figure is shown as a progress bar with surplus (green) / deficit (red) colouring

## 12. Frontend Views — Remaining Scheduling Views

- [ ] 12.1 Create `src/views/deliverySchedule/` views (Index, Detail, Form)
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-006`
  - **files**: `src/views/deliverySchedule/DeliveryScheduleIndex.vue`, `src/views/deliverySchedule/DeliveryScheduleDetail.vue`, `src/views/deliverySchedule/DeliveryScheduleForm.vue`
  - **acceptance_criteria**:
    - GIVEN the detail page renders THEN `DeliveryLineTimeline.vue` shows all delivery lines in chronological order with status chips
    - AND overdue lines (`status: scheduled` with `deliveryDate` in the past) MUST display a red badge

- [ ] 12.2 Create `src/views/scheduledInvoice/`, `src/views/paymentSchedule/`, `src/views/paymentReminder/` views
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-007, #REQ-SCH-008, #REQ-SCH-009`
  - **files**: corresponding Index, Detail, Form Vue files
  - **acceptance_criteria**:
    - GIVEN `ScheduledInvoice` index renders THEN filter chips for `status` and a date-range filter on `dispatchAt` MUST be present
    - GIVEN `PaymentSchedule` index renders THEN payments MUST be sortable by `scheduledDate` and filterable by `priority`; urgent and high priority rows highlighted with NL Design System colour tokens
    - GIVEN `PaymentReminder` detail renders THEN `ReminderStepBuilder.vue` allows adding, reordering, and configuring steps; existing sent steps (sentAt is set) are read-only

- [ ] 12.3 Create `src/views/multiYearPlan/` views (Index, Detail, Form)
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-010`
  - **files**: `src/views/multiYearPlan/MultiYearPlanIndex.vue`, `src/views/multiYearPlan/MultiYearPlanDetail.vue`, `src/views/multiYearPlan/MultiYearPlanForm.vue`
  - **acceptance_criteria**:
    - GIVEN the detail page renders THEN a matrix table with one column per `fiscalYear` MUST show: `revenueTarget`, `expenditureTarget`, `capitalExpenditure`, `procurementBudget`, and computed variance vs actuals from linked Budget objects
    - AND variance cells MUST be green (within 5%) or amber (5–15% over) or red (>15% over target)

- [ ] 12.4 Create `src/views/contractClosure/` views (Index, Detail, Form)
  - **spec_ref**: `specs/scheduling/spec.md#REQ-SCH-011`
  - **files**: `src/views/contractClosure/ContractClosureIndex.vue`, `src/views/contractClosure/ContractClosureDetail.vue`, `src/views/contractClosure/ContractClosureForm.vue`
  - **acceptance_criteria**:
    - GIVEN the detail page renders THEN `ClosureTaskChecklist.vue` shows each task with status, due date, and assignee; completed tasks are struck through
    - AND `status: complete` is displayed only when all tasks have `completedAt` set

## 13. Sidebar Navigation Update

- [ ] 13.1 Add scheduling sections to `src/components/ShillinqSidebar.vue`
  - **files**: `src/components/ShillinqSidebar.vue`
  - **acceptance_criteria**:
    - GIVEN the sidebar renders THEN a "Scheduling" section MUST be present with nav items for: Liquidity Forecasts, Delegation Rules, Tender Deadlines, Procurement Plans, Delivery Schedules, Scheduled Invoices, Payment Schedules, Payment Reminders, Multi-Year Plans, Contract Closures
    - AND a "Digital Lockbox" nav item MUST be present under the Procurement section (alongside Sourcing Events)
    - AND each nav item MUST show a badge count from OpenRegister object counts for the corresponding schema
