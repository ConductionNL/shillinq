---
kind: code
depends_on: []
---

# Proposal: bookkeeping-aansluitingen

## Summary

Build the `Aansluiting` (tie-out) framework: a declarative definition of one
reconciliation ("source A", "source B", the expected relationship between
them, a tolerance) plus a per-period computed instance (`AansluitingResult`)
that carries the resolved totals, the signed difference, a bucket-level
drill-down, and an `open -> explained -> resolved` lifecycle with an
audit-trailed explanation. This change ships the framework plus its two
highest-value instances:

1. **BTW-ledger -> aangifte** — ties the live BTW-grootboek recompute against
   the as-filed aangifte (reuses `VATReturnService`'s existing GL-derivation
   engine — the same diff `btw-suppletie-detection`'s
   `VatSuppletieDetectionService` already computes for its own correction
   workflow).
2. **Subledger -> GL control account** — ties the AR/AP subledger's open-item
   total against its GL control-account balance (Debiteuren 1300, Crediteuren
   1600). `PeriodCloseAssistantService::detectOpenSubLedger()` only counts
   draft/unposted `GLTransaction`s; it never compares a control-account
   balance to a subledger total. This is that missing comparison.

## Motivation

The 2026-07 market/gap sweep found **six separate reconciliation gaps** in
the bookkeeping domain, all requiring the exact same capability — tie
artefact A to artefact B, flag the difference, drill down, and record a
per-period status:

1. BTW-ledger -> aangifte reconciliation (the #1 pain point in the
   tax-compliance journey research).
2. Subledger -> GL control-account tie-out (AR/AP).
3. Year-end balance reconciliation pack (every balance-sheet account tied to
   its supporting schedule).
4. ICP <-> BTW rubriek 3b tie-out.
5. Bank-balance tie-out (statement closing balance vs. GL bank account).
6. XAF/auditfile completeness check.

Building six separate PHP report classes for the same underlying shape would
duplicate the tie-out/tolerance/drill-down/explain/resolve/audit logic six
times. Per ADR-031 and the precedent set by `bookkeeping-trial-balance`
(REQ-TB-001), this is a case for ONE declarative-first framework rather than
six bespoke report builders. This change builds that framework and proves it
against the two highest-value instances; the remaining four are named
follow-up work (see Scope below and the filed follow-up issue), each of which
either implements a new resolver on top of this same framework
(year-end pack, ICP<->rubriek 3b, XAF completeness) or extends the existing
`bookkeeping-reconciliation-reports` bank-reconciliation capability rather
than duplicating its `BankReconciliation`/`ReconciliationMatch` schemas
(bank-balance tie-out).

## Affected Projects

- [x] Project: `shillinq` — new `Aansluiting` + `AansluitingResult` registers,
  a new `AansluitingService` (imperative, justified below) implementing the
  two resolvers, a new `AansluitingResolutionGuard` lifecycle guard, a new
  `AansluitingController` compute/explain/resolve/reopen API, and manifest
  navigation (index + detail pages for both schemas).

## Scope

### In Scope

- The `Aansluiting` definition schema: name, `aansluitingType`, source A /
  source B labels, `expectedRelationship` (equal / equal-with-sign-flip),
  `toleranceCents`.
- The `AansluitingResult` schema: per-period computed totals, signed
  difference, `withinTolerance`, bucket-level `lineDeltas` drill-down, and the
  `open -> explained -> resolved` lifecycle (with `reopen`).
- `AansluitingService::compute()` — dispatches on `aansluitingType` to one of
  the two resolvers below, computes the tolerance decision, and persists (or
  idempotently skips, if already explained/resolved) an `AansluitingResult`.
- `AansluitingService::explain() / resolve() / reopen()` — the operator
  workflow, gated by `AansluitingResolutionGuard` (ADR-031 exception).
- **Resolver 1 — btw-ledger-aangifte**: reuses
  `VATReturnService::computeCurrentDeclarations()` /
  `::fetchFiledDeclarations()`; cross-references an existing `VatCorrection`
  for the same `VATReturn` (created by `btw-suppletie-detection`) via
  `relatedVatCorrectionId` rather than creating a competing correction record.
- **Resolver 2 — subledger-gl-control**: sums a GL control account's
  cumulative balance directly from `GLLine`, and sums open `ARInvoice`
  (`lifecycleState` in issued/overdue/disputed) or open `APTransaction`
  (`state` in received/issued/partially-paid/overdue/disputed) for the same
  administration; seeded for both Debiteuren (1300, AR) and Crediteuren
  (1600, AP, sign-flip relationship).
- `AansluitingController`: POST compute / explain / resolve / reopen
  endpoints, `#[NoAdminRequired]` + IDOR-safe.
- Manifest navigation: `Bookkeeping > Aansluitingen` (definitions index +
  detail) and `Aansluiting Resultaten` (per-period results index + detail
  with lifecycle action buttons + audit trail), generic renderer only
  (`CnIndexPage`/`CnDetailPage`), no bespoke Vue.
- Unit tests for the calculator, the service (both resolvers + the
  lifecycle), the guard, the controller, and the register fragment.

### Out of Scope (named follow-up)

The remaining four aansluitingen the gap sweep found are explicitly deferred
to a follow-up change (to be filed as a GitHub issue on this PR's merge):

- **Year-end balance reconciliation pack** — every balance-sheet account tied
  to its supporting schedule; a new `aansluitingType` resolver on this same
  framework (many accounts, not one control account).
- **ICP <-> BTW rubriek 3b tie-out** — `IcpService::reconcile()` already
  computes this exact comparison imperatively (REQ-ICP-004) with its own
  `IcpFinalizeGuard`; the follow-up is to decide whether to keep that
  purpose-built implementation or migrate it onto the generic
  `Aansluiting`/`AansluitingResult` schemas for a unified operator UI (a
  design decision, not implemented here — see design.md Decision 5).
- **Bank-balance tie-out** — `bookkeeping-reconciliation-reports`
  (REQ-REC-001..010) already owns `BankReconciliation`/`ReconciliationMatch`/
  `ReconciliationReport` for exactly this; the follow-up is to decide whether
  those registers gain an `Aansluiting`-compatible read projection (so a
  bank tie-out shows up in the same "open aansluitingen" dashboard) rather
  than building a THIRD parallel bank-reconciliation register family.
- **XAF/auditfile completeness check** — a new `aansluitingType` resolver
  comparing the exported XAF/auditfile's declared totals against the live GL
  (not implemented — no XAF export capability exists in shillinq yet to tie
  against).
- Auto-triggering `compute()` as a scheduled sweep across all active
  `Aansluiting` definitions (this change ships a callable
  compute-per-definition entry point via the controller; wiring a
  period-close-time or nightly sweep is deferred).

## Approach

A new imperative `AansluitingService` (justified per ADR-031: both resolvers
diff two independently-queried data sources, apply an operator-overridable
tolerance decision, and persist a derived record with a bucket-level
drill-down — cross-schema compilation logic, not a single declarative
aggregation) resolves source A / source B per `aansluitingType`, delegates the
tolerance/diff arithmetic to a pure `AansluitingCalculator` (mirroring
`TrialBalanceCalculator`/`IcpCalculator`), and persists the result via
OpenRegister's `ObjectService`. See design.md for the full architecture,
the declarative-vs-imperative rationale, and the two resolvers' data-source
details.

## New Dependencies

None.

## Impact

- New file `lib/Service/AansluitingCalculator.php`.
- New file `lib/Service/AansluitingService.php`.
- New file `lib/Lifecycle/AansluitingResolutionGuard.php`.
- New file `lib/Controller/AansluitingController.php`.
- New file `lib/Settings/register.d/bookkeeping-aansluitingen.json`
  (`Aansluiting` + `AansluitingResult` schemas).
- New routes in `appinfo/routes.php` (`aansluiting#compute`,
  `aansluiting#explain`, `aansluiting#resolve`, `aansluiting#reopen`).
- New manifest entries in `src/manifest.json` (nav + 4 pages).
- New unit tests: `tests/Unit/Service/AansluitingCalculatorTest.php`,
  `tests/Unit/Service/AansluitingServiceTest.php`,
  `tests/Unit/Lifecycle/AansluitingResolutionGuardTest.php`,
  `tests/Unit/Controller/AansluitingControllerTest.php`,
  `tests/Unit/Settings/AansluitingenFragmentTest.php`.
- No changes to `VATReturnService`, `VatSuppletieDetectionService`,
  `TrialBalanceService`, `PeriodCloseAssistantService`,
  `bookkeeping-reconciliation-reports`'s `BankReconciliation`/
  `ReconciliationMatch`/`ReconciliationReport` schemas, or any existing
  lifecycle state/transition.

## Cross-Project Dependencies

None — consumes only OpenRegister's `ObjectService` (already a shillinq
dependency) per ADR-022.

## Risks

### Risk 1: The GL control-account balance is computed as an all-time
cumulative sum, not a single period's movement
**Severity:** Medium — **Mitigation:** this is the deliberate, correct
semantics for a balance-sheet control account (contrast
`TrialBalanceService::compute()`, which is single-period-scoped with an
optional prior-period carry); documented explicitly in design.md Decision 2
rather than silently assumed. The open-subledger side is likewise
inherently cumulative (an unpaid invoice from 3 periods ago still counts),
so both sides of this aansluiting are consistently life-to-date.

### Risk 2: Two aansluitingType resolvers are hand-coded, not fully
data-driven against an aggregation-string interpreter
**Severity:** Low — **Mitigation:** deliberate scope discipline (see
design.md Decision 1); the codebase has no existing generic
aggregation-invocation API in PHP to lean on (`TrialBalanceService`,
`FluxService`, and `IcpService` all hand-roll their PHP computation too), so
building one here would be speculative generalisation for a framework that,
today, has exactly two concrete instances. Adding a third resolver (per the
deferred follow-up items) is a small, explicit, well-precedented addition to
the `match` dispatch in `AansluitingService::compute()`.

### Risk 3: `IcpService::reconcile()` already implements an ICP<->rubriek 3b
tie-out outside this framework
**Severity:** Low — **Mitigation:** left untouched in this change (out of
scope, see Scope above); not a regression, and the follow-up issue
explicitly frames the migration decision rather than silently duplicating a
third parallel implementation.

## Rollback Strategy

Revert the PR. The new registers, service, guard, controller, routes, and
manifest entries are purely additive; no existing schema fields, lifecycle
states, or service methods are modified, so a revert leaves the codebase
exactly as it was.

## Open Questions

- Should `IcpService::reconcile()` eventually migrate onto the generic
  `Aansluiting`/`AansluitingResult` schemas for a unified "all open
  tie-outs" operator dashboard? Deferred to the ICP<->rubriek-3b follow-up
  item — a design decision with UI/migration implications beyond this
  change's scope.
- Should the bank-balance tie-out extend `bookkeeping-reconciliation-reports`
  in place, or gain a read-only `AansluitingResult` projection alongside its
  existing `BankReconciliation` lifecycle? Deferred to the bank-balance
  follow-up item.
