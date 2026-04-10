# Design: Scheduling — Shillinq

## Architecture Overview

This change adds time-aware scheduling and deadline management across Shillinq's financial and procurement workflows. All entities follow the OpenRegister thin-client pattern: no custom database tables, all data via `ObjectService`. Background jobs handle deadline alerting, reminder escalation, liquidity recalculation, and lockbox expiry. PHP services encapsulate domain logic and are injected into controllers and background jobs.

```
Browser (Vue 2.7 + Pinia)
    │
    ├─ OpenRegister REST API  (LiquidityForecast, DelegationRule,
    │                          TenderDeadline, DigitalLockbox,
    │                          ProcurementPlan, DeliverySchedule,
    │                          ScheduledInvoice, PaymentSchedule,
    │                          PaymentReminder, MultiYearPlan,
    │                          ContractClosure CRUD)
    │
    └─ Shillinq OCS API
            ├─ SchedulingController      (lockbox unlock, reminder advance, forecast trigger)
            ├─ LockboxController         (bid submission with encryption, manual unlock)
            ├─ DelegationController      (active delegate lookup for approval routing)
            └─ ProcurementPlanController (budget utilisation summary)
                    │
                    └─ PHP Services
                            ├─ LockboxService          (AES-256 encrypt/decrypt via ICrypto)
                            ├─ LiquidityForecastService (aggregate CashFlow + PaymentSchedule)
                            ├─ DelegationResolverService (active rule lookup for a given user+date)
                            ├─ DeadlineAlertService    (compute alert dates, build notifications)
                            └─ ReminderEscalationService (evaluate overdue steps, send emails)
                    │
                    └─ Background Jobs
                            ├─ LiquidityForecastJob   (daily)
                            ├─ DeadlineAlertJob        (daily)
                            ├─ LockboxExpiryJob        (every 15 min)
                            ├─ ScheduledInvoiceJob     (every 15 min)
                            ├─ PaymentReminderJob      (daily)
                            └─ ClosureTaskAlertJob     (daily)
```

## Data Model

### LiquidityForecast (`schema:FinancialProduct`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| bankAccountId | string | Yes | — | OpenRegister object ID of the parent BankAccount or CashAccount |
| generatedAt | datetime | Yes | — | Timestamp when the forecast was last recalculated |
| horizonWeeks | integer | No | 13 | Number of forward weeks in the forecast |
| minimumBalanceThreshold | number | No | 0 | Alert threshold in account currency |
| currency | string | No | EUR | ISO 4217 currency code |
| openingBalance | number | Yes | — | Balance at forecast start date |
| forecastWeeks | array | Yes | [] | Array of ForecastWeek objects (see nested schema) |
| status | string | Yes | current | Enum: current / shortfall / stale |
| shortfallWeeks | array | No | [] | Array of week numbers where balance < threshold |
| notes | string | No | — | Analyst annotations |

**ForecastWeek (nested object):**

| Property | Type | Notes |
|----------|------|-------|
| weekNumber | integer | ISO week number |
| weekStartDate | string | ISO date |
| projectedInflow | number | Sum of expected receipts |
| projectedOutflow | number | Sum of scheduled payments and obligations |
| projectedBalance | number | Running balance at week end |
| isShortfall | boolean | true if projectedBalance < minimumBalanceThreshold |

### DelegationRule (`schema:Action`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| delegatorId | string | Yes | — | userId of the absent approver |
| deputyId | string | Yes | — | userId of the primary delegate |
| fallbackId | string | No | — | userId of the secondary delegate if deputy is also absent |
| startDate | datetime | Yes | — | Start of the delegation period |
| endDate | datetime | Yes | — | End of the delegation period (inclusive) |
| scope | string | Yes | all | Enum: all / specific_workflow_type |
| workflowType | string | No | — | ApprovalWorkflow type key; required when scope is specific_workflow_type |
| reason | string | No | — | Out-of-office reason (e.g. "Annual leave", "Sick leave") |
| notifyDelegator | boolean | No | true | Whether to send notification to delegator on each delegation |
| createdBy | string | Yes | — | userId who created the rule |
| isActive | boolean | No | true | Computed: true when current date is within startDate–endDate |

### TenderDeadline (`schema:Event`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| referenceObjectType | string | Yes | — | Enum: Award / Bid / SourcingEvent |
| referenceObjectId | string | Yes | — | OpenRegister object ID of the linked entity |
| deadlineType | string | Yes | — | Enum: award_notice / objection_response / standstill_expiry / bid_submission_close / clarification_deadline |
| dueDate | datetime | Yes | — | Legal deadline datetime |
| legalBasis | string | No | — | Legal reference text (e.g. "Aanbestedingswet art. 2.131 lid 1") |
| status | string | Yes | pending | Enum: pending / met / missed / waived |
| metAt | datetime | No | — | Timestamp when the deadline was marked as met |
| metBy | string | No | — | userId who marked the deadline as met |
| alertLeadDays | array | No | [5,1] | Days before dueDate to send alert notifications |
| responsibleOfficerId | string | Yes | — | userId who receives deadline alerts |
| notes | string | No | — | Internal notes |

### DigitalLockbox (`schema:Thing`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| tenderReferenceId | string | Yes | — | OpenRegister object ID of the SourcingEvent or Award |
| unlockAfter | datetime | Yes | — | Bid submission deadline; bids become readable only after this |
| status | string | Yes | locked | Enum: locked / unlocked / voided |
| unlockedAt | datetime | No | — | Timestamp when the lockbox was opened |
| encryptedBidIds | array | No | [] | Array of Bid object IDs whose content is encrypted |
| keyShardStored | boolean | No | false | True once the server-side key shard is stored in OpenRegister |
| createdBy | string | Yes | — | userId of the procurement manager who created the lockbox |
| auditLog | array | No | [] | Array of {timestamp, userId, action} access events |

### ProcurementPlan (`schema:CreativeWork`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| title | string | Yes | — | e.g. "Procurement Plan 2026" |
| fiscalYear | integer | Yes | — | Four-digit fiscal year |
| budgetId | string | No | — | OpenRegister object ID of the linked Budget |
| status | string | Yes | draft | Enum: draft / approved / active / closed |
| totalPlannedValue | number | No | — | Computed: sum of all planLines.estimatedValue |
| planLines | array | No | [] | Array of ProcurementPlanLine nested objects |
| approvedBy | string | No | — | userId of the approving officer |
| approvedAt | datetime | No | — | Timestamp of approval |
| notes | string | No | — | Planning notes |

**ProcurementPlanLine (nested object):**

| Property | Type | Notes |
|----------|------|-------|
| lineId | string | UUID, generated on creation |
| categoryName | string | Category description |
| cpvCode | string | Primary CPV code |
| estimatedValue | number | EUR |
| plannedPublicationQuarter | string | Enum: Q1 / Q2 / Q3 / Q4 |
| responsibleOfficerId | string | userId |
| awardId | string | OpenRegister object ID of linked Award (once published) |
| status | string | Enum: planned / published / awarded / cancelled |

### DeliverySchedule (`schema:Schedule`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| callOffOrderId | string | Yes | — | OpenRegister object ID of the parent call-off order |
| supplierProfileId | string | No | — | OpenRegister object ID of the SupplierProfile |
| responsibleBuyerId | string | Yes | — | userId of the buyer managing deliveries |
| alertLeadDays | integer | No | 3 | Days before delivery line due date to send alert |
| deliveryLines | array | Yes | [] | Array of DeliveryLine nested objects |
| notes | string | No | — | Schedule-level notes |

**DeliveryLine (nested object):**

| Property | Type | Notes |
|----------|------|-------|
| lineId | string | UUID |
| deliveryDate | datetime | Expected delivery date |
| quantity | number | Quantity to deliver |
| unit | string | Unit of measure (e.g. "pcs", "kg") |
| locationId | string | OpenRegister object ID of delivery location |
| status | string | Enum: scheduled / confirmed / delivered / partial / missed |
| deliveredAt | datetime | Actual delivery timestamp |
| notes | string | Line-level notes |

### ScheduledInvoice (`schema:Invoice`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| invoiceId | string | Yes | — | OpenRegister object ID of the draft invoice |
| dispatchAt | datetime | Yes | — | Scheduled dispatch datetime |
| recipientEmail | string | Yes | — | Recipient email address |
| subject | string | No | — | Email subject; defaults to invoice template subject if empty |
| status | string | Yes | scheduled | Enum: scheduled / dispatched / cancelled / failed |
| dispatchedAt | datetime | No | — | Timestamp when email was successfully sent |
| failureReason | string | No | — | Error message if dispatch failed |
| createdBy | string | Yes | — | userId who scheduled the invoice |

### PaymentSchedule (`schema:PaymentChargeSpecification`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| invoiceId | string | Yes | — | OpenRegister object ID of the outstanding payable invoice |
| bankAccountId | string | No | — | OpenRegister object ID of the paying BankAccount |
| amount | number | Yes | — | Payment amount |
| currency | string | No | EUR | ISO 4217 currency code |
| scheduledDate | datetime | Yes | — | Planned payment execution date |
| priority | string | Yes | normal | Enum: urgent / high / normal / deferred |
| status | string | Yes | scheduled | Enum: scheduled / executed / cancelled |
| executedAt | datetime | No | — | Timestamp of actual payment execution |
| notes | string | No | — | Reason for scheduling (e.g. "Awaiting client receipt on 15th") |
| createdBy | string | Yes | — | userId who created the schedule |

### PaymentReminder (`schema:Action`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| invoiceId | string | Yes | — | OpenRegister object ID of the overdue invoice |
| activeStep | integer | No | 0 | Index of the current reminder step (0 = none sent yet) |
| status | string | Yes | active | Enum: active / paused / resolved / escalated_to_legal |
| reminderSteps | array | Yes | [] | Array of ReminderStep nested objects |
| pausedUntil | datetime | No | — | Date when reminder escalation is paused (e.g. during dispute) |
| resolvedAt | datetime | No | — | Timestamp when invoice was paid or written off |
| notes | string | No | — | Internal notes on the reminder history |

**ReminderStep (nested object):**

| Property | Type | Notes |
|----------|------|-------|
| stepNumber | integer | 1-based step index |
| daysAfterDue | integer | Number of days past invoice due date to trigger |
| templateKey | string | Email template identifier in AppSettings |
| recipientRole | string | Enum: debtor / account_manager / legal |
| escalatesTo | string | userId to CC or escalate to at this step |
| sentAt | datetime | Timestamp when this step's email was sent |

### MultiYearPlan (`schema:CreativeWork`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| title | string | Yes | — | e.g. "Meerjarenbegroting 2026–2030" |
| startYear | integer | Yes | — | First fiscal year in the plan |
| endYear | integer | Yes | — | Last fiscal year in the plan |
| status | string | Yes | draft | Enum: draft / approved / active / superseded |
| planYears | array | Yes | [] | Array of MultiYearPlanYear nested objects |
| approvedBy | string | No | — | userId of the approving officer |
| approvedAt | datetime | No | — | Timestamp of approval |
| notes | string | No | — | Plan-level notes and assumptions |

**MultiYearPlanYear (nested object):**

| Property | Type | Notes |
|----------|------|-------|
| fiscalYear | integer | Four-digit year |
| revenueTarget | number | EUR |
| expenditureTarget | number | EUR |
| capitalExpenditure | number | EUR |
| procurementBudget | number | EUR |
| budgetId | string | OpenRegister object ID of the approved Budget for this year |
| notes | string | Year-specific notes |

### ContractClosure (`schema:Action`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| contractId | string | Yes | — | OpenRegister object ID of the contract being closed |
| closureDate | datetime | Yes | — | Target closure date |
| declineReason | string | Yes | — | Reason for declining renewal |
| status | string | Yes | initiated | Enum: initiated / in_progress / complete / cancelled |
| notifiedSupplierAt | datetime | No | — | Timestamp supplier was notified of non-renewal |
| closureTasks | array | No | [] | Array of ClosureTask nested objects |
| completedAt | datetime | No | — | Timestamp when all tasks are complete |
| createdBy | string | Yes | — | userId who initiated the closure |

**ClosureTask (nested object):**

| Property | Type | Notes |
|----------|------|-------|
| taskId | string | UUID |
| taskType | string | Enum: final_invoice / asset_return / data_deletion / supplier_notification / access_revocation / other |
| dueDate | datetime | Task completion deadline |
| assigneeId | string | userId responsible for the task |
| completedAt | datetime | Timestamp of task completion |
| notes | string | Task-specific notes |

## Component Architecture

### PHP Services

| Service | Responsibility |
|---------|---------------|
| `LockboxService` | Encrypts `Bid` content with AES-256 on submission using Nextcloud `ICrypto`; stores encrypted payload in OpenRegister; decrypts after `unlockAfter` using the stored key shard; writes to audit log |
| `LiquidityForecastService` | Queries `CashFlow` and `PaymentSchedule` objects for the account; aggregates inflows and outflows by ISO week; computes running balance; identifies shortfall weeks |
| `DelegationResolverService` | Accepts a userId and datetime; queries active `DelegationRule` objects; returns the effective delegate; handles fallback chain; logs delegation events |
| `DeadlineAlertService` | Computes alert dates for each `TenderDeadline` from `alertLeadDays`; sends Nextcloud notifications using `INotifier`; marks alerts as sent to prevent duplicates |
| `ReminderEscalationService` | Evaluates overdue invoices against `PaymentReminder.reminderSteps`; sends email via Nextcloud Mail (`IMailer`); advances `activeStep`; handles pause and resolve transitions |

### Background Jobs

| Job | Schedule | Behaviour |
|-----|----------|-----------|
| `LiquidityForecastJob` | Daily (ITimedJobList) | Calls `LiquidityForecastService` for each active `BankAccount`; updates corresponding `LiquidityForecast` objects; notifies the bank account owner if `status` changes to `shortfall` |
| `DeadlineAlertJob` | Daily (ITimedJobList) | Queries all `TenderDeadline` objects with `status: pending`; for each, calls `DeadlineAlertService` with the configured `alertLeadDays`; marks deadline `missed` if `dueDate` has passed without `status: met` |
| `LockboxExpiryJob` | Every 15 min (ITimedJobList) | Queries `DigitalLockbox` objects with `status: locked` and `unlockAfter ≤ now`; calls `LockboxService.unlock()` for each; sets `status: unlocked` and `unlockedAt` |
| `ScheduledInvoiceJob` | Every 15 min (ITimedJobList) | Queries `ScheduledInvoice` objects with `status: scheduled` and `dispatchAt ≤ now`; triggers invoice email dispatch; sets `status: dispatched` or `failed` with `failureReason` |
| `PaymentReminderJob` | Daily (ITimedJobList) | Queries invoices referenced by active `PaymentReminder` objects; calls `ReminderEscalationService` for each overdue invoice; advances step counters |
| `ClosureTaskAlertJob` | Daily (ITimedJobList) | Queries open `ContractClosure` objects; for each `ClosureTask` where `dueDate` is within `alertLeadDays` (default 3) of now and `completedAt` is null, sends a notification to `assigneeId` |

### Vue Component Structure

```
src/
├── views/
│   ├── liquidityForecast/
│   │   ├── LiquidityForecastIndex.vue     (CnIndexPage — account selector + status chips)
│   │   ├── LiquidityForecastDetail.vue    (CnDetailPage — weekly cash flow bar chart)
│   │   └── LiquidityForecastForm.vue      (CnFormDialog — threshold and horizon config)
│   ├── delegationRule/
│   │   ├── DelegationRuleIndex.vue        (CnIndexPage — active/inactive filter)
│   │   ├── DelegationRuleDetail.vue       (CnDetailPage)
│   │   └── DelegationRuleForm.vue         (CnFormDialog — date range picker, deputy selector)
│   ├── tenderDeadline/
│   │   ├── TenderDeadlineIndex.vue        (CnIndexPage — deadline type + status filters)
│   │   ├── TenderDeadlineDetail.vue       (CnDetailPage)
│   │   └── TenderDeadlineForm.vue         (CnFormDialog)
│   ├── digitalLockbox/
│   │   ├── DigitalLockboxIndex.vue        (CnIndexPage)
│   │   ├── DigitalLockboxDetail.vue       (CnDetailPage — bid list with lock/unlock status)
│   │   └── DigitalLockboxForm.vue         (CnFormDialog)
│   ├── procurementPlan/
│   │   ├── ProcurementPlanIndex.vue       (CnIndexPage — fiscal year filter)
│   │   ├── ProcurementPlanDetail.vue      (CnDetailPage — tabs: Plan Lines, Budget Utilisation)
│   │   └── ProcurementPlanForm.vue        (CnFormDialog)
│   ├── deliverySchedule/
│   │   ├── DeliveryScheduleIndex.vue      (CnIndexPage)
│   │   ├── DeliveryScheduleDetail.vue     (CnDetailPage — delivery line timeline)
│   │   └── DeliveryScheduleForm.vue       (CnFormDialog)
│   ├── scheduledInvoice/
│   │   ├── ScheduledInvoiceIndex.vue      (CnIndexPage — dispatch date + status filter)
│   │   ├── ScheduledInvoiceDetail.vue     (CnDetailPage)
│   │   └── ScheduledInvoiceForm.vue       (CnFormDialog — datetime picker for dispatchAt)
│   ├── paymentSchedule/
│   │   ├── PaymentScheduleIndex.vue       (CnIndexPage — date range + priority filter)
│   │   ├── PaymentScheduleDetail.vue      (CnDetailPage)
│   │   └── PaymentScheduleForm.vue        (CnFormDialog)
│   ├── paymentReminder/
│   │   ├── PaymentReminderIndex.vue       (CnIndexPage — status + step filter)
│   │   ├── PaymentReminderDetail.vue      (CnDetailPage — reminder step timeline)
│   │   └── PaymentReminderForm.vue        (CnFormDialog — reminder step builder)
│   ├── multiYearPlan/
│   │   ├── MultiYearPlanIndex.vue         (CnIndexPage)
│   │   ├── MultiYearPlanDetail.vue        (CnDetailPage — year matrix with actuals variance)
│   │   └── MultiYearPlanForm.vue          (CnFormDialog)
│   └── contractClosure/
│       ├── ContractClosureIndex.vue       (CnIndexPage — status filter)
│       ├── ContractClosureDetail.vue      (CnDetailPage — closure task checklist)
│       └── ContractClosureForm.vue        (CnFormDialog)
├── components/
│   ├── LiquidityCashFlowChart.vue         (weekly bar chart embedded in LiquidityForecastDetail)
│   ├── ProcurementPlanLineTable.vue       (editable plan line table in ProcurementPlanDetail)
│   ├── DeliveryLineTimeline.vue           (timeline of delivery lines in DeliveryScheduleDetail)
│   ├── ReminderStepBuilder.vue            (drag-to-reorder step builder in PaymentReminderForm)
│   └── ClosureTaskChecklist.vue           (task list in ContractClosureDetail)
└── store/modules/
    ├── liquidityForecast.js               (createObjectStore('liquidityForecast'))
    ├── delegationRule.js                  (createObjectStore('delegationRule'))
    ├── tenderDeadline.js                  (createObjectStore('tenderDeadline'))
    ├── digitalLockbox.js                  (createObjectStore('digitalLockbox'))
    ├── procurementPlan.js                 (createObjectStore('procurementPlan'))
    ├── deliverySchedule.js                (createObjectStore('deliverySchedule'))
    ├── scheduledInvoice.js                (createObjectStore('scheduledInvoice'))
    ├── paymentSchedule.js                 (createObjectStore('paymentSchedule'))
    ├── paymentReminder.js                 (createObjectStore('paymentReminder'))
    ├── multiYearPlan.js                   (createObjectStore('multiYearPlan'))
    └── contractClosure.js                 (createObjectStore('contractClosure'))
```

## Seed Data (ADR-016)

### LiquidityForecast seed object

| Field | Value |
|-------|-------|
| bankAccountId | `seed-bank-account-1` |
| horizonWeeks | `13` |
| minimumBalanceThreshold | `50000.00` |
| currency | `EUR` |
| openingBalance | `120000.00` |
| status | `current` |
| forecastWeeks | 13 ForecastWeek objects with synthetic inflow/outflow data |

### DelegationRule seed objects

| Field | Value (Rule 1) | Value (Rule 2) |
|-------|----------------|----------------|
| delegatorId | `admin` | `admin` |
| deputyId | `seed-user-finance-1` | `seed-user-procurement-1` |
| scope | `all` | `specific_workflow_type` |
| workflowType | — | `purchase_order_approval` |
| startDate | 2026-07-01 | 2026-08-01 |
| endDate | 2026-07-14 | 2026-08-07 |
| reason | `Annual leave` | `Training week` |

### TenderDeadline seed objects

| Field | Value (Deadline 1) | Value (Deadline 2) |
|-------|--------------------|--------------------|
| deadlineType | `award_notice` | `objection_response` |
| dueDate | 2026-05-20 | 2026-06-03 |
| legalBasis | `Aanbestedingswet art. 2.131 lid 1` | `Aanbestedingswet art. 2.136` |
| status | `pending` | `pending` |
| alertLeadDays | `[5,1]` | `[5,1]` |

### DigitalLockbox seed object

| Field | Value |
|-------|-------|
| unlockAfter | 2026-05-31T12:00:00Z |
| status | `locked` |
| encryptedBidIds | `[]` |
| keyShardStored | `true` |

### ProcurementPlan seed object

| Field | Value |
|-------|-------|
| title | `Procurement Plan 2026` |
| fiscalYear | `2026` |
| status | `approved` |
| planLines | 2 lines: "Office Supplies" (Q2, CPV 30192000, EUR 80000) and "IT Hardware" (Q3, CPV 30213000, EUR 250000) |

### DeliverySchedule seed object (3 delivery lines)

| lineId | deliveryDate | quantity | unit | status |
|--------|-------------|----------|------|--------|
| `dl-1` | 2026-06-15 | 500 | pcs | `scheduled` |
| `dl-2` | 2026-09-15 | 500 | pcs | `scheduled` |
| `dl-3` | 2026-12-15 | 500 | pcs | `scheduled` |

### ScheduledInvoice seed object

| Field | Value |
|-------|-------|
| dispatchAt | 2026-07-01T08:00:00Z |
| recipientEmail | `finance@demo-client.nl` |
| status | `scheduled` |

### PaymentSchedule seed objects (2)

1. Amount EUR 15 000 — scheduled 2026-05-10 — priority `normal`
2. Amount EUR 42 500 — scheduled 2026-05-20 — priority `high`

### PaymentReminder seed object (2 steps)

| stepNumber | daysAfterDue | templateKey | recipientRole |
|------------|-------------|-------------|---------------|
| 1 | 7 | `reminder_friendly` | `debtor` |
| 2 | 21 | `reminder_formal` | `account_manager` |

### MultiYearPlan seed object (4 years: 2026–2029)

| fiscalYear | revenueTarget | expenditureTarget | capitalExpenditure | procurementBudget |
|------------|--------------|-------------------|--------------------|-------------------|
| 2026 | 5 000 000 | 4 800 000 | 500 000 | 1 200 000 |
| 2027 | 5 200 000 | 4 900 000 | 600 000 | 1 300 000 |
| 2028 | 5 400 000 | 5 000 000 | 400 000 | 1 100 000 |
| 2029 | 5 600 000 | 5 100 000 | 350 000 | 1 050 000 |

### ContractClosure seed object (3 closure tasks)

| taskType | dueDate | assigneeId |
|----------|---------|------------|
| `supplier_notification` | 2026-06-01 | `seed-user-procurement-1` |
| `final_invoice` | 2026-06-15 | `seed-user-finance-1` |
| `data_deletion` | 2026-07-01 | `seed-user-admin-1` |

## Security Considerations

- `LockboxService` uses Nextcloud `ICrypto` for AES-256 encryption; encryption keys are never stored as plaintext in OpenRegister objects or logs
- Bid content is returned as encrypted binary blob via OpenRegister; the decrypted payload is only materialised in memory during API response and never persisted in decrypted form
- `DelegationRule` creation requires `approver` CollaborationRole; delegation chains cannot exceed depth 2 (delegate → fallback) to prevent circular delegation
- `TenderDeadline` `status: met` transitions are append-only (no reverting to `pending`) to preserve the legal audit trail
- `ScheduledInvoice.recipientEmail` is validated against the linked invoice's debtor email before scheduling to prevent misdirected dispatch
- Payment reminder emails are sent via Nextcloud's `IMailer`; no third-party email service is introduced
- `DigitalLockbox.auditLog` is written by the server; Audit trail is provided by OpenRegister automatically — do NOT add auditLog as a schema property
