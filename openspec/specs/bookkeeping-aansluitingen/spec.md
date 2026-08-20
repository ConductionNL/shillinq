# bookkeeping-aansluitingen Specification

**Status**: done
**Scope**: shillinq
**OpenSpec changes**:
- bookkeeping-aansluitingen (2026-07-14, archived)

## Purpose

This specification defines the `Aansluiting` (tie-out) framework: a
declarative definition of one reconciliation — source A, source B, the
expected relationship between them, and a tolerance — plus a per-period
computed instance (`AansluitingResult`) carrying the resolved totals, the
signed difference, a bucket-level drill-down, and an
`open -> explained -> resolved` lifecycle with an audit-trailed explanation.
It ships the framework plus two concrete instances: BTW-ledger -> aangifte
and subledger -> GL control account. It explicitly does NOT re-implement
`bookkeeping-reconciliation-reports`' bank-balance tie-out or
`bookkeeping-icp-opgaaf`'s ICP<->rubriek-3b tie-out — both are named as
follow-up integration work (see the change's proposal.md).

Four further aansluitingen found by the 2026-07 gap sweep — the year-end
balance reconciliation pack, the ICP<->rubriek-3b tie-out, the bank-balance
tie-out (which MUST extend `bookkeeping-reconciliation-reports`'s existing
`BankReconciliation`/`ReconciliationMatch`/`ReconciliationReport` schemas
rather than duplicate them), and the XAF/auditfile completeness check — are
explicitly deferred as named follow-up work, to be filed as a GitHub issue
on this change's merge.

## Requirements

@e2e exclude unbuilt UI: aansluitingen index/detail pages not yet built
against a live Playwright target

### Requirement: REQ-AANS-001: `Aansluiting` SHALL be declared as a definition register

An `Aansluiting` MUST be expressed as a new register in
`lib/Settings/register.d/bookkeeping-aansluitingen.json` per ADR-037,
carrying: `name`, `aansluitingType` (enum, dispatch key for the resolver),
`sourceALabel`/`sourceBLabel` (display), `expectedRelationship` (`equal` |
`equal-with-sign-flip`), `toleranceCents`, and — for
`aansluitingType=subledger-gl-control` — `controlAccountNumber` and
`subLedgerType` (`ar` | `ap`). `Aansluiting` is master data (no lifecycle);
one definition is computed once per fiscal period, producing one
`AansluitingResult`.

#### Scenario: Reviewer confirms no parallel report-builder register

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/*ReportBuilder*.php`,
  `lib/Service/*Report*Service.php` introduced by this change
- **THEN** no such files SHALL exist — the framework is `Aansluiting` +
  `AansluitingResult` + `AansluitingService` + `AansluitingCalculator`, not
  a report-builder hierarchy.

#### Scenario: An Aansluiting definition declares both relationship types

- **GIVEN** the seeded `Aansluiting` definitions
- **WHEN** inspected
- **THEN** at least one carries `expectedRelationship: "equal"` and at
  least one carries `expectedRelationship: "equal-with-sign-flip"`.

### Requirement: REQ-AANS-002: `AansluitingService::compute()` SHALL dispatch on `aansluitingType` to a resolver

The system SHALL satisfy this requirement: `AansluitingService::compute()`
SHALL dispatch on `aansluitingType` to a resolver.

`AansluitingService::compute(aansluitingId, periodId)` MUST fetch the
`Aansluiting` definition, dispatch to the resolver named by its
`aansluitingType`, and fail (throwing, not silently returning a zero-total
result) when `aansluitingType` is unsupported. This change implements
exactly two resolvers: `btw-ledger-aangifte` and `subledger-gl-control`.
Additional aansluitingen (year-end balance pack, ICP<->rubriek-3b,
XAF/auditfile completeness) are out of scope and named as follow-up work.

#### Scenario: Unsupported aansluitingType fails loudly

- **GIVEN** an `Aansluiting` definition with `aansluitingType: "year-end-pack"`
  (not yet implemented)
- **WHEN** `compute()` is called against it
- **THEN** the call MUST throw, naming the unsupported type; it MUST NOT
  return a zero-total `AansluitingResult`.

### Requirement: REQ-AANS-003: The tolerance decision SHALL be computed per the declared `expectedRelationship`

For `expectedRelationship: "equal"`, the signed difference MUST be computed
as `toCents(sourceATotal) - toCents(sourceBTotal)`. For
`"equal-with-sign-flip"`, it MUST be computed as
`toCents(sourceATotal) + toCents(sourceBTotal)` (source A is expected to
carry the opposite sign convention from source B — e.g. a liability GL
control account, debit-negative, against its positive-sum subledger total).
A result is `withinTolerance` when `abs(differenceCents) <= toleranceCents`.

#### Scenario: Equal relationship computes a simple difference

- **GIVEN** sourceATotal €4,200.00 and sourceBTotal €4,450.00 with
  `expectedRelationship: "equal"`
- **WHEN** the difference is computed
- **THEN** `differenceCents` MUST be `-25000`.

#### Scenario: Sign-flip relationship nets a balanced position to zero

- **GIVEN** sourceATotal €-9,200.00 (a liability control account) and
  sourceBTotal €9,200.00 (its subledger total) with
  `expectedRelationship: "equal-with-sign-flip"`
- **WHEN** the difference is computed
- **THEN** `differenceCents` MUST be `0` and the result MUST be
  `withinTolerance`.

### Requirement: REQ-AANS-004: `compute()` SHALL persist an `AansluitingResult` and auto-resolve within-tolerance differences

The system SHALL satisfy this requirement: `compute()` SHALL persist an
`AansluitingResult` and auto-resolve within-tolerance differences.

`compute()` MUST persist one `AansluitingResult` per `(aansluitingId,
periodId)`, carrying `sourceATotal`, `sourceBTotal`, `differenceCents`,
`withinTolerance`, and a `lineDeltas` bucket-level drill-down (a `TOTAL`
summary row plus resolver-specific detail rows). When `withinTolerance` is
true, `status` MUST be set to `resolved` automatically (`resolvedBy:
"system"`) — there is nothing for an operator to explain. When false,
`status` MUST be `open`. Recomputing an `AansluitingResult` already
`explained` or `resolved` MUST return the existing record unchanged — an
operator's explanation is never silently overwritten; the caller must
`reopen()` it first.

#### Scenario: Within-tolerance compute auto-resolves

- **GIVEN** an `Aansluiting` whose sourceA and sourceB totals differ by
  €0.50 and `toleranceCents: 100`
- **WHEN** `compute()` runs
- **THEN** the persisted `AansluitingResult` MUST have `status: "resolved"`
  and `resolvedBy: "system"`.

#### Scenario: Recompute never overwrites an explained result

- **GIVEN** an `AansluitingResult` in status `explained` with
  `explanationReasonText` set
- **WHEN** `compute()` is called again for the same `(aansluitingId,
  periodId)`
- **THEN** the existing record MUST be returned unchanged; no new totals
  MUST be written.

### Requirement: REQ-AANS-005: The `subledger-gl-control` resolver SHALL compare a GL control account's cumulative balance to its open subledger total

The system SHALL satisfy this requirement: the `subledger-gl-control`
resolver SHALL compare a GL control account's cumulative balance to its
open subledger total.

Source A MUST be the declared `controlAccountNumber`'s all-time cumulative
GL balance (summed directly from `GLLine`, debit-positive convention,
excluding eliminated lines — not a single period's movement, since a
balance-sheet control account's tie-out target is its life-to-date
balance). Source B MUST be the sum of open subledger items for the same
administration: `ARInvoice` records whose `lifecycleState` is one of
`issued`, `overdue`, `disputed` (for `subLedgerType: "ar"`), or
`APTransaction` records whose `state` is one of `received`, `issued`,
`partially-paid`, `overdue`, `disputed` (for `subLedgerType: "ap"`).
`lineDeltas` MUST carry one row per open item contributing to source B
(drill-down). This is the comparison `PeriodCloseAssistantService::
detectOpenSubLedger()` never makes — that method only counts draft/unposted
`GLTransaction`s.

#### Scenario: Balanced AR subledger auto-resolves

- **GIVEN** GL account 1300 (Debiteuren) carries a cumulative debit balance
  of €18,500.00, and two open `ARInvoice` records (`issued` + `overdue`)
  sum to €18,500.00, and one `paid` invoice of €5,000.00 exists
- **WHEN** the subledger-gl-control (AR) aansluiting is computed
- **THEN** `sourceATotal` MUST be €18,500.00, `sourceBTotal` MUST be
  €18,500.00 (the paid invoice excluded), `differenceCents` MUST be `0`,
  and `status` MUST be `resolved`.

#### Scenario: AP drift reports an itemized drill-down row

- **GIVEN** GL account 1600 (Crediteuren) carries a cumulative credit
  balance reported as sourceATotal €-9,200.00, and one open `APTransaction`
  of €9,350.00, with `expectedRelationship: "equal-with-sign-flip"`
- **WHEN** the subledger-gl-control (AP) aansluiting is computed
- **THEN** `differenceCents` MUST be `15000` (€150.00), `status` MUST be
  `open`, and `lineDeltas` MUST include a row keyed by the open
  `APTransaction`'s id with `sourceBAmount: 9350.0`.

### Requirement: REQ-AANS-006: `AansluitingResult` SHALL support an audit-trailed `open -> explained -> resolved` lifecycle

`AansluitingResult` MUST declare an `x-openregister-lifecycle` with states
`open` (initial), `explained`, `resolved`, and transitions:

| From | To | Trigger | Guard |
|---|---|---|---|
| `open` | `explained` | operator explain | requires non-blank `explanationReasonText` |
| `explained` | `resolved` | operator resolve | `AansluitingResolutionGuard::canResolve` |
| `open` | `resolved` | system auto-resolve | `withinTolerance == true` at compute time |
| `explained` | `open` | operator/system reopen | none (audit-trailed reason) |
| `resolved` | `open` | operator/system reopen | none (audit-trailed reason) |

`explain()` MUST reject a blank `explanationReasonText`. `resolve()` MUST
reject a result not in `explained` status and MUST be denied fail-closed by
`AansluitingResolutionGuard::canResolve()` on any missing explanation or
internal error (CWE-863). Every transition MUST be audit-trailed (actor,
timestamp) via `x-openregister-audit-trail`.

#### Scenario: Explain requires a non-blank reason

- **GIVEN** an open `AansluitingResult`
- **WHEN** an operator calls `explain()` with a blank `reasonText`
- **THEN** the call MUST throw and `status` MUST remain `open`.

#### Scenario: Resolve is denied without an explanation

- **GIVEN** an `AansluitingResult` in status `open` (never explained)
- **WHEN** an operator calls `resolve()`
- **THEN** the call MUST throw; `AansluitingResolutionGuard::canResolve()`
  MUST return false for a non-`explained` status.

#### Scenario: Reopen clears the resolution stamp

- **GIVEN** a `resolved` `AansluitingResult`
- **WHEN** an operator calls `reopen()` with a reason
- **THEN** `status` MUST become `open`, `resolvedBy`/`resolvedAt` MUST be
  cleared, and the reason MUST be audit-trailed.

### Requirement: REQ-AANS-007: The `btw-ledger-aangifte` resolver SHALL cross-reference an existing `VatCorrection` rather than duplicating it

The system SHALL satisfy this requirement: the `btw-ledger-aangifte`
resolver SHALL cross-reference an existing `VatCorrection` rather than
duplicating it.

Source A MUST be `VATReturnService::computeCurrentDeclarations()`'s live
BTW-grootboek recompute; source B MUST be
`VATReturnService::fetchFiledDeclarations()`'s as-filed snapshot for the
same `VATReturn`. `lineDeltas` MUST carry one row per `type:taxRate`
rubriek bucket. When a `VatCorrection` already exists referencing the same
`VATReturn` (`originalVatReturnId`) — created by the
`btw-suppletie-detection` capability's `VatSuppletieDetectionService` — the
`AansluitingResult` MUST set `relatedVatCorrectionId` to it. This aansluiting
MUST NOT create, prepare, or file a `VatCorrection` itself; it is a
read-oriented status tracker layered on top of, and cross-referencing, the
existing suppletie-detection write path.

#### Scenario: A filed return with no drift produces no open result

- **GIVEN** a filed `VATReturn` whose GL-derived current declarations
  exactly match its as-filed declarations
- **WHEN** the btw-ledger-aangifte aansluiting is computed
- **THEN** the persisted `AansluitingResult` MUST have `differenceCents: 0`
  and `status: "resolved"`.

#### Scenario: An existing VatCorrection is linked, not duplicated

- **GIVEN** a `VatCorrection` already exists with
  `originalVatReturnId` matching the `VATReturn` being computed
- **WHEN** the btw-ledger-aangifte aansluiting is computed
- **THEN** the resulting `AansluitingResult.relatedVatCorrectionId` MUST
  equal that `VatCorrection`'s id; no second `VatCorrection` MUST be
  created.

### Requirement: REQ-AANS-008: Aansluitingen SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare:

- `Bookkeeping > Aansluitingen` — `type: index` + `type: detail` on
  `Aansluiting` definitions.
- `Aansluiting Resultaten` — `type: index` (reachable from the definition
  detail page) + `type: detail` on `AansluitingResult`, filterable by
  `status`, showing the resolved totals, `lineDeltas` drill-down, and
  lifecycle action buttons per REQ-AANS-006.

Rendering MUST use `@conduction/nextcloud-vue` generic components per
ADR-024 — no bespoke Vue files. `AansluitingResult` MUST declare
`openCountByAdministration` and `openCountByAansluiting`
`x-openregister-aggregations` (count, grouped, filtered to `status: open`)
so an operator dashboard tile can surface the total count of unresolved
tie-out differences across every computed aansluiting.

#### Scenario: Aansluiting Resultaten page surfaces the drill-down

- **GIVEN** the manifest declares the Aansluiting Resultaten pages
- **WHEN** an operator opens an `AansluitingResult` detail
- **THEN** the page MUST display `sourceATotal`, `sourceBTotal`,
  `differenceCents`, `withinTolerance`, `status`, the `lineDeltas` table,
  and lifecycle action buttons (explain/resolve/reopen) appropriate to the
  current status.

#### Scenario: Open-count aggregation excludes resolved results

- **GIVEN** 3 `AansluitingResult` records in status `open` and 2 in status
  `resolved` for one administration
- **WHEN** the `openCountByAdministration` aggregation runs
- **THEN** the result MUST report `3` for that administration; the 2
  resolved records MUST be excluded.
