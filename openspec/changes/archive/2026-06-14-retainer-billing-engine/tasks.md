# Tasks — Retainer Billing Engine

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `retainer-billing-management` spec
> — they are recorded now so the spec-review gate, dependency planning,
> and tier-cascade impact are all visible at proposal time. No source
> files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm rate-card-engine is merged and available (dependency); confirm no `retainer-billing-management` capability spec already exists, no `RetainerPool`/`RetainerDrawdown`/`RetainerRollover`/`RetainerTrueUp` schemas are declared, and no `lib/Service/Retainer*` / `lib/Db/Retainer*` PHP classes are present (per ADR-031 anti-pattern enumeration); explicitly note this capability "enables monthly retainer pools with drawdown tracking, rollover, and period-end true-up reconciliation"

- [x] Task 2: Author `specs/retainer-billing-management/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (billing + operations)` / `Depends on: rate-card-engine` header, `REQ-RETN-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline

- [x] Task 3: Author `proposal.md` referencing rate-card-engine dependency, shared `nextcloud-app` spec, and including Affected Projects / Scope / Risks (drawdown calculation stability, period-close automation timing, rollover policy enforcement) / Rollback / Open Questions

- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (per-client/project pools with effective-period), D2 (drawdown = time-entry consumption, not rates), D3 (rollover policy = pool-level config), D4 (drawdown = aggregation query), D5 (true-up = period-end, automatic, materialized), D6 (rollover immutability after period close)

- [x] Task 5: Declare the `RetainerPool` schema in `lib/Settings/shillinq_register.json` with all REQ-RETN-001 fields (poolId, clientId, projectId, periodStart, periodEnd, poolAmount, currency, retainerRate, rolloverPolicy, administrationId, status, createdAt, updatedAt) — with non-overlapping period validation at schema or precondition level

- [x] Task 6: Declare the `RetainerDrawdown` schema in `lib/Settings/shillinq_register.json` with all REQ-RETN-002 fields (drawdownId, poolId, timeEntryId, drawdownDate, hoursOrAmount, drawdownRate, drawdownAmount, status, administrationId, createdAt) — immutable register with status=pending|materialized|reversed|adjusted

- [x] Task 7: Declare the `RetainerRollover` schema in `lib/Settings/shillinq_register.json` with all REQ-RETN-004 fields (rolloverId, sourcePeriodPoolId, targetPeriodPoolId, carryoverAmount, carryoverHours, carryoverCapApplied, resetBalance, status, administrationId, createdAt) — immutable register

- [x] Task 8: Declare the `RetainerTrueUp` schema in `lib/Settings/shillinq_register.json` with all REQ-RETN-006 fields (trueUpId, poolId, actualDrawdown, poolAmount, overageAmount, overageRate, overageInvoiceAmount, status, generatedAt, approvedBy, approvalDate, invoiceId, administrationId) — status enum: generated|pending-approval|approved|invoiced|settled|reversed

- [x] Task 9: Implement drawdown-balance aggregation per REQ-RETN-003 — accept (poolId, asOfDate); filter drawdowns by date ≤ asOfDate; sum drawdownAmount; account for rollover carryover from prior period; return available-balance (poolAmount - sum + carryover); return negative balance for overage visibility — NOT a PHP service, pure aggregation query or documented `RetainerDrawdownGuard` per ADR-031 exception

- [x] Task 10: Implement non-overlapping period validation per REQ-RETN-001 — ensure no overlapping periods for same (clientId, projectId) pair; rejection at schema or aggregation-precondition level; error message: "Retainer pool exists for {client} in period {start}..{end}; overlapping periods not allowed"

- [x] Task 11: Implement drawdown materialization trigger per REQ-RETN-002 — listen to TimeEntry create/update events; for each entry with poolId reference, create immutable RetainerDrawdown record (drawdownAmount = hoursOrAmount × poolRetainerRate, not timesheet rate); status=materialized; audit timestamp

- [x] Task 12: Implement rollover-cap enforcement per REQ-RETN-004 — on period-end, calculate remaining-balance; apply carryover-max policy (amount or hours); if resetBalance=true, carryover=0; create immutable RetainerRollover record; prevent cap-override without explicit approver action

- [x] Task 13: Implement overage-rate lookup per REQ-RETN-005 — on true-up calculation, query rate-card-engine for standard rate (not retainer rate); if rate not found, use configured fallback rate or error; convert overage (in retainer-rate terms) to billing-amount (in standard-rate terms)

- [x] Task 14: Implement period-end true-up trigger per REQ-RETN-006 — listen to period-close calendar event; for each active RetainerPool in closed period, create RetainerTrueUp record (actualDrawdown, poolAmount, overageAmount, overageRate, overageInvoiceAmount); status=generated; generatedAt timestamp; handle errors (e.g., period-close delayed) with fallback to manual trigger

- [x] Task 15: Implement manual true-up trigger per REQ-RETN-007 — expose UI action "trigger true-up for pool + period"; check if true-up already exists (prevent duplicates); create new RetainerTrueUp record; audit log (who triggered, when, reason); support optional reason text field

- [x] Task 16: Implement true-up adjustment per REQ-RETN-007 — allow authorized users to create new RetainerTrueUp record with status=reversed or adjusted; link to prior true-up record (reversal-id, reversal-reason); do NOT modify original record; audit trail intact

- [x] Task 17: Implement adjustment invoice generation per REQ-RETN-008 — on true-up approval, optionally auto-generate Invoice with invoiceType=adjustment, linkedTrueUpId, lineItem (description + overageInvoiceAmount), dueDate (config-driven offset), status=draft; update RetainerTrueUp.status=invoiced; log generation; support manual invoice creation if org policy disables auto-invoice

- [x] Task 18: Implement approval workflow per REQ-RETN-011 — require `retainer:approve-true-up` permission for generated → approved status change; support delegation (Mandate + Delegation registers per ADR-023); record approvedBy + approvalDate; support batch approval (e.g., select 3 true-ups, approve all)

- [x] Task 19: Implement rollover-to-next-period per REQ-RETN-009 — after true-up settled (status=settled or invoiced), apply rollover policy; create next-period RetainerPool draft with starting-balance = carryover (if resetBalance=false) + fresh allocation; status=draft; require operator activation; auto-link to source pool

- [x] Task 20: Implement audit-trail queryability per REQ-RETN-010 — expose query builders for Drawdowns(pool-id, date-range), Rollovers(client-id, date-range), TrueUps(pool-id, date-range, status), PoolBalance(pool-id, as-of-date); results include amounts, rates, periods, status, timestamps, approver; support export (CSV, PDF)

- [x] Task 21: Add 4 manifest navigation entries (Retainer Pools, Drawdowns, Rollovers, True-Ups) + their `type: index` / `type: detail` pages to `src/manifest.json` per REQ-RETN-012; `node tests/validate-manifest.json` exits 0; detail pages link to related records (pool → drawdowns, drawdowns → true-up, true-up → invoice)

- [x] Task 22: Update `openspec/architecture/adr-000-data-model.md` with `RetainerPool`, `RetainerDrawdown`, `RetainerRollover`, `RetainerTrueUp` entries and their relations (TimeEntry → RetainerDrawdown, Invoice → RetainerTrueUp, RetainerPool → Organization); note dependency on rate-card-engine for overage-rate lookup

## Verification

`openspec validate` must exit clean on the change folder. Accountant-persona
peer review (e.g. `/test-persona-janwillem` for SMB) confirms the retainer
pool setup flow (pool definition → drawdown tracking → rollover enforcement →
period-end true-up → adjustment invoice) matches Dutch SMB practice for
retainer billing. Architecture reviewer confirms ADR-022 + ADR-031
compliance (no app-local drawdown service; aggregation query or documented
PHP-guard fallback; manifest carries navigation). Dependency validation
confirms rate-card-engine is available for overage-rate lookup.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle
(separate `opsx-apply`) is responsible for:

- **PHPUnit unit tests:**
  - Drawdown-balance aggregation: sum of drawdowns ≤ as-of-date + carryover
  - Rollover-cap enforcement: carryover-max honored, reset-balance zero'd out
  - Non-overlapping period validation: overlapping pools rejected
  - Overage-rate lookup: rate resolved from rate-card-engine
  - True-up calculation: actual-drawdown vs. pool-amount, overage-amount, overage-rate conversion
  - Manual true-up trigger: duplicate prevention, audit-log creation
  - Adjustment-invoice generation: correct amount, linked to true-up, status=draft
  - Rollover-to-next-period: carryover amount applied to new pool

- **Playwright MCP browser tests:**
  - Retainer Pools index: list all pools, filter by status/client/period (pre-declared on Task 21)
  - Retainer Pools detail: view pool, drawdowns, rollovers, true-ups; manual true-up trigger action
  - Drawdowns index: list all drawdowns, filter by pool/period/status; view drawdown detail with rate breakdown
  - Rollovers index: list all rollovers, inspect carryover-cap applied
  - True-Ups index: list all true-ups; detail view with calculation breakdown (actual vs. pool, overage, rate, invoice link)
  - True-Up approval flow: generated → approve → invoiced; audit trail visible
  - Adjustment invoice: view linked true-up, settlement date, overage details

- **Integration test:**
  - End-to-end: create pool → log time entries → trigger drawdown → query balance → period-close → true-up generated → approve → invoice created → rollover-to-next-period
  - Overage scenario: pool €3,000, drawdown €3,500, overage €500 converted to standard rate, adjustment invoice

- **CI exit code:** `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle
authors:

- `docs/user-guide/billing/retainer-pools.md` per ADR-030 journeydoc
  convention
- Screenshots: retainer pool creation, drawdown tracking, balance query,
  true-up approval, adjustment invoice, rollover-to-next-period
- Operator flow: "How to set up a monthly retainer pool for a client",
  "How to track drawdowns from time entries", "How to configure rollover
  policy (carryover cap vs. reset)", "How to review and approve period-end
  true-up", "How to dispute a historical retainer allocation"

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation
cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:

- UI labels: `Retainer Pools`, `Retainer Pool`, `Drawdowns`, `Drawdown`,
  `Rollovers`, `Rollover`, `True-Ups`, `True-Up`, `Period`, `Pool Amount`,
  `Retainer Rate`, `Rollover Policy`, `Carryover Cap`, `Reset Balance`,
  `Actual Drawdown`, `Overage Amount`, `Overage Rate`, `Adjustment Invoice`,
  `Approved By`, `Approval Date`
- Statuses: `Active`, `Inactive`, `Archived`, `Draft`, `Generated`,
  `Pending Approval`, `Approved`, `Invoiced`, `Settled`, `Reversed`,
  `Materialized`
- Policy types: `Carryover Cap (Amount)`, `Carryover Cap (Hours)`, `Reset Monthly`
- Units: `Hourly`, `Fixed Amount`
- Error messages: "Overlapping retainer pool exists for this client in period {start}..{end}",
  "Period-close automation failed; trigger manually via action menu",
  "No applicable standard rate found; overage cannot be billed",
  "True-up already exists for this pool; create reversal if adjustment needed"
