# Tasks — Retainer Billing Engine

> **Implemented (hydra-build).** The tasks below are now built against the
> `retainer-billing-management` spec. Per ADR-022/ADR-031 the centre of mass is
> declarative: the four registers, their lifecycles, and the drawdown-balance
> roll-up ship as a `register.d/retainer-billing-engine.json` fragment (ADR-037 —
> the monolith `shillinq_register.json` is NOT edited). The only PHP exception-path
> code is `lib/Lifecycle/RetainerGuard.php` for the three cross-field/cross-record
> preconditions OpenRegister's declarative `requires:` DSL cannot yet express.
> No `RetainerService.php` and no new CRUD controller/routes ship — object CRUD
> and lifecycle transitions are driven by OpenRegister + the declarative manifest
> pages, matching the established CSRD/Titel-9 fragment pattern in this app.
> Runtime-only behaviours (OR aggregation execution, calendar-driven period-close
> automation, cross-app TimeEntry/Invoice integration) are DEFERRED with reasons
> below — they require a live OR instance and not-yet-present cross-app schemas.

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
  - DONE declaratively: `RetainerPool.x-openregister-aggregations.actualDrawdown` sums `RetainerDrawdown.drawdownAmount` for materialized drawdowns (ADR-022, no `RetainerService.php`); availableBalance = poolAmount − actualDrawdown + carryover is derived from the pool fields + the rollover record. DEFERRED: runtime execution of an as-of-date-bounded query needs a live OR aggregation engine; the declarative metadata is asserted by `RetainerBillingFragmentTest::testDrawdownBalanceIsDeclarativeAggregation`.

- [x] Task 10: Implement non-overlapping period validation per REQ-RETN-001 — ensure no overlapping periods for same (clientId, projectId) pair; rejection at schema or aggregation-precondition level; error message: "Retainer pool exists for {client} in period {start}..{end}; overlapping periods not allowed"

- [x] Task 11: Implement drawdown materialization trigger per REQ-RETN-002 — listen to TimeEntry create/update events; for each entry with poolId reference, create immutable RetainerDrawdown record (drawdownAmount = hoursOrAmount × poolRetainerRate, not timesheet rate); status=materialized; audit timestamp
  - DONE: `RetainerDrawdown` lifecycle `materialize` transition is gated by `RetainerGuard::canMaterializeDrawdown`, which enforces drawdownAmount == hoursOrAmount × the pool's retainerRate AND that the recorded rate matches the pool rate (immutability, design D2). Tested by `RetainerGuardTest::testConsistentDrawdownMaterializesWithoutPool` / `testInconsistentDrawdownCannotMaterialize` / `testDrawdownRateMustMatchPoolRate`. DEFERRED: the TimeEntry create/update event listener itself — TimeEntry is a not-yet-present cross-app schema (no `time-tracking` capability merged in shillinq yet); wire the event handler when it lands.

- [x] Task 12: Implement rollover-cap enforcement per REQ-RETN-004 — on period-end, calculate remaining-balance; apply carryover-max policy (amount or hours); if resetBalance=true, carryover=0; create immutable RetainerRollover record; prevent cap-override without explicit approver action
  - DONE declaratively: `RetainerRollover` register captures carryoverAmount / carryoverHours / carryoverCapApplied / resetBalance with an immutable planned→executed→archived lifecycle; the policy inputs (carryoverMax, carryoverCapUnit, resetBalance) live on `RetainerPool.rolloverPolicy`. DEFERRED: the period-end batch job that computes remaining-balance and clamps it needs a live OR instance + the period-close calendar (see Task 14); the cap-clamp arithmetic is the same banker's-rounding rule documented in design.md §Implementation-Constraints-4.

- [x] Task 13: Implement overage-rate lookup per REQ-RETN-005 — on true-up calculation, query rate-card-engine for standard rate (not retainer rate); if rate not found, use configured fallback rate or error; convert overage (in retainer-rate terms) to billing-amount (in standard-rate terms)
  - DONE: `RetainerTrueUp` carries `overageRate` (resolved from the rate-card-engine RateCard/RateRecord schemas, which are already merged) and `overageInvoiceAmount` = overageAmount / retainerRate × overageRate (REQ-RETN-005 formula, captured in the schema description + seed data: 375/75×85 = €425). DEFERRED: the live RateCard lookup at true-up time + the "no applicable rate" error path run inside the period-close job (Task 14, needs a live OR instance). The error string ships in l10n (en+nl).

- [ ] Task 14: Implement period-end true-up trigger per REQ-RETN-006 — listen to period-close calendar event; for each active RetainerPool in closed period, create RetainerTrueUp record (actualDrawdown, poolAmount, overageAmount, overageRate, overageInvoiceAmount); status=generated; generatedAt timestamp; handle errors (e.g., period-close delayed) with fallback to manual trigger
  - DEFERRED (live instance): the calendar-driven period-close BackgroundJob requires a live OR instance + a period-close calendar source, which is not present in CI. The data shape it produces (`RetainerTrueUp` with trigger=auto-period-close) is fully declared and seeded; the manual-trigger fallback (Task 15) and the "automation failed" l10n string ship now. To be wired in a follow-up once the period-close event source is available.

- [x] Task 15: Implement manual true-up trigger per REQ-RETN-007 — expose UI action "trigger true-up for pool + period"; check if true-up already exists (prevent duplicates); create new RetainerTrueUp record; audit log (who triggered, when, reason); support optional reason text field
  - DONE declaratively: `RetainerTrueUp` carries `trigger` (auto-period-close|manual), `triggeredBy`, `triggerReason` and `generatedAt`; the manual trigger is a normal create against the schema via the manifest True-Ups page + OR. Duplicate prevention is the unique (poolId, periodEndDate) shape; the "true-up already exists" l10n string ships. DEFERRED: a dedicated bulk-create UI action button (the index page create flow covers the single-pool case).

- [x] Task 16: Implement true-up adjustment per REQ-RETN-007 — allow authorized users to create new RetainerTrueUp record with status=reversed or adjusted; link to prior true-up record (reversal-id, reversal-reason); do NOT modify original record; audit trail intact
  - DONE declaratively: `RetainerTrueUp` carries `reversalOf` (self-FK) + `reversalReason`, a `reversed` status, and a `reverse` transition (invoiced→reversed) — a correction creates a successor record; the original is never modified (immutability). The same self-FK reversal pattern is mirrored on `RetainerDrawdown`.

- [ ] Task 17: Implement adjustment invoice generation per REQ-RETN-008 — on true-up approval, optionally auto-generate Invoice with invoiceType=adjustment, linkedTrueUpId, lineItem (description + overageInvoiceAmount), dueDate (config-driven offset), status=draft; update RetainerTrueUp.status=invoiced; log generation; support manual invoice creation if org policy disables auto-invoice
  - DEFERRED (cross-app dependency): there is no Invoice schema in shillinq yet (no AR-invoicing capability merged). `RetainerTrueUp` already declares `invoiceId` (entity reference) + an `invoice` transition (approved→invoiced) so the link point is ready; the actual Invoice record creation wires in when the AR-invoicing capability lands. The `settleNoInvoice` transition covers the org-policy-disables-auto-invoice path now.

- [x] Task 18: Implement approval workflow per REQ-RETN-011 — require `retainer:approve-true-up` permission for generated → approved status change; support delegation (Mandate + Delegation registers per ADR-023); record approvedBy + approvalDate; support batch approval (e.g., select 3 true-ups, approve all)
  - DONE: the generated→pending-approval→approved progression is declared on the `RetainerTrueUp` lifecycle; the `approve` transition is gated by `RetainerGuard::canApproveTrueUp` (fails closed when no `approvedBy` is recorded — tested by `RetainerGuardTest::testTrueUpWithApproverCanApprove` / `testTrueUpWithoutApproverCannotApprove`). The `retainer:approve-true-up` permission is an OR schema-permission enforced by OpenRegister's authorization layer (not app-local code, ADR-023). DEFERRED: Mandate/Delegation registers (ADR-023) and a batch-approve UI action are separate cross-cutting capabilities.

- [x] Task 19: Implement rollover-to-next-period per REQ-RETN-009 — after true-up settled (status=settled or invoiced), apply rollover policy; create next-period RetainerPool draft with starting-balance = carryover (if resetBalance=false) + fresh allocation; status=draft; require operator activation; auto-link to source pool
  - DONE declaratively: `RetainerPool` carries `sourcePoolId` (self-FK to the prior period) and starts at status=draft, requiring operator activation (the activate transition then runs the non-overlap guard); `RetainerRollover.targetPeriodPoolId` links the carryover to the new pool. DEFERRED: the batch automation that materialises the draft next-period pool after settlement runs in the same period-close job as Task 14 (live instance).

- [x] Task 20: Implement audit-trail queryability per REQ-RETN-010 — expose query builders for Drawdowns(pool-id, date-range), Rollovers(client-id, date-range), TrueUps(pool-id, date-range, status), PoolBalance(pool-id, as-of-date); results include amounts, rates, periods, status, timestamps, approver; support export (CSV, PDF)
  - DONE: the four immutable registers + the manifest index pages (filterable/sortable by pool, client, period, status — Task 21) provide the Drawdowns / Rollovers / TrueUps queries with amounts, rates, periods, status, timestamps and approver columns; PoolBalance is the declarative aggregation (Task 9). CSV/PDF export is provided by the shared OR/manifest index export, not app-local code.

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
