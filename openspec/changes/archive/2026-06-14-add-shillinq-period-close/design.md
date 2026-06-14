# Design — Period Close

## Context

T1's `GLLine.periodId` is a stub string — every line carries a
plain identifier ("2026-01") that downstream consumers parse without
referential integrity. T2's period-close capability promotes
`FiscalPeriod` to a full register with a lifecycle that gates
posting and freezes auditable history once an auditor signs off.

This change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire period-close surface as **declarative
  metadata** — `FiscalPeriod` schema + lifecycle + additive
  precondition on T1's `GLTransaction.post` + manifest entries —
  per ADR-031. No PHP state-machine code.
- Consume OR's `x-openregister-lifecycle` — per ADR-022. Zero
  parallel state-machine service.
- Make the spec a **competent-bookkeeper readable contract** —
  a Dutch SMB accountant should recognise the open / closing /
  closed / audit-locked flow and the reopen workflow as the
  industry-standard pattern.
- Keep the FK promotion additive so T1's existing stub-string
  values resolve without destructive migration.

## Non-Goals

- No PHP state-machine service, no `PeriodCloseService.php`.
- No year-end close (opening-balance journal generation,
  retained-earnings rollover) — T3.
- No VAT-period close — T3.
- No bespoke Vue components — generic `CnIndexPage` / `CnDetailPage`
  bound through the manifest.

## Decisions

### D1 — Period close is a lifecycle on a new `FiscalPeriod` register

T1's `GLLine.periodId` is a stub string. T2 promotes
`FiscalPeriod` to a full register with an
`open → closing → closed → audit-locked` lifecycle. Postings
against a closed period are rejected by an OR lifecycle
precondition on `GLTransaction.post` (added additively to T1's
existing balance + active-account precondition list).

**Alternative considered**: A PHP `PeriodCloseService` mirroring
Exact / AFAS / Twinfield. Rejected per ADR-031 — explicit anti-
pattern.

### D2 — `closed` is reversible, `audit-locked` is irreversible

The `closed` state is reversible by an operator with the
elevated `period-closer` role + an audit-trailed reason; the
original close timestamp + actor are preserved in
`reopenedHistory`. The `audit-locked` state is irreversible — once
an auditor signs off, the period freezes. Industry-standard shape;
matches Exact / AFAS / Twinfield.

Late corrections after audit-lock require a compensating journal
in the next open period.

### D3 — FK promotion is additive

T1 `GLLine.periodId` is a stub string. T2 adds an
`x-openregister-relations` block resolving against
`FiscalPeriod.periodId` — additive; existing string values resolve
by exact match. No data migration; the implementing cycle's seed
step creates `FiscalPeriod` records for every distinct historical
`periodId` value.

**Alternative considered**: Convert the field to a numeric FK with
data migration. Rejected — destructive; the string-FK pattern
preserves existing data with zero risk.

### D4 — Year-end close explicitly deferred to T3

T2 declares only the monthly/quarterly close lifecycle. Year-end
close (opening-balance journal generation, retained-earnings
rollover) is its own concern with different inputs (full-year
trial balance, retained-earnings policy per administration) and
ships in T3.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Period stamping on GL lines | T1 `GLLine.periodId` (stub string) | Promoted additively to FK via `x-openregister-relations` |
| Closed-period rejection | OR `x-openregister-lifecycle` preconditions | Additive clause on T1's `GLTransaction.post` precondition list |
| Period lifecycle state machine | OR `x-openregister-lifecycle` (ADR-031) | The whole period close |
| Reopen audit-trail capture | OR audit-trail-immutable (consumed via `bookkeeping-audit-trail`) | Automatic on lifecycle transitions |
| Reopen role gate | OR authorization | `period-closer` role required for `closing → open` |
| Audit-lock role gate | OR authorization | `auditor` role required for `closed → audit-locked` |
| Manifest navigation | T1 manifest pattern | Adds 1 menu entry + 1 index page + 1 detail page |
| Trial balance preview link | T2 trial-balance aggregation (`bookkeeping-trial-balance`) | Manifest-side link from detail page action |

**Net new code in implementation cycle**: 1 schema declaration + 1
lifecycle block + 1 additive precondition clause + 1 additive
relations block + 1 manifest entry pair. No new PHP service.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Period-close lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| Closed-period posting rejection | Declarative — adds to T1's existing `GLTransaction.post` precondition list | Engine already handles preconditions |
| Reopen role gate | Declarative — OR authorization on the transition | Standard role check |
| Audit-lock role gate | Declarative — OR authorization on the transition | Standard role check |
| Reopen history append | Declarative — schema field append-only | Engine handles |
| Pre-close trial balance preview | Manifest-side link to trial-balance aggregation | No app code |

No service class authored in this envelope.

## Seed Data

None in T2 itself. The implementing cycle's repair step creates
`FiscalPeriod` records for every distinct historical
`GLLine.periodId` value (one-shot data backfill, idempotent),
ensuring the FK promotion resolves cleanly.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Reopening a closed period is destructive | Reopen requires elevated role + audit-trailed reason; original close timestamp + actor preserved; matches industry-standard behaviour |
| `audit-locked` irreversibility is too strict for late corrections | Corrective path is a compensating journal in the next open period; documented in the spec |
| FK promotion breaks existing stub-string values | Additive change; existing strings resolve by exact match; one-shot backfill step in the implementing cycle |
| Operators close periods prematurely without closing AP / AR | Pre-close checklist (manifest-side, surfaced on detail page) lists open AP / AR items requiring resolution; non-blocking warning |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the
   `FiscalPeriod` schema (additive — no existing schema changes
   except T1's `GLLine.periodId` + `GLTransaction.post`
   precondition list, both additive).
2. `src/manifest.json` is patched with one new menu entry + one
   new index/detail page pair (additive).
3. The repair step seeds `FiscalPeriod` records for every distinct
   historical `periodId` (idempotent backfill).

Down-direction: registers are non-destructive — reverting removes
the lifecycle gating; `FiscalPeriod` records remain queryable but
unreferenced.

## Open Questions

1. **Quarterly vs monthly cadence default** — operator-configurable
   per administration; resolved during the implementing cycle's UX
   review.
2. **Pre-close checklist contents** — defaults to open AP, open
   AR, unreconciled bank lines; resolved during the implementing
   cycle.
3. **Reopen history retention** — follows OR's audit retention per
   `bookkeeping-audit-trail`.
