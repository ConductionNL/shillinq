# Design — Schatkistbankieren

## Context

Wet HOF mandates Treasury banking beyond a drempelbedrag for Dutch
decentralised governments. Schatkist deposits/withdrawals post to
the GL like any other bank transaction; the distinguishing markers
are (1) an `isSchatkistAccount` flag on the bank account record,
and (2) a daily aggregated `SchatkistPosition` derived from those
flagged accounts.

This is one of ten T3 capability splits per ADR-032 spec-sizing.

The change is **spec-only**.

## Goals

- Add `isSchatkistAccount` flag to T1 `Account` (additive).
- Declare `SchatkistPosition` as a derived register holding daily
  position records.
- Declare the daily aggregation as an OR `ScheduledWorkflow` per
  ADR-031 (no `*Job` class).
- Surface the position as a `CnDashboardPage` widget per ADR-024.
- Avoid the parallel-ledger anti-pattern — schatkist transactions
  use T1 GL like any other bank.

## Non-Goals

- No app-local `SchatkistService` (ADR-031 anti-pattern).
- No parallel ledger (ADR-022 anti-pattern).
- No app-local cron job (`*Job` class) — ADR-031 anti-pattern.

## Decisions

### D1 — `isSchatkistAccount` lives on T1 `Account`

The flag belongs on the bank account record, not a separate
register, because (1) it's an intrinsic property of the account
(yes/no), (2) the aggregation needs a fast filter on this property,
and (3) it doesn't need per-administration override (one account
is either schatkist or not).

### D2 — `SchatkistPosition` as a derived register, not a ledger

The position is conceptually a *snapshot* derived from GL postings
on flagged accounts. The register holds one record per
administration per business day; each record is the
end-of-business-day position computed by the daily
`ScheduledWorkflow`.

**Alternative considered**: A parallel `SchatkistJournal` ledger
with its own transactions. Rejected — ADR-022 anti-pattern
("parallel ledger"). Schatkist transactions post to T1 GL like any
other bank transaction; the derived position aggregates from there.

### D3 — Daily aggregation as `ScheduledWorkflow`, not `*Job`

Per ADR-031, scheduled tasks are declared as OR `ScheduledWorkflow`
entries, never as app-local `*Job` PHP classes (the
"`Background jobs that walk an object queue`" anti-pattern).

The cron defaults to once-per-business-day; operators reconfigure
via OR's standard cron-expression UI. Weekends + Dutch bank
holidays skipped via OR's holiday-aware cron library.

### D4 — Threshold-crossing notification declarative

When the daily position crosses the seeded threshold (per
administration type from `schatkist-thresholds.json`), an
`x-openregister-notifications` event fires for the treasury-
officer role.

### D5 — Position widget on `CnDashboardPage`

The widget surfaces current position, threshold, days-above-
threshold, and trend. Declared via `x-openregister-widgets`;
rendered by `CnDashboardPage`. No bespoke Vue.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Account flag | T1 `Account` schema | Additive field `isSchatkistAccount` |
| Daily position aggregation | `x-openregister-aggregations` (ADR-031) | Cross-schema projection over T1 `GLLine` filtered by `isSchatkistAccount=true` |
| Daily workflow | OR `ScheduledWorkflow` (ADR-031) | Cron-driven; replaces the `*Job` anti-pattern |
| Threshold notification | `x-openregister-notifications` (ADR-031) | Fires on threshold-crossing |
| Dashboard widget | `x-openregister-widgets` + `CnDashboardPage` | Schema-derived; no bespoke Vue |
| RBAC (treasury-officer) | OR authorization (ADR-022) | Per-schema role |
| Audit trail | OR audit-trail-immutable (ADR-022) | Consumed automatically |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` | 1 menu entry under `Overheid` with visibility predicate |
| Threshold seed | `ConfigurationService::importFromApp()` | `schatkist-thresholds.json` |

**Net new code in implementation**: 1 schema declaration + 1 field
extension on `Account` + 1 manifest entry + 1 widget + 1 seed
JSON + 1 `ScheduledWorkflow` declaration. No new PHP service.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| `SchatkistPosition` derivation | Declarative (`x-openregister-aggregations`) | Standard projection-filter |
| Daily aggregation cron | Declarative (`ScheduledWorkflow`) | ADR-031 §"Background jobs that walk an object queue" path 2 |
| Threshold-crossing notification | Declarative (`x-openregister-notifications`) | Standard event-driven |
| Dashboard widget | Declarative (`x-openregister-widgets`) | Schema-derived |

No service class authored.

## Seed Data

| File | Purpose | Approximate row count | Citation |
|---|---|---|---|
| `lib/Settings/seeds/schatkist-thresholds.json` | Drempelbedrag per administration type (small gemeente 0.75%, large gemeente 0.5%, provincie 0.5%, waterschap 0.5% of begroting) | 4 | Wet HOF art. 2 + ministerial regeling |

SPDX header + `_meta` block with statutory citation + version.
Loaded via the repair step. Operator cannot override the canonical
percentages without escalating (the absolute drempel depends on
the operator's begroting and is operator-supplied per administration).

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Daily cadence on bank-holiday | OR holiday-aware cron skips |
| Threshold formula revision | Versioned seed; coexistence trivial |
| Pre-flagging historic data | Aggregation forward-only from flag-set date |
| Intra-day position query | Out of scope; can be added later as `x-openregister-calculations` on demand |

## Migration Plan

Spec-only. When implementation lands:

1. T1 `Account` gains `isSchatkistAccount` field (additive,
   default `false`).
2. `lib/Settings/shillinq_register.json` adds 1 schema (additive).
3. `src/manifest.json` adds 1 navigation entry + 1 widget.
4. The repair step imports the threshold seed and registers the
   daily `ScheduledWorkflow`.

Down-direction: revert the implementing PR, run the repair step in
down-direction. Existing position records remain queryable.

## Open Questions

1. **Drempelbedrag formula stability** — operator-supplied
   begroting × seeded percentage; confirm with treasury-officer
   persona.
2. **Intra-day position** — out of scope here; deferred to a
   future enhancement.
