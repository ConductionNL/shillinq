---
status: proposed
---

# Scheduling — Shillinq

## Purpose

Defines functional requirements for Shillinq's scheduling and deadline management capabilities: liquidity forecasting with shortfall detection, calendar-based delegation rules with escalation, legal procurement deadline tracking with award notice and objection response alerts, digital lockbox enforcement for bid submissions, procurement planning with budget allocation, delivery schedule management on call-off orders, scheduled invoice dispatch, strategic payment scheduling, configurable payment reminder escalation, multi-year financial planning, and structured contract closure scheduling.

Stakeholders: Management Accountant, City Council Member, Bank / Financier.

User stories addressed: Match invoice to purchase order automatically, Review mandate register for compliance completeness, Identify top suppliers by spend, Create category spend baseline.

## Requirements

### REQ-SCH-001: Liquidity Forecast with Shortfall Detection [must]

The app MUST register the `LiquidityForecast` schema (`schema:FinancialProduct`) in OpenRegister. A daily background job (`LiquidityForecastJob`) MUST aggregate projected inflows from outstanding receivables and outflows from `PaymentSchedule` objects, compute a per-week running balance across a configurable horizon (default 13 weeks), and flag any week where the projected balance falls below a configurable minimum threshold. The responsible bank account owner MUST receive a Nextcloud notification when `status` changes to `shortfall`.

**Scenarios:**

1. **GIVEN** a `BankAccount` has an `openingBalance` of EUR 120 000 and `PaymentSchedule` objects totalling EUR 80 000 due in week 3 with no inflows **WHEN** `LiquidityForecastJob` runs **THEN** the `LiquidityForecast` for that account shows `forecastWeeks[2].projectedBalance = 40 000` and `isShortfall: true` if `minimumBalanceThreshold` is 50 000.

2. **GIVEN** the forecast previously had `status: current` **WHEN** the job recalculates and finds a shortfall week **THEN** `status` changes to `shortfall` and the bank account owner receives a Nextcloud notification "Liquidity shortfall projected for week {weekNumber}: balance EUR {amount}".

3. **GIVEN** the minimum threshold is set to `0` (no threshold) **WHEN** the forecast is computed **THEN** `shortfallWeeks` is empty and `status` remains `current` regardless of balance levels.

4. **GIVEN** the forecast is older than 24 hours and the job has not run **WHEN** a finance user views the forecast detail page **THEN** `status` is displayed as `stale` with a warning "This forecast was last updated more than 24 hours ago."

5. **GIVEN** a finance user changes `minimumBalanceThreshold` from 50 000 to 100 000 **WHEN** the forecast is next recalculated **THEN** the new threshold is applied and additional shortfall weeks may be identified; the previous threshold is not retained.

### REQ-SCH-002: Delegation Rules with Calendar-Based Out-of-Office and Escalation [must]

The app MUST register the `DelegationRule` schema (`schema:Action`) and integrate with the `ApprovalWorkflow` engine to redirect approvals to the active delegate when the delegator is within a delegation period. Rules MUST support an optional fallback user for cases where the primary deputy is also absent. Rules MAY be scoped to all approvals or a specific workflow type.

**Scenarios:**

1. **GIVEN** a `DelegationRule` exists with `delegatorId: alice`, `deputyId: bob`, `startDate: 2026-07-01`, `endDate: 2026-07-14` **WHEN** an approval request is routed to `alice` on 2026-07-05 **THEN** `DelegationResolverService` returns `bob` as the active delegate, the approval is assigned to `bob`, and both `alice` and `bob` receive a Nextcloud notification "Approval delegated to Bob (Alice is out of office until 14 July)."

2. **GIVEN** a delegation rule with `scope: specific_workflow_type` and `workflowType: purchase_order_approval` **WHEN** an invoice approval (not a purchase order approval) is routed to the delegator **THEN** the rule is NOT applied and the approval remains with the delegator.

3. **GIVEN** a delegation rule specifies `deputyId: bob` and `fallbackId: charlie` **WHEN** an approval is to be delegated to `bob` but `bob` also has an active delegation rule for the same period **THEN** `DelegationResolverService` escalates to `charlie` and records the fallback escalation in the delegation audit log.

4. **GIVEN** a delegation rule has `endDate` in the past **WHEN** `DelegationResolverService` is called **THEN** the rule is not returned as active and the approval is routed to the original delegator without delegation.

5. **GIVEN** a user attempts to create a `DelegationRule` with `deputyId` pointing back to themselves **WHEN** the save is attempted **THEN** the API returns 422 "Delegator and deputy cannot be the same user."

6. **GIVEN** two overlapping `DelegationRule` objects exist for the same delegator — one with `scope: all` and one with `scope: specific_workflow_type` **WHEN** a purchase order approval is routed **THEN** the more specific rule (workflow-type scope) takes precedence.

### REQ-SCH-003: Tender Deadline Tracking with Alert Notifications [must]

The app MUST register the `TenderDeadline` schema (`schema:Event`) and provide a daily background job (`DeadlineAlertJob`) that sends Nextcloud notifications to the responsible officer at configurable lead times before the deadline. The job MUST mark deadlines as `missed` if the due date passes without `status: met`. The app MUST support deadline types: `award_notice`, `objection_response`, `standstill_expiry`, `bid_submission_close`, and `clarification_deadline`.

**Scenarios:**

1. **GIVEN** a `TenderDeadline` of type `award_notice` has `dueDate: 2026-05-20` and `alertLeadDays: [5,1]` **WHEN** `DeadlineAlertJob` runs on 2026-05-15 **THEN** the responsible officer receives a notification "Award notice deadline in 5 days: publish by 2026-05-20 (Aanbestedingswet art. 2.131 lid 1)."

2. **GIVEN** the same deadline has `alertLeadDays: [5,1]` **WHEN** `DeadlineAlertJob` runs on 2026-05-19 **THEN** a second notification is sent "Award notice deadline tomorrow: publish by 2026-05-20."

3. **GIVEN** a `TenderDeadline` has `dueDate` in the past and `status: pending` **WHEN** `DeadlineAlertJob` runs **THEN** `status` is set to `missed` and the responsible officer receives a notification "MISSED: Award notice deadline passed on 2026-05-20 without publication."

4. **GIVEN** the responsible officer marks a deadline as met via `POST /api/v1/tender-deadlines/{id}/met` **WHEN** the request is processed **THEN** `status` changes to `met`, `metAt` and `metBy` are recorded, and no further alerts are sent for that deadline.

5. **GIVEN** a `TenderDeadline` of type `objection_response` is created with `dueDate: 2026-06-03` **WHEN** a procurement manager saves it **THEN** it appears in the tender deadline list with a red urgency badge if `dueDate` is within 5 days of today.

### REQ-SCH-004: Digital Lockbox for Bid Submission Deadline Enforcement [must]

The app MUST register the `DigitalLockbox` schema (`schema:Thing`) and implement a `LockboxService` that encrypts `Bid` content on submission using Nextcloud `ICrypto` (AES-256). Encrypted bid content MUST NOT be readable by any user — including administrators — before the `unlockAfter` datetime. A background job (`LockboxExpiryJob`) running every 15 minutes MUST decrypt and unlock all lockboxes whose `unlockAfter` has passed. Access events MUST be recorded in `auditLog`.

**Scenarios:**

1. **GIVEN** a `DigitalLockbox` with `unlockAfter: 2026-05-31T12:00:00Z` is active **WHEN** a supplier submits a bid via `POST /api/v1/lockboxes/{id}/submit-bid` at 10:00 on 2026-05-31 **THEN** the bid content is encrypted by `LockboxService`, stored as an encrypted blob in the `Bid` object, and `Bid.status` is set to `sealed`; the bid content cannot be read via any API endpoint.

2. **GIVEN** a user attempts to retrieve the bid content via `GET /api/v1/bids/{id}` while the lockbox is `locked` **THEN** the `Bid` object is returned with `content: null` and a response header `X-Lockbox-Unlocks-At: 2026-05-31T12:00:00Z`.

3. **GIVEN** `LockboxExpiryJob` runs at 12:05 on 2026-05-31 **WHEN** the job finds a lockbox with `unlockAfter ≤ now` and `status: locked` **THEN** `LockboxService.unlock()` is called, all associated bids are decrypted in memory and their content stored back in plaintext in OpenRegister, `DigitalLockbox.status` changes to `unlocked`, and `unlockedAt` is set to `2026-05-31T12:05:00Z`.

4. **GIVEN** the lockbox is unlocked **WHEN** a procurement manager retrieves a bid via `GET /api/v1/bids/{id}` **THEN** the full bid content is returned and a `{timestamp, userId, action: "read"}` entry is appended to `DigitalLockbox.auditLog`.

5. **GIVEN** the bid submission deadline has not yet passed **WHEN** a supplier submits a second bid to the same lockbox **THEN** the second bid is also encrypted and added to `encryptedBidIds`; the total bid count is visible in the lockbox detail view but individual bid identities are not revealed until unlock.

### REQ-SCH-005: Procurement Planning with Budget Allocation [must]

The app MUST register the `ProcurementPlan` schema (`schema:CreativeWork`) with nested `ProcurementPlanLine` objects. Each plan MUST link to an approved `Budget` object. A budget utilisation view MUST show planned tender values summed per fiscal year alongside the approved budget with a surplus/deficit indicator. Plan lines MUST update their `status` when the linked `Award` is published.

**Scenarios:**

1. **GIVEN** a `ProcurementPlan` for fiscal year 2026 is linked to a `Budget` with `approvedAmount: 1 500 000` **WHEN** two plan lines are added with `estimatedValue: 80 000` and `estimatedValue: 250 000` **THEN** `totalPlannedValue` is computed as `330 000` and the budget utilisation panel shows `330 000 / 1 500 000 (22%)`.

2. **GIVEN** a plan line has `status: planned` and references an `awardId` **WHEN** the linked `Award` changes to `status: published` **THEN** the plan line `status` automatically updates to `published`.

3. **GIVEN** the `ProcurementPlan` is in `draft` status **WHEN** a procurement manager submits it for approval via `POST /api/v1/procurement-plans/{id}/submit` **THEN** the plan enters the `ApprovalWorkflow` and `status` changes to `pending_approval`; editing of plan lines is locked.

4. **GIVEN** the plan is approved **WHEN** the plan detail page renders **THEN** the Budget Utilisation tab shows a bar per fiscal year with planned vs approved budget, and a drill-down per CPV category with `plannedPublicationQuarter` labels.

5. **GIVEN** a plan line is created without a `cpvCode` **WHEN** the form is saved **THEN** a warning "CPV code is recommended for budget reporting" is shown as a non-blocking advisory; saving still succeeds.

### REQ-SCH-006: Delivery Schedule on Call-Off Orders [must]

The app MUST register the `DeliverySchedule` schema (`schema:Schedule`) with nested `DeliveryLine` objects for call-off orders. A background job MUST alert the responsible buyer when a delivery line due date is within the configured lead time (default 3 days). Delivery line status transitions MUST be logged.

**Scenarios:**

1. **GIVEN** a `DeliverySchedule` has 3 delivery lines with dates 2026-06-15, 2026-09-15, 2026-12-15 **WHEN** the schedule is saved **THEN** all 3 lines are stored with `status: scheduled` and visible in the delivery timeline view.

2. **GIVEN** `alertLeadDays: 3` and a delivery line due date is 2026-06-15 **WHEN** `ClosureTaskAlertJob` (or a dedicated delivery alert logic) runs on 2026-06-12 **THEN** the `responsibleBuyerId` receives a notification "Delivery due in 3 days: 500 pcs — 2026-06-15."

3. **GIVEN** a buyer marks a delivery line as delivered via `PATCH /api/v1/delivery-schedules/{scheduleId}/lines/{lineId}` with `{status: "delivered", deliveredAt: "2026-06-15T10:30:00Z"}` **WHEN** the update is processed **THEN** `DeliveryLine.status` changes to `delivered` and `deliveredAt` is stored; a delivery confirmation is appended to the schedule-level `notes`.

4. **GIVEN** a delivery line due date has passed and `status` is still `scheduled` **WHEN** the alert job runs **THEN** `status` changes to `missed` and the responsible buyer receives a notification "Delivery line missed: 500 pcs was due 2026-06-15."

### REQ-SCH-007: Scheduled Invoice Email Dispatch [must]

The app MUST register the `ScheduledInvoice` schema (`schema:Invoice`) and a background job (`ScheduledInvoiceJob`) running every 15 minutes that dispatches invoice emails when `dispatchAt ≤ now` and `status: scheduled`. The job MUST set `status: dispatched` on success or `status: failed` with `failureReason` on error.

**Scenarios:**

1. **GIVEN** a finance user creates a `ScheduledInvoice` with `dispatchAt: 2026-07-01T08:00:00Z` **WHEN** `ScheduledInvoiceJob` runs at 08:05 on 2026-07-01 **THEN** the invoice email is sent to `recipientEmail`, `status` changes to `dispatched`, and `dispatchedAt` is set to the actual dispatch timestamp.

2. **GIVEN** a `ScheduledInvoice` has `status: scheduled` and `dispatchAt` is 30 minutes in the future **WHEN** the job runs **THEN** the invoice is NOT dispatched; only invoices with `dispatchAt ≤ now` are processed.

3. **GIVEN** the email dispatch fails due to an `IMailer` error **WHEN** the job processes the scheduled invoice **THEN** `status` is set to `failed`, `failureReason` is set to the mailer error message, and the responsible user receives a Nextcloud notification "Scheduled invoice dispatch failed: {failureReason}."

4. **GIVEN** a `ScheduledInvoice` is in `scheduled` status **WHEN** the user cancels it via `DELETE /api/v1/scheduled-invoices/{id}` **THEN** `status` changes to `cancelled` and the job will not process it in subsequent runs.

5. **GIVEN** the same `ScheduledInvoice` is processed by two concurrent job executions (race condition) **WHEN** the second job picks up the record **THEN** a database-level unique constraint on `(invoiceId, status: dispatched)` or an optimistic lock prevents double dispatch.

### REQ-SCH-008: Strategic Payment Scheduling [must]

The app MUST register the `PaymentSchedule` schema (`schema:PaymentChargeSpecification`) linking outstanding invoices to planned payment dates. A planning view MUST aggregate all scheduled payments by date to enable treasury officers to visualise outgoing cash flow and align payment timing with the liquidity forecast. Payment priority (`urgent / high / normal / deferred`) MUST be used to sort and highlight payments in the planning view.

**Scenarios:**

1. **GIVEN** two `PaymentSchedule` objects exist for 2026-05-20 with amounts EUR 15 000 (normal) and EUR 42 500 (high) **WHEN** the payment planning view renders for week 2026-W21 **THEN** both payments appear on 2026-05-20 sorted by priority (high first), totalling EUR 57 500 for that day.

2. **GIVEN** the payment planning view is open alongside the `LiquidityForecast` **WHEN** the user views the week containing a shortfall **THEN** payments scheduled in that week are highlighted with an amber warning "Liquidity shortfall projected for this week."

3. **GIVEN** a treasury officer reschedules a payment by updating `scheduledDate` to a later date **WHEN** the update is saved **THEN** the `LiquidityForecastJob` recalculates on next run using the new date and the shortfall indicator is re-evaluated.

4. **GIVEN** a payment is executed by the ERP system and the invoice is marked paid **WHEN** the `PaymentSchedule` object is updated to `status: executed` with `executedAt` **THEN** it no longer appears in the pending payment planning view.

### REQ-SCH-009: Configurable Payment Reminder Escalation [must]

The app MUST register the `PaymentReminder` schema (`schema:Action`) with nested `ReminderStep` objects. A daily background job (`PaymentReminderJob`) MUST evaluate overdue invoices against active `PaymentReminder` configurations, dispatch the appropriate email template at each step, and advance `activeStep`. The reminder chain MUST support pausing (e.g. during a payment dispute) and resolution on invoice payment.

**Scenarios:**

1. **GIVEN** a `PaymentReminder` has `reminderSteps: [{stepNumber:1, daysAfterDue:7, templateKey:"reminder_friendly"}, {stepNumber:2, daysAfterDue:21, templateKey:"reminder_formal"}]` and the linked invoice is 7 days overdue **WHEN** `PaymentReminderJob` runs **THEN** the step 1 email is dispatched using template `reminder_friendly`, `activeStep` advances to `1`, and `reminderSteps[0].sentAt` is set.

2. **GIVEN** `activeStep: 1` and the invoice is now 21 days overdue **WHEN** the job runs **THEN** step 2 is triggered: the `reminder_formal` template is sent to `account_manager`, `activeStep` advances to `2`, and a CC notification is sent to `escalatesTo` if defined.

3. **GIVEN** a reminder is paused with `pausedUntil: 2026-06-15` **WHEN** `PaymentReminderJob` runs before 2026-06-15 **THEN** no reminder email is sent and `activeStep` is not advanced.

4. **GIVEN** the debtor pays the invoice **WHEN** the invoice status changes to `paid` **THEN** `PaymentReminder.status` is automatically set to `resolved` and `resolvedAt` is recorded; no further steps are triggered.

5. **GIVEN** a finance user adds a new `ReminderStep` to an active reminder with `daysAfterDue: 45, templateKey: "legal_notice"` **WHEN** the step is saved **THEN** it is appended to `reminderSteps` and will be triggered automatically when the invoice reaches 45 days overdue.

### REQ-SCH-010: Multi-Year Financial Planning [must]

The app MUST register the `MultiYearPlan` schema (`schema:CreativeWork`) with nested `MultiYearPlanYear` objects spanning 4–10 fiscal years. A matrix view MUST display each year's plan targets alongside actuals derived from linked `CashFlow` and `Budget` objects, with variance columns. The plan MUST support approval via the existing `ApprovalWorkflow` engine.

**Scenarios:**

1. **GIVEN** a `MultiYearPlan` covers 2026–2029 **WHEN** the plan detail matrix view renders **THEN** each year row shows: `revenueTarget`, `expenditureTarget`, `capitalExpenditure`, `procurementBudget`, and computed variance columns (target vs actual from linked `CashFlow` and `Budget` objects where available).

2. **GIVEN** a `MultiYearPlanYear` for 2026 links to `budgetId: budget-2026` **WHEN** `Budget.approvedAmount` is updated **THEN** the variance column for 2026 in the matrix view reflects the new actual figure on next page load.

3. **GIVEN** the plan is in `draft` status **WHEN** a finance manager submits it for approval **THEN** it enters the `ApprovalWorkflow`; plan year values are read-only until the plan is approved or the submission is rejected.

4. **GIVEN** the plan is `approved` **WHEN** a City Council Member user navigates to the multi-year plan view **THEN** they see the matrix with policy program annotations and can drill into any year's `procurementBudget` to see the linked `ProcurementPlan` for that year.

5. **GIVEN** the current date moves into a plan year (e.g. 2027) **WHEN** a finance user creates a new `Budget` for 2027 **THEN** they can link it to the existing `MultiYearPlanYear` for 2027 by entering the `budgetId`; the variance column immediately reflects actuals vs plan.

### REQ-SCH-011: Contract Closure Scheduling [must]

The app MUST register the `ContractClosure` schema (`schema:Action`) with nested `ClosureTask` objects. A background job (`ClosureTaskAlertJob`) MUST notify task assignees when their due date is within the configurable alert lead time (default 3 days). The closure MUST be marked `complete` only when all `ClosureTask` objects have a non-null `completedAt`.

**Scenarios:**

1. **GIVEN** a contract manager initiates closure via `POST /api/v1/contract-closures` with `contractId`, `closureDate: 2026-07-31`, and `declineReason: "Switching to preferred supplier"` **WHEN** the record is saved **THEN** a `ContractClosure` is created with `status: initiated` and the supplier notification task appears in the closure task list with `dueDate: 2026-07-31`.

2. **GIVEN** a `ClosureTask` with `taskType: supplier_notification` has `dueDate: 2026-06-01` **WHEN** `ClosureTaskAlertJob` runs on 2026-05-29 **THEN** the `assigneeId` receives a notification "Contract closure task due in 3 days: Supplier Notification — please action by 2026-06-01."

3. **GIVEN** the task assignee marks the task complete via `PATCH /api/v1/contract-closures/{id}/tasks/{taskId}` with `{completedAt: "2026-06-01T09:00:00Z"}` **WHEN** the update is processed **THEN** `ClosureTask.completedAt` is stored and the closure task checklist shows the task as completed; no further alerts are sent for that task.

4. **GIVEN** the last remaining open `ClosureTask` is marked complete **WHEN** the update is processed **THEN** `ContractClosure.status` automatically changes to `complete` and `completedAt` is set to the current timestamp.

5. **GIVEN** a `ContractClosure` is in `initiated` status **WHEN** the contract manager adds a new closure task for `data_deletion` via the form **THEN** the task is appended to `closureTasks` and `status` transitions to `in_progress` if it was previously `initiated`.

### REQ-SCH-012: Seed Data [must]

The app MUST load demo seed data for all new schemas via the repair step. Seed data MUST be idempotent — running the repair step multiple times MUST NOT create duplicate records.

**Scenarios:**

1. **GIVEN** a fresh Shillinq installation **WHEN** the repair step runs **THEN** all seed records are created: 1 LiquidityForecast (13 forecast weeks), 2 DelegationRules, 2 TenderDeadlines, 1 DigitalLockbox, 1 ProcurementPlan (2 plan lines), 1 DeliverySchedule (3 delivery lines), 1 ScheduledInvoice, 2 PaymentSchedules, 1 PaymentReminder (2 reminder steps), 1 MultiYearPlan (4 years), 1 ContractClosure (3 closure tasks).

2. **GIVEN** the repair step has already run **WHEN** it runs again **THEN** no duplicate records are created; idempotency is checked using: `LiquidityForecast.bankAccountId`, `DelegationRule.(delegatorId+startDate)`, `TenderDeadline.(referenceObjectId+deadlineType)`, `DigitalLockbox.tenderReferenceId`, `ProcurementPlan.(fiscalYear+title)`, `DeliverySchedule.callOffOrderId`, `ScheduledInvoice.invoiceId`, `PaymentSchedule.(invoiceId+scheduledDate)`, `PaymentReminder.invoiceId`, `MultiYearPlan.title`, `ContractClosure.contractId`.

3. **GIVEN** the seed data is loaded **WHEN** the admin navigates to each scheduling view **THEN** the seed records are visible and the index pages display correct column values without errors.
