# Design — Trial Balance

**Status:** pr-created

## Context

T1's balanced double-entry GL surface (`GLTransaction` + `GLLine`)
ships through `add-shillinq-general-ledger`. The first thing any
accountant opens after the GL is laid down is the trial balance —
the per-account opening / movement / closing summary that proves
the books balance.

Per ADR-031, the trial balance MUST be declarative. The
`TrialBalanceReportService.php` that shillinq could otherwise grow
into is the canonical ADR-031 anti-pattern. This change declares the
shape that keeps shillinq inside the declarative envelope.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire trial-balance surface as **declarative
  aggregation metadata** on T1's existing `GLLine` register +
  manifest entries — per ADR-031. No PHP report builder.
- Consume OR's `x-openregister-aggregations` extension — per
  ADR-022. Zero report-builder service in shillinq.
- Make the spec a **competent-bookkeeper readable contract** — a
  Dutch SMB accountant should recognise the shape as a faithful
  trial balance with opening / movement / closing columns, balanced
  by construction.
- Keep the shape narrow enough that the sibling
  `bookkeeping-financial-statements` capability can compose the
  trial-balance aggregation without reshaping it.

## Non-Goals

- No PHP report builder, no `TrialBalanceService.php`.
- No Balance Sheet / P&L / Cash Flow assembly — sibling spec owns
  those.
- No period close lifecycle — sibling spec owns it.
- No bespoke Vue components beyond the generic `CnReportPage` (or
  `CnIndexPage` fallback) from `@conduction/nextcloud-vue`.

## Decisions

### D1 — Trial balance is an aggregation, not a report builder

Per ADR-031, the trial balance is declared as **three composed**
`x-openregister-aggregations` queries on `GLLine` (see D5 below
for the one-vs-three resolution): `trialBalanceOpening`,
`trialBalanceMovement`, `trialBalanceClosing`. Each groups by
`accountNumber`; the manifest renderer composes the three result
sets client-side. No shillinq PHP service.

**Alternative considered**: A PHP `TrialBalanceReportService`
mirroring Exact / Twinfield / AFAS. Rejected per ADR-031 —
explicitly enumerated as the anti-pattern.

### D2 — Drill-through is a manifest-side affordance

The trial-balance row links to a filtered GL index page
(`/general-ledger?period=…&account=…`) via a manifest-declared URL
template. No shillinq routing code; the OR-canonical filter
query-param shape is used.

**Alternative considered**: An app-local routing layer that builds
filter URLs server-side. Rejected — manifest-driven URL templates
keep the surface inside the Tier-4 envelope.

### D3 — Balance invariant is declarative, not a PHP service check

The debit-credit-balance-verifies invariant (sum of period debits =
sum of period credits across all accounts) is declared as a schema
invariant on the aggregation output, not as a PHP service check.
Per ADR-031, invariants on aggregations are themselves declarative.

**Alternative considered**: A PHP `BalanceVerifier` running on
every aggregation evaluation. Rejected — declarative invariants
trip during aggregation evaluation without imperative orchestration.

### D4 — Reversed transactions excluded from movement totals

`GLTransaction.state: reversed` parents are excluded from movement
totals; reversed lines remain queryable (audit trail, drill-through)
but do not contribute to closing balances. Standard bookkeeping
shape; matches Exact / AFAS / Twinfield.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| GL line storage | T1 `GLLine` register | Consumed via aggregation; no GL field changes |
| Period stamping | T1 `GLLine.periodId` (stub string in T1; FK in T2 once `FiscalPeriod` lands) | Aggregation groups on `periodId` regardless of whether it's a stub or FK |
| Aggregation engine | OR `x-openregister-aggregations` (ADR-031) | The entire trial balance |
| Multi-group with bucket discriminators | OR aggregation (if supported) | Single query; else three composed queries (still declarative) |
| Invariant declaration | OR aggregation invariant block (ADR-031) | Balance check |
| Drill-through filter URL | OR-canonical filter query-param shape | Manifest-declared URL template |
| Report renderer | `CnReportPage` from `@conduction/nextcloud-vue` (preferred) or `CnIndexPage` fallback | Tier-4 manifest binding |
| Audit trail on aggregation queries | OR audit-trail-immutable (consumed via `bookkeeping-audit-trail`) | Read operations logged automatically |

**Net new code in implementation cycle**: 1 aggregation declaration +
1 invariant declaration + 1 manifest entry pair. No new PHP service.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Trial balance assembly | Declarative (`x-openregister-aggregations`) | Pure GROUP BY + SUM over T1's `GLLine` |
| Bucket discrimination (opening / movement / closing) | **Three composed queries** — `trialBalanceOpening`, `trialBalanceMovement`, `trialBalanceClosing` (see D5) | OR engine cannot express cross-period bucket discriminators in a single declarative query at current capability level |
| Balance invariant | Declarative — `x-invariant` on `trialBalanceMovement` output | Engine evaluates during aggregation; surfaces delta to cent on fail per REQ-TB-003 |
| Drill-through | Declarative — manifest-side `rowLink.urlTemplate` | No app routing code per ADR-031 D2 |
| Reversed-transaction exclusion | Declarative — `parentTransaction.state__neq: reversed` filter on all three aggregations | Pure data filter; lines remain queryable per REQ-TB-002 |

No service class authored in this envelope.

### D5 — One aggregation vs three: resolved as three composed (Task 10)

**Decision**: Three composed aggregations (`trialBalanceOpening`,
`trialBalanceMovement`, `trialBalanceClosing`) declared on
`GLLine.x-openregister-aggregations` in
`lib/Settings/shillinq_register.json`.

**Rationale**: OR's aggregation engine at the current version
(`^v0.2.10`) cannot express opening balance (aggregation over
periods *before* the requested period) in the same query as the
movement (aggregation over *exactly* the requested period) without
a subquery mechanism not yet supported in `x-openregister-aggregations`.
Three separate queries — each a straightforward GROUP BY + SUM —
are issued by the manifest renderer; closing is derived as
`opening + movement` client-side in `trialBalanceClosing.composedFrom`.
Both shapes satisfy REQ-TB-001 ("one or composed three"); this
resolution lands the three-composed shape. The balance invariant
(`x-invariant.balanceInvariant`) is attached to `trialBalanceMovement`,
the query that produces the period's debits and credits.

**Renderer path**: `type: index` fallback (not `type: report`) —
`CnReportPage` is not yet stable at the time of this PR. The page
is declared in `src/manifest.json` with explicit six-column shape
and a `rowLink.urlTemplate` drill-through per REQ-TB-004.

## Seed Data

None. The trial balance is purely computed; no seeds.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR aggregation engine cannot express buckets in one query | Three composed queries (still declarative); decision in `opsx-ff` discovery; spec shape-neutral |
| Reversed-transaction exclusion missed | REQ-TB-002 explicit; PHPUnit assertion on tampered-state fixture in implementing cycle |
| Multi-period comparatives perform poorly with N>3 periods | Default to current + 1 prior; expose period count as a manifest parameter so operators can extend with knowledge of cost |
| `CnReportPage` library not yet ready | Fallback to `type: index` with a flat column shape; spec shape-neutral |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the trial-
   balance aggregation declaration on `GLLine` (additive — no
   existing schema changes).
2. `src/manifest.json` is patched with one new menu entry + one new
   report/index page (additive).

Down-direction: aggregations are non-destructive — disabling the
aggregation declaration leaves the underlying `GLLine` data intact
and queryable through normal CRUD.

## Open Questions

1. **One aggregation vs three** — **Resolved**: three composed aggregations (see D5).
2. **Default comparative period count** — resolved during the
   implementing cycle's UX review.
3. **Renderer path** (`CnReportPage` vs `CnIndexPage` fallback) —
   **Resolved**: `type: index` fallback used; `CnReportPage` not yet stable.
