---
status: done
---

# Spec: Retainer Billing Management

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T2 (billing + operations)  
**Depends on:** rate-card-engine

## Purpose

The retainer-billing-management capability enables monthly retainer pools
per client/project with automatic drawdown tracking from time entries,
rollover of unused balances, and period-end true-up reconciliation.

Per ADR-022 (declarative aggregations), drawdown queries are aggregation-based,
not PHP services. Per ADR-031 (schema-driven logic), retainer state mutations
(drawdown materialization, true-up generation) are lifecycle actions, not
bespoke code.

## Requirements

@e2e exclude unbuilt UI: retainer billing management pages not yet implemented


### REQ-RETN-001: Pool Lifecycle and Effective Dates

A `RetainerPool` MUST define a monthly retainer allocation with effective-date
windows. A pool MUST specify:
- `poolId` (unique identifier)
- `clientId` / `projectId` (entity reference)
- `period` (start-date and end-date)
- `poolAmount` (currency and amount in base unit, e.g., cents)
- `currency` (ISO 4217 code, default EUR)
- `retainerRate` (hourly or daily rate for drawdown calculation)
- `rolloverPolicy` (carryover-max, reset-balance, carryover-cap-unit)
- `administrationId` (OU isolation)
- `status` (active, inactive, archived)

Pools with non-overlapping `period` windows allow historical retainer
tracking. Overlapping periods for the same (client, project) pair MUST be
rejected at validation time.

#### Scenario: Create a monthly retainer pool for a client

GIVEN: Gemeente Amsterdam has a 2026-01 consulting engagement  
WHEN: operator creates RetainerPool(period="2026-01-01..2026-01-31", poolAmount=3000 EUR, retainerRate=75 EUR/hour)  
THEN: pool is created with status=active; no overlapping pool exists for this client in 2026-01  

### REQ-RETN-002: Drawdown Materialization on Time Entry

Each `TimeEntry` booked against a retainer pool MUST trigger a `RetainerDrawdown`
record. A drawdown MUST specify:
- `drawdownId` (unique identifier)
- `poolId` (reference to RetainerPool)
- `timeEntryId` (reference to the consuming time entry)
- `drawdownDate` (date of the time entry)
- `hoursOrAmount` (hours or amount unit from the time entry)
- `drawdownRate` (the pool's configured retainerRate, not the timesheet rate)
- `drawdownAmount` (calculated as hoursOrAmount × drawdownRate)
- `status` (pending, materialized, reversed, adjusted)

A drawdown MUST be immutable once materialized; adjustments MUST create a
new record (not overwrite).

#### Scenario: Time entry consumes from retainer pool

GIVEN: RetainerPool RETN-2026-01-001 (€3,000, €75/hour) for Gemeente Amsterdam (Jan 2026)  
WHEN: operator logs TimeEntry(hours=20, date=2026-01-10, clientId=Amsterdam)  
THEN: RetainerDrawdown is created with drawdownAmount=€1,500 (20h × €75/h); pool balance becomes €1,500  

### REQ-RETN-003: Drawdown-Balance Aggregation

A drawdown-balance query MUST return the available balance for a given pool
as of a given date:

```
available-balance = poolAmount - sum(drawdownAmount for all drawdowns <= date)
                    + sum(rollover-carryover for prior-period rollovers)
```

The query MUST:
- Accept (poolId, as-of-date) as input
- Filter drawdowns by date ≤ as-of-date
- Sum drawdown amounts
- Account for prior-period carryover (if any)
- Return available-balance as a MonetaryAmount
- Return 0 if all pool consumed; allow negative balance (overage) visibility

#### Scenario: Query available balance mid-period

GIVEN: RetainerPool RETN-2026-01-001 (€3,000) with drawdowns totaling €1,800 as of 2026-01-20  
WHEN: operator queries available-balance(RETN-2026-01-001, 2026-01-20)  
THEN: system returns €1,200 available  

### REQ-RETN-004: Rollover Policy Enforcement

At period-end, a `RetainerRollover` record MUST be created that captures
the unused-balance carryover (or reset). A rollover MUST specify:
- `rolloverId` (unique identifier)
- `sourcePeriodPoolId` (prior-period pool)
- `targetPeriodPoolId` (next-period pool)
- `carryoverAmount` (amount carried forward)
- `carryoverHours` (hours equivalent, if rate-convertible)
- `carryoverCapApplied` (cap amount from policy)
- `resetBalance` (boolean: true = no carryover)
- `status` (planned, executed, adjusted, archived)

Rollover MUST be immutable after execution; adjustments MUST create a new
record, not overwrite the original.

Rollover-cap enforcement MUST apply per pool's policy:
- If `carryover-max = 50 hours`: carryover ≤ 50 hours
- If `carryover-max = €2,000`: carryover ≤ €2,000 (at pool's rate)
- If `reset-balance = true`: carryover = 0 (no carryover to next month)

#### Scenario: End of January: enforce carryover cap

GIVEN: RetainerPool RETN-2026-01-001 (policy: carryover-max=50 hours, rate=€75/h) with remaining balance of 60 hours (€4,500)  
WHEN: period closes on 2026-01-31  
THEN: RetainerRollover is created with carryoverHours=50, carryoverCapApplied=true; carryover-to-Feb = 50h (€3,750)  

### REQ-RETN-005: Overage Detection and Rate Lookup

If actual drawdown exceeds pool-amount, the excess MUST be flagged as
"overage" and billed at the standard rate resolved from rate-card-engine
(not the retainer rate).

Overage calculation MUST be:
```
overage-amount = max(0, sum(drawdowns) - poolAmount)
overage-rate = rate-card-engine.lookup(clientId, serviceType, period-end-date)
overage-billing-amount = overage-amount / retainerRate × overage-rate
```

The system MUST support two overage policies:
1. **Auto-bill**: overage is auto-billed at period-end (default)
2. **Manual review**: overage is flagged for approver review before billing

#### Scenario: Time entries exceed pool; overage billed at standard rate

GIVEN: RetainerPool RETN-2026-01-001 (€3,000, €75/hour) with actual drawdown €3,375 (45 hours)  
WHEN: period closes on 2026-01-31; standard rate for Gemeente = €85/hour  
THEN: overage-amount = (3375 - 3000) / 75 × 85 = 5 hours × €85 = €425; flagged for billing  

### REQ-RETN-006: Period-End True-Up Trigger and Settlement

On period close (calendar-driven), a `RetainerTrueUp` record MUST be
auto-created for each active pool. True-up MUST:
- Accept (poolId, periodEndDate) as input
- Calculate actual drawdown vs. pool-amount
- Detect overage (if drawdown > pool)
- Resolve overage-rate from rate-card-engine
- Create immutable RetainerTrueUp record with:
  - `trueUpId` (unique identifier)
  - `poolId` (reference to pool)
  - `actualDrawdown` (sum of all drawdowns in period)
  - `poolAmount` (configured pool amount)
  - `overageAmount` (max(0, actualDrawdown - poolAmount))
  - `overageRate` (resolved from rate-card-engine)
  - `overageInvoiceAmount` (overage-amount converted to billing terms)
  - `status` (generated, pending-approval, approved, invoiced, settled, reversed)
  - `generatedAt` (timestamp)
  - `approvedBy` (optional; person who approved true-up)
  - `approvalDate` (optional)
  - `invoiceId` (optional; reference to generated adjustment invoice)

True-up MUST NOT be generated if pool-amount = 0 (free retainer).

#### Scenario: Period-end true-up with overage

GIVEN: RetainerPool RETN-2026-01-001 period ending 2026-01-31 with poolAmount=€3,000 and actualDrawdown=€3,375  
WHEN: period close is triggered on 2026-02-01  
THEN: RetainerTrueUp is created with status=generated; overageAmount=€375; awaiting approval before invoice  

### REQ-RETN-007: Manual True-Up Trigger and Override

If period-close automation is delayed or skipped, an authorized user MUST be
able to manually trigger true-up for a given pool + period-date. Manual
trigger MUST:
- Accept (poolId, periodEndDate) as input
- Check if true-up already exists (prevent duplicates)
- Create RetainerTrueUp record with same logic as auto-trigger
- Audit log the manual trigger (who, when, reason)

Operators MUST be able to adjust already-created true-up records (e.g., if
an invoice was issued in error):
- Create a new `RetainerTrueUp` record with status=reversed or adjusted
- Link to the prior true-up record for audit trail
- Do not modify the original record

#### Scenario: Manual true-up for missed period-close

GIVEN: Period 2026-01 ended without auto-trigger; now 2026-02-15  
WHEN: operator manually triggers true-up for RETN-2026-01-001  
THEN: RetainerTrueUp is created; audit log records "manual trigger by Alice on 2026-02-15"  

### REQ-RETN-008: Adjustment Invoice Generation

The system SHALL satisfy this requirement: Adjustment Invoice Generation.

Once true-up is approved (status=approved), an optional adjustment invoice
MUST be generated if overage > 0 or credit < 0 (under-utilization).

Invoice generation MUST:
- Create a new `Invoice` record with:
  - `invoiceType = adjustment` (or retainer-true-up)
  - `linkedTrueUpId` (reference to RetainerTrueUp)
  - `lineItem`: description="{pool description} true-up {month}", amount=overageInvoiceAmount
  - `dueDate`: config-driven offset from invoice-date (default: net 14)
  - `status = draft` (pending signature/approval)
- Update RetainerTrueUp.status to `invoiced`
- Audit log the invoice generation

If organization policy is `no auto-invoice`, true-up remains at status=approved
and invoice must be manually created (future workflow).

#### Scenario: Auto-generate adjustment invoice for overage true-up

GIVEN: RetainerTrueUp (status=approved, overageInvoiceAmount=€425)  
WHEN: operator approves invoice generation  
THEN: Invoice is created (draft, due 2026-02-14); RetainerTrueUp.status = invoiced; RetainerTrueUp.invoiceId = INV-2026-02-001  

### REQ-RETN-009: Rollover to Next Period

After true-up is settled (status=settled or invoiced), the rollover policy MUST be
applied to create the next-period `RetainerPool`:

- If `resetBalance = true`: next pool = fresh pool-amount (no carryover)
- If `resetBalance = false` and carryover-cap = X: next pool = carryover-cap (max)
- Carryover is expressed in pool's currency and rate; carries forward as a
  credit to the next period's pool-amount.

The next-period pool MUST be auto-created (or template-created for approval)
with status=draft; operator approval required before activation.

#### Scenario: Rollover to February after January settlement

GIVEN: January pool RETN-2026-01-001 settled with carryover=50 hours (€3,750, cap=€3,750)  
WHEN: February pool template is created based on rollover  
THEN: February pool draft is created with starting balance = €3,750 (carried over) + €3,000 (fresh allocation) = €6,750; awaiting activation  

### REQ-RETN-010: Audit Trail and Historical Queryability

All retainer mutations (drawdowns, rollovers, true-ups) MUST be queryable by
period, pool, and entity for audit and dispute resolution.

The system MUST support queries:
- `Drawdowns(pool-id, date-range)`: list all drawdowns in period
- `Rollovers(client-id, date-range)`: list all rollovers (multi-month)
- `TrueUps(pool-id, date-range, status)`: list all true-ups filtered by status
- `PoolBalance(pool-id, as-of-date)`: available balance as of date

Results MUST include:
- Entity IDs and descriptions
- Amount, rate, effective period
- Status and timestamp
- Approver/operator audit trail (who approved, when)

#### Scenario: Audit trail query for disputed invoice

GIVEN: Invoice for Gemeente Amsterdam Jan 2026 (overage €425) is disputed  
WHEN: finance team queries TrueUps(client=Gemeente, period=2026-01)  
THEN: system returns all drawdowns, rollover carryover, true-up calculation, approver, and invoice-date for audit trail  

### REQ-RETN-011: Role-Based Approvals

True-up generation (especially with overage) MUST respect approval workflows
per ADR-023 (authorization-mandate-management).

Approvals MUST:
- Require `retainer:approve-true-up` permission for status change: generated → approved
- Allow delegation during out-of-office (per Mandate + Delegation registers)
- Record approver identity and timestamp in RetainerTrueUp
- Support batch approval (e.g., all true-ups for a client) per ADR-015

Manual true-up trigger MUST require `retainer:override-period-close` permission.

#### Scenario: Approver reviews and approves true-up

GIVEN: RetainerTrueUp (status=generated, overageAmount=€425)  
WHEN: finance director (role: approver, permission: retainer:approve-true-up) approves  
THEN: RetainerTrueUp.status = approved; RetainerTrueUp.approvedBy = director-id; RetainerTrueUp.approvalDate = 2026-02-01T14:30:00Z  

### REQ-RETN-012: Manifest Navigation

The retainer-billing-management capability MUST register 4 manifest entries:
1. **Retainer Pools** (type: index) — list all pools, filter by status/client/period
2. **Drawdowns** (type: index) — list all drawdowns, filter by pool/period/status
3. **Rollovers** (type: index) — list all rollovers, filter by client/period
4. **True-Ups** (type: index/detail) — list all true-ups, detail view with adjustment-invoice link

Each entry MUST:
- Appear in app sidebar under "Billing" section (per T1 manifest pattern)
- Support sorting, filtering, and pagination
- Link to detail views for inspection
- Support bulk actions (export, filter, download PDF) per ADR-015

#### Scenario: Operator navigates to retainer pools

GIVEN: operator is in Shillinq app  
WHEN: operator clicks "Retainer Pools" in sidebar → Billing section  
THEN: index page loads; lists all pools with columns: client, period, poolAmount, status, carryoverPolicy; filterable by client, status, period  

## Data Model References

The following entities are declared in `lib/Settings/shillinq_register.json`:

- **RetainerPool**: per ADR-000 data-model (new entity for retainer-billing-management)
- **RetainerDrawdown**: per ADR-000 (new entity)
- **RetainerRollover**: per ADR-000 (new entity)
- **RetainerTrueUp**: per ADR-000 (new entity)

These entities participate in:
- `TimeEntry → RetainerDrawdown` (one-to-many, time entry consumes pool)
- `Invoice → RetainerTrueUp` (one-to-one, adjustment invoice from true-up)
- `RetainerPool → Organization` (many-to-one, per-OU isolation)
- `RetainerTrueUp → Person` (many-to-one, approver)

## Conformance

This spec conforms to:
- **ADR-022** (declarative aggregations): drawdown-balance queries are
  aggregation-based, not PHP service.
- **ADR-031** (schema-driven business logic): drawdown materialization and
  true-up generation are lifecycle actions, not custom code.
- **ADR-023** (authorization-mandate-management): true-up approvals require
  explicit role-based permissions.
- **ADR-030** (journeydoc pattern): operator onboarding via guided setup
  workflow for retainer pools and rollover policies.

## Test Scenarios (Company ADR-009)

- Unit: drawdown-balance aggregation (sum + carryover + overflow)
- Unit: rollover-cap enforcement (amount vs. hours, reset vs. carryover)
- Unit: overage-rate lookup from rate-card-engine
- Unit: period-overlap detection (pools for same entity)
- Integration: time-entry → drawdown → balance update → true-up → invoice
- Browser: retainer-pools index, detail, create/edit; drawdowns list; true-ups approval flow
- CI: `composer test` green; spec-only validates with `openspec validate`
