# Spec Delta: Retainer Billing Management

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (billing + operations)
**Depends on:** rate-card-engine

Per ADR-022 (declarative aggregations), drawdown queries are aggregation-based,
not PHP services. Per ADR-031 (schema-driven logic), retainer state mutations
(drawdown materialization, true-up generation) are lifecycle actions, not
bespoke code.

## ADDED Requirements

### Requirement: REQ-RETN-001 The system SHALL define retainer pools with effective-date windows

A `RetainerPool` MUST define a monthly retainer allocation with effective-date
windows and the following fields: `poolId`, `clientId`, optional `projectId`,
`periodStart`, `periodEnd`, `poolAmount`, `currency`, `retainerRate`,
`rolloverPolicy`, `administrationId`, and `status`. Pools with non-overlapping
`period` windows allow historical retainer tracking. Overlapping periods for
the same (clientId, projectId) pair MUST be rejected at activation time with
the error: "Retainer pool exists for {client} in period {start}..{end};
overlapping periods not allowed".

#### Scenario: Create a monthly retainer pool for a client

- **GIVEN** Gemeente Amsterdam has a 2026-01 consulting engagement
- **WHEN** operator creates RetainerPool(period="2026-01-01..2026-01-31", poolAmount=3000 EUR, retainerRate=75 EUR/hour)
- **THEN** the pool is created with status=draft and no overlapping pool exists for this client in 2026-01

#### Scenario: Overlapping period is rejected

- **GIVEN** RetainerPool RETN-2026-01-001 for Gemeente Amsterdam covers 2026-01-01..2026-01-31 with status=active
- **WHEN** operator activates a second pool for Gemeente Amsterdam with periodStart=2026-01-15
- **THEN** the activation is rejected with an "overlapping periods not allowed" error

### Requirement: REQ-RETN-002 The system SHALL materialize a RetainerDrawdown on each time-entry consumption

Each `TimeEntry` booked against a retainer pool MUST trigger a `RetainerDrawdown`
record with the fields `drawdownId`, `poolId`, `timeEntryId`, `drawdownDate`,
`hoursOrAmount`, `drawdownRate` (the pool's configured retainerRate at
materialization time, NOT the timesheet rate), `drawdownAmount` (computed as
hoursOrAmount × drawdownRate), and `status` (one of pending, materialized,
reversed, adjusted). A drawdown MUST be immutable once materialized; adjustments
MUST create a new record linking back via reversalOfDrawdownId.

#### Scenario: Time entry consumes from retainer pool

- **GIVEN** RetainerPool RETN-2026-01-001 (€3,000, €75/hour) for Gemeente Amsterdam (Jan 2026)
- **WHEN** operator logs TimeEntry(hours=20, date=2026-01-10, poolId=RETN-2026-01-001)
- **THEN** a RetainerDrawdown is created with drawdownAmount=€1,500 (20h × €75/h) and status=materialized

#### Scenario: Reversal creates a new immutable record

- **GIVEN** RetainerDrawdown DRAW-2026-01-001 with status=materialized
- **WHEN** operator reverses the underlying time entry
- **THEN** a new RetainerDrawdown with status=reversed and reversalOfDrawdownId=DRAW-2026-01-001 is created; the original record is unmodified

### Requirement: REQ-RETN-003 The system SHALL expose drawdown-balance aggregation per pool and as-of date

A drawdown-balance query MUST accept (poolId, asOfDate) and return the
available balance as `poolAmount - sum(drawdownAmount where date <= asOfDate
and status='materialized') + sum(prior-period rollover.carryoverAmount where
targetPeriodPoolId = poolId)`. The query MUST return a MonetaryAmount that
MAY be negative to surface overage for visibility.

#### Scenario: Query available balance mid-period

- **GIVEN** RetainerPool RETN-2026-01-001 (€3,000) with drawdowns totaling €1,800 as of 2026-01-20
- **WHEN** operator queries available-balance(RETN-2026-01-001, 2026-01-20)
- **THEN** the system returns €1,200 available

### Requirement: REQ-RETN-004 The system SHALL enforce rollover-cap policy on period close

At period-end a `RetainerRollover` record MUST be created capturing the
unused-balance carryover (or reset) with the fields `rolloverId`,
`sourcePeriodPoolId`, optional `targetPeriodPoolId`, `carryoverAmount`,
optional `carryoverHours`, `carryoverCapApplied`, `resetBalance`,
`administrationId`, and `status`. Rollover MUST be immutable after execution;
adjustments MUST create a new record. The cap enforcement MUST apply per
pool's policy: if `carryoverMaxHours=50` then carryover ≤ 50 hours; if
`carryoverMaxAmount=€2000` then carryover ≤ €2,000; if `resetBalance=true`
then carryover=0.

#### Scenario: End of January enforces carryover cap

- **GIVEN** RetainerPool RETN-2026-01-001 (policy: carryoverMaxHours=50, rate=€75/h) with remaining balance of 60 hours (€4,500)
- **WHEN** the period closes on 2026-01-31
- **THEN** RetainerRollover is created with carryoverHours=50, carryoverAmount=€3,750, carryoverCapApplied=true

#### Scenario: Reset-balance policy zeros carryover

- **GIVEN** RetainerPool RETN-2026-01-002 (policy: resetBalance=true) with remaining balance of €2,500
- **WHEN** the period closes on 2026-01-31
- **THEN** RetainerRollover is created with carryoverAmount=0 and resetBalance=true

### Requirement: REQ-RETN-005 The system SHALL bill overage at the standard rate resolved from rate-card-engine

If actual drawdown exceeds `poolAmount`, the excess MUST be flagged as overage
and billed at the standard rate resolved from `rate-card-engine` (not the
retainer rate). The system MUST compute `overageAmount = max(0, sum(drawdowns)
- poolAmount)`, look up `overageRate` via rate-card-engine for (clientId,
serviceType, periodEndDate), and produce `overageInvoiceAmount = overageAmount
/ retainerRate × overageRate`. Two policies MUST be supported: auto-bill
(default) or flag-for-review.

#### Scenario: Time entries exceed pool; overage billed at standard rate

- **GIVEN** RetainerPool RETN-2026-01-001 (€3,000, €75/h) with actualDrawdown €3,375 and standard rate for Gemeente Amsterdam = €85/h
- **WHEN** the period closes on 2026-01-31
- **THEN** overageAmount=€375 and overageInvoiceAmount=€425 (5 hours × €85)

#### Scenario: No standard rate is resolvable

- **GIVEN** RetainerPool with actualDrawdown > poolAmount and no standard rate is resolvable in rate-card-engine for the (clientId, serviceType)
- **WHEN** the period closes
- **THEN** the operator MUST be shown the error "No applicable standard rate found; overage cannot be billed" and the true-up MUST remain at status=generated until a fallback rate is configured

### Requirement: REQ-RETN-006 The system SHALL auto-trigger period-end true-up

On period close a `RetainerTrueUp` record MUST be auto-created per active pool
with the fields `trueUpId`, `poolId`, `actualDrawdown`, `poolAmount`,
`overageAmount`, optional `overageRate`, `overageInvoiceAmount`,
`administrationId`, `status` (one of generated, pending-approval, approved,
invoiced, settled, reversed), and `generatedAt`. True-up MUST NOT be generated
when poolAmount=0 (free retainer).

#### Scenario: Period-end true-up with overage

- **GIVEN** RetainerPool RETN-2026-01-001 with poolAmount=€3,000 and actualDrawdown=€3,375
- **WHEN** the close transition is triggered on 2026-02-01
- **THEN** RetainerTrueUp is created with status=generated, overageAmount=€375, and awaits approval

### Requirement: REQ-RETN-007 The system SHALL allow manual true-up trigger and adjustment

If period-close automation is delayed or skipped, an authorized user MUST be
able to manually trigger true-up for a given (poolId, periodEndDate). Manual
trigger MUST check whether a true-up already exists (preventing duplicates),
create the RetainerTrueUp record with the same logic as auto-trigger, and
record `manualTriggerReason` plus `generatedBy` in the audit log. Operators
MUST be able to adjust an already-created true-up by creating a new record
with status=reversed (linking via `reversalOfTrueUpId`); the original record
MUST NOT be modified.

#### Scenario: Manual true-up for missed period-close

- **GIVEN** Period 2026-01 ended without auto-trigger; the current date is 2026-02-15
- **WHEN** an authorized operator manually triggers true-up for RETN-2026-01-001 with reason "automation backlog"
- **THEN** RetainerTrueUp is created with status=generated, generatedBy=<operator-uid>, manualTriggerReason="automation backlog"

### Requirement: REQ-RETN-008 The system SHALL generate an adjustment invoice on approved true-up

Once a true-up is approved (status=approved), an adjustment Invoice MUST be
generatable when `overageAmount > 0` or `underUtilisationAmount > 0`. The
generated Invoice MUST carry `invoiceType=adjustment`, a `linkedTrueUpId`
referencing the source RetainerTrueUp, a line item with description and
amount=overageInvoiceAmount, a `dueDate` per the configured offset (default
net 14), and `status=draft`. The RetainerTrueUp.status MUST update to
`invoiced` with the Invoice id stored in `invoiceId`. Organizations that
disable auto-invoice generation MUST be able to keep the true-up at
status=approved for manual invoice creation.

#### Scenario: Auto-generate adjustment invoice for overage true-up

- **GIVEN** RetainerTrueUp (status=approved, overageInvoiceAmount=€425)
- **WHEN** operator triggers invoice generation
- **THEN** Invoice is created with status=draft and dueDate net-14; RetainerTrueUp.status=invoiced; RetainerTrueUp.invoiceId is set

### Requirement: REQ-RETN-009 The system SHALL apply rollover to the next-period pool

The system MUST apply the configured rollover policy to create the next-period
`RetainerPool` after the source true-up reaches status=settled or
status=invoiced. If `resetBalance=true` the next pool MUST start at
`poolAmount` with no carryover. Otherwise the carryover MUST be added to the
next-period pool draft. The next pool MUST be created with `status=draft` and
`sourcePoolId` pointing at the prior pool; operator activation MUST be required
before drawdowns are accepted.

#### Scenario: Rollover to February after January settlement

- **GIVEN** January pool RETN-2026-01-001 settled with carryoverHours=0 (overspent)
- **WHEN** the February rollover is executed
- **THEN** a RetainerPool draft RETN-2026-02-001 is created with poolAmount=€3,000, sourcePoolId=RETN-2026-01-001, status=draft

### Requirement: REQ-RETN-010 The system SHALL expose audit-trail queries for drawdowns, rollovers, and true-ups

All retainer mutations MUST be queryable by period, pool, and entity for
audit and dispute resolution. The capability MUST support these named queries:
`Drawdowns(poolId, dateRange)`, `Rollovers(clientId, dateRange)`,
`TrueUps(poolId, dateRange, status)`, and `PoolBalance(poolId, asOfDate)`.
Results MUST include amounts, rates, period, status, timestamp, and approver
identity.

#### Scenario: Audit-trail query for disputed invoice

- **GIVEN** an Invoice for Gemeente Amsterdam Jan 2026 (overage €425) is disputed
- **WHEN** the finance team queries TrueUps(clientId=Gemeente, dateRange=2026-01)
- **THEN** the response MUST include the true-up, all in-period drawdowns, the rollover, the approver UID, and the invoice id

### Requirement: REQ-RETN-011 The system SHALL gate true-up approvals via role-based permissions

True-up generation (especially with overage) MUST respect the
`authorization-mandate-management` capability (ADR-023). Advancing
`pending-approval → approved` MUST require the `retainer:approve-true-up`
permission; delegation via Mandate + Delegation MUST be honored; the approver
identity and timestamp MUST be recorded in `approvedBy` and `approvalDate`.
Manual true-up trigger MUST require the `retainer:override-period-close`
permission. Batch approval MUST be supported per ADR-015.

#### Scenario: Approver reviews and approves true-up

- **GIVEN** RetainerTrueUp with status=pending-approval and overageAmount=€425
- **WHEN** a finance director with the `retainer:approve-true-up` permission approves the record
- **THEN** RetainerTrueUp.status=approved, approvedBy=<director-uid>, approvalDate=<now>

### Requirement: REQ-RETN-012 The system SHALL register four manifest navigation entries

The retainer-billing-management capability MUST register four manifest entries
under the Billing section: `Retainer Pools` (type: index), `Drawdowns`
(type: index), `Rollovers` (type: index), and `True-Ups` (type: index/detail).
Each entry MUST support sorting, filtering, pagination, and detail-view drilldown.
Pool detail MUST link to its drawdowns, true-ups, and rollovers; true-up detail
MUST link to the adjustment invoice when generated.

#### Scenario: Operator navigates to retainer pools

- **GIVEN** an operator is in the Shillinq app
- **WHEN** the operator clicks "Retainer Pools" in the sidebar
- **THEN** the index page loads with columns (client, period, poolAmount, status) and filters for client, status, and period
