---
status: proposed
source: specter
features: [cash-management-liquidity-forecasting-planning, delegation-rules-calendar-out-of-office-escalation, publish-award-notice-legal-deadline, digital-lockbox-bid-submission-deadline, procurement-planning-budget-allocation-tender-pipeline, set-delivery-schedule-call-off-order, receive-deadline-alerts-objection-responses, schedule-invoice-email-specific-date, multi-year-planning, decline-renewal-schedule-contract-closure, automate-reminder-escalation-workflow, automatic-payment-reminder-configurable-escalation, schedule-payments-strategically, schedule-payment-date]
---

# Scheduling — Shillinq

## Summary

Implements time-aware scheduling and deadline management across Shillinq's financial and procurement workflows: liquidity forecasting with cash flow projections, calendar-based delegation rules with out-of-office and escalation chains, procurement deadline tracking with legal award notice publication and digital lockbox enforcement, multi-year budget planning, delivery schedule management on call-off orders, scheduled invoice dispatch, strategic payment scheduling, configurable payment reminder escalation, and contract closure scheduling. These capabilities address the fourteen highest-demand scheduling features identified in the Specter intelligence model and build on the core, access-control-authorisation, collaboration, document-management, supplier-management, and catalog-purchase-management infrastructure changes.

## Demand Evidence

Top features by market demand score:

- **Cash management with liquidity forecasting and planning** (demand: 1748) — the top-ranked scheduling feature. Finance teams need rolling cash flow projections that aggregate expected payments and receipts to identify liquidity shortfalls before they occur, enabling strategic timing of outgoing payments.
- **Delegation rules with calendar-based out-of-office and escalation management** (demand: 1691) — approval workflows break down when approvers are unavailable. Organisations need structured delegation rules tied to calendar periods so that out-of-office approvers are automatically replaced by designated deputies, with escalation to a fallback if the deputy is also absent.
- **Publish award notice within legal deadline** (demand: 1062) — contracting authorities are legally required to publish award notices within defined timeframes (e.g. 15 days under Dutch public procurement law). Missed deadlines attract legal challenges. The system must track the deadline and alert responsible staff well in advance.
- **Digital lockbox technology preventing bid viewing before submission deadline** (demand: 1052) — open tenders require that submitted bids remain sealed until the deadline. A digital lockbox mechanism encrypts bid contents and makes them accessible only after the deadline passes, providing an auditable time-lock on sensitive procurement data.
- **Procurement planning with budget allocation linked to tender pipeline** (demand: 538) — procurement managers plan annual and multi-year purchasing activity by linking planned tenders to approved budget lines, enabling visibility into committed versus available budget across the pipeline.
- **Set delivery schedule on call-off order** (demand: 378) — framework contract call-off orders require detailed delivery schedules specifying quantities, delivery dates, and locations across multiple delivery lines.
- **Receive deadline alerts for objection responses** (demand: 342) — contracting authorities must respond to procurement objections within legally defined windows. Missed response deadlines result in automatic loss of the objection. Alerts are required well before the deadline.
- **Schedule invoice email for specific date** (demand: 327) — finance teams prepare invoices in advance but need them dispatched on a precise future date (e.g. start of the new month or the agreed billing cycle date).
- **Multi-year planning** (demand: 279) — municipalities and corporations require rolling multi-year financial plans (meerjarenbegroting) that link annual budgets, capital expenditure schedules, and procurement pipelines across a 4–10 year horizon.
- **Decline renewal and schedule contract closure** (demand: 248) — contract managers need to formally decline an auto-renewing contract and schedule all closure activities: final invoicing, asset return, data deletion, and notification to the supplier.

Key stakeholder pain points addressed:

- **Management Accountant**: manual cash flow projections in spreadsheets not linked to live payable/receivable data, no automated escalation when approvers are absent, multi-year planning disconnected from actual contract and procurement pipelines — addressed by LiquidityForecast, DelegationRule, and MultiYearPlan schemas.
- **City Council Member**: no visibility into multi-year capital expenditure commitments, budget vs pipeline alignment not transparent, no self-service deadline monitoring — addressed by ProcurementPlan and MultiYearPlan views with policy-linked drill-down.
- **Bank / Financier**: outdated liquidity position, covenant monitoring depends on manual reports — addressed by LiquidityForecast exposing real-time cash position derived from live CashFlow and ScheduledPayment data.

## What Shillinq Already Has

After the core, access-control-authorisation, collaboration, document-management, supplier-management, and catalog-purchase-management changes:

- OpenRegister schemas for `CashFlow`, `Budget`, `Award`, `Bid`, `BankAccount`, `CashAccount`, `ApprovalWorkflow`, `AccessControl`, `Comment`, `Document`
- Nextcloud notification integration and mention engine
- Entity list, detail, and form views using `CnIndexPage` / `CnDetailPage` / `CnFormDialog`
- Global search, admin settings, sidebar navigation, user preferences
- Approval workflow engine with role-based guards

### What Is Missing

- No `LiquidityForecast` schema for rolling cash projection with shortfall detection
- No `DelegationRule` schema for calendar-bound deputy assignment and escalation chains
- No `TenderDeadline` schema for tracking legal procurement deadlines (award notices, objection responses)
- No `DigitalLockbox` schema for time-locked bid encapsulation
- No `ProcurementPlan` schema for budget-linked tender pipeline planning
- No `DeliverySchedule` schema for call-off order delivery line management
- No `ScheduledInvoice` schema for deferred invoice dispatch
- No `PaymentSchedule` schema for strategic payment timing
- No `PaymentReminder` schema for escalating reminder chains
- No `MultiYearPlan` schema for rolling multi-year financial planning
- No `ContractClosure` schema for structured contract wind-down
- No background jobs for deadline alerting, reminder escalation, or lockbox expiry
- No calendar integration for delegation rule evaluation

## Scope

### In Scope

1. **LiquidityForecast Schema** — OpenRegister `LiquidityForecast` schema (`schema:FinancialProduct`) aggregating projected inflows (outstanding receivables, scheduled receipts) and outflows (scheduled payments, recurring obligations) across a configurable horizon (default 13 weeks). A daily background job recalculates the forecast and flags periods where projected balance drops below a configurable minimum threshold. Views at `src/views/liquidityForecast/`; store at `src/store/modules/liquidityForecast.js`.

2. **DelegationRule Schema** — OpenRegister `DelegationRule` schema (`schema:Action`) binding a delegator, a deputy, an optional fallback, a calendar period (`startDate` / `endDate`), and a scope (all approvals or a specific `ApprovalWorkflow` type). The `ApprovalWorkflow` engine checks active delegation rules before routing each approval. Overlapping rules use the most specific (narrowest workflow scope) first. Views at `src/views/delegationRule/`; store at `src/store/modules/delegationRule.js`.

3. **TenderDeadline Schema** — OpenRegister `TenderDeadline` schema (`schema:Event`) tracks legally mandated procurement deadlines: award notice publication, objection response, standstill period expiry, and bid submission close. Each deadline record links to an `Award` or `Bid` object, carries a `deadlineType`, `dueDate`, `legalBasis` text, and `status` (pending / met / missed / waived). A background job sends Nextcloud notifications at configurable lead times (default: 5 days and 1 day before due). Views at `src/views/tenderDeadline/`; store at `src/store/modules/tenderDeadline.js`.

4. **DigitalLockbox Schema** — OpenRegister `DigitalLockbox` schema (`schema:Thing`) encapsulates submitted `Bid` objects with an AES-256 server-side encryption key that is split: one half stored in OpenRegister, the other released only after `unlockAfter` datetime passes. A PHP `LockboxService` manages encryption on bid submission and decryption after the deadline via Nextcloud's `ICrypto`. No bid content is readable before the deadline even by administrators. Views at `src/views/digitalLockbox/`; store at `src/store/modules/digitalLockbox.js`.

5. **ProcurementPlan Schema** — OpenRegister `ProcurementPlan` schema (`schema:CreativeWork`) groups planned tenders by fiscal year with a link to the parent `Budget` object. Each plan line (`ProcurementPlanLine`, nested object array) holds category, estimated value, planned publication quarter, responsible officer, and a reference to the `Award` once published. Views at `src/views/procurementPlan/`; store at `src/store/modules/procurementPlan.js`.

6. **DeliverySchedule Schema** — OpenRegister `DeliverySchedule` schema (`schema:Schedule`) attached to a call-off order, containing an array of `DeliveryLine` nested objects (deliveryDate, quantity, unit, locationId, notes, status). A background job alerts the responsible buyer when a delivery line is within 3 days of its due date. Views at `src/views/deliverySchedule/`; store at `src/store/modules/deliverySchedule.js`.

7. **ScheduledInvoice Schema** — OpenRegister `ScheduledInvoice` schema (`schema:Invoice`) wraps a draft invoice with a `dispatchAt` datetime. A background job runs every 15 minutes, finds invoices where `dispatchAt ≤ now` and `status: scheduled`, and triggers the standard invoice email dispatch pipeline. The job sets `status: dispatched` and records `dispatchedAt` on completion. Views at `src/views/scheduledInvoice/`; store at `src/store/modules/scheduledInvoice.js`.

8. **PaymentSchedule Schema** — OpenRegister `PaymentSchedule` schema (`schema:PaymentChargeSpecification`) records the planned payment date for an outstanding payable: linked invoice, bank account, amount, currency, `scheduledDate`, `priority` (urgent / high / normal / deferred), and optional `notes`. A planning view aggregates all scheduled payments by date to enable strategic payment timing against the liquidity forecast. Views at `src/views/paymentSchedule/`; store at `src/store/modules/paymentSchedule.js`.

9. **PaymentReminder Schema** — OpenRegister `PaymentReminder` schema (`schema:Action`) defines an escalating reminder chain for overdue receivables: linked invoice, a `ReminderStep` array (stepNumber, daysAfterDue, templateKey, recipientRole, escalatesTo), and current `activeStep`. A daily background job evaluates overdue invoices against configured reminder steps, sends the appropriate template email via Nextcloud Mail, and advances `activeStep`. Views at `src/views/paymentReminder/`; store at `src/store/modules/paymentReminder.js`.

10. **MultiYearPlan Schema** — OpenRegister `MultiYearPlan` schema (`schema:CreativeWork`) represents a rolling multi-year financial plan spanning 4–10 fiscal years. Each year contains a `MultiYearPlanYear` nested array (fiscalYear, revenueTarget, expenditureTarget, capitalExpenditure, procurementBudget, notes). A dedicated view shows the plan as a matrix with variance-from-actuals columns computed from linked `CashFlow` and `Budget` objects. Views at `src/views/multiYearPlan/`; store at `src/store/modules/multiYearPlan.js`.

11. **ContractClosure Schema** — OpenRegister `ContractClosure` schema (`schema:Action`) captures the structured wind-down of a contract after renewal decline: linked contract object ID, `closureDate`, a `ClosureTask` array (taskType, dueDate, assigneeId, completedAt), and `status` (initiated / in_progress / complete). A background job monitors open closure tasks and notifies assignees at configurable lead times. Views at `src/views/contractClosure/`; store at `src/store/modules/contractClosure.js`.

12. **Seed Data** — demo records for all new schemas (ADR-016): 1 LiquidityForecast, 2 DelegationRules, 2 TenderDeadlines, 1 DigitalLockbox, 1 ProcurementPlan (with 2 plan lines), 1 DeliverySchedule (with 3 delivery lines), 1 ScheduledInvoice, 2 PaymentSchedules, 1 PaymentReminder (with 2 reminder steps), 1 MultiYearPlan (4 years), 1 ContractClosure (with 3 closure tasks). Loaded via the repair step idempotently.

### Out of Scope

- Full calendar UI (CalDAV) integration — Nextcloud Calendar app handles this; delegation rules reference date ranges only
- Real-time treasury management system (TMS) API feeds — deferred to a banking integration change
- Automated court deadline synchronisation — requires external legal API agreement, deferred
- Multi-currency liquidity forecasting with FX hedging — deferred to multi-currency change
- Public tender portal bid submission UI — handled by procurement portal change
- EDI/Peppol delivery notification messages — deferred to e-invoicing change

## Acceptance Criteria

1. GIVEN cash inflows and outflows are recorded in `CashFlow` and `PaymentSchedule` WHEN the daily `LiquidityForecastJob` runs THEN a `LiquidityForecast` object is updated with per-week projected balances for the next 13 weeks and any week where balance < threshold is flagged with `status: shortfall`
2. GIVEN a `DelegationRule` is active for a delegator during the current date range WHEN the `ApprovalWorkflow` engine routes an approval to the delegator THEN the workflow is redirected to the deputy, and a notification is sent to both the deputy and the original delegator
3. GIVEN a `TenderDeadline` with `dueDate` 5 days in the future WHEN the deadline alert job runs THEN the responsible officer receives a Nextcloud notification with the deadline type, due date, and linked procurement reference
4. GIVEN a `Bid` is submitted before a `DigitalLockbox` `unlockAfter` datetime WHEN the bid is stored THEN the bid content is encrypted and inaccessible; WHEN `unlockAfter` passes THEN `LockboxService` decrypts the bid content and sets `status: unlocked`
5. GIVEN a `ProcurementPlan` is linked to a `Budget` WHEN the budget utilisation view renders THEN planned tender values summed per fiscal year are shown alongside the approved budget with a surplus/deficit indicator
6. GIVEN a `ScheduledInvoice` has `dispatchAt` in the past and `status: scheduled` WHEN the scheduled dispatch job runs THEN the invoice email is sent, `status` is set to `dispatched`, and `dispatchedAt` is recorded
7. GIVEN an overdue receivable matches a `PaymentReminder` step 1 condition (7 days overdue) WHEN the daily reminder job runs THEN the configured reminder email is dispatched and `activeStep` advances to 2
8. GIVEN a `ContractClosure` is initiated WHEN the closure background job finds a `ClosureTask` due within 3 days THEN the `assigneeId` receives a Nextcloud notification listing the task type and due date
