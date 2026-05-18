# Proposal: add-shillinq-schatkistbankieren

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries + threshold seed data +
`ScheduledWorkflow` declaration. No PHP service classes are
authored.

## Summary

Introduce **schatkistbankieren** (Treasury banking) support for
Shillinq as a T3 capability per
`adr-001-bookkeeping-tier-roadmap.md`. This change extends T1
`Account` with an `isSchatkistAccount: boolean` flag, declares the
`SchatkistPosition` register holding the daily aggregated position
(per ADR-031), declares the daily aggregation as an OR
`ScheduledWorkflow` (NOT a `*Job` class per ADR-031), wires
navigation + dashboard position-widget into `src/manifest.json`
(per ADR-024), and ships `schatkist-thresholds.json` (drempelbedrag
per administration type). No PHP service classes for state machines
or aggregation; no parallel ledger.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()`
repair-step seeding.

**Depends on:** T2 `bookkeeping-bank-reconciliation` (within T2
`accounts-receivable` spec — provides the `BankTransaction` shape
the aggregation filters) and T1 `bookkeeping-general-ledger`
(`GLLine` filtered by schatkist-flagged accounts).

## Motivation

Wet HOF mandates that decentralised governments bank with the
Treasury beyond a drempelbedrag (currently 0.75% of begroting for
small munis, 0.5% for large). Treasury banking requires daily
liquidity reporting and threshold-crossing alarms. Without
schatkist support, a Shillinq-running municipality cannot comply
with Wet HOF.

## Affected Projects

- [x] Project: shillinq — extends T1 `Account` with
  `isSchatkistAccount` flag, adds 1 new register/schema
  (`SchatkistPosition`) to `lib/Settings/shillinq_register.json`,
  adds 1 manifest navigation entry (`Overheid > Schatkist-positie`)
  + dashboard widget, ships
  `lib/Settings/seeds/schatkist-thresholds.json`, declares the
  daily aggregation `ScheduledWorkflow`.
- [ ] Project: openregister — no source changes; consumes existing OR
  abstractions (lifecycle, aggregations, scheduled workflow,
  notifications, widgets).

## Scope

### In Scope

- One new capability spec (`bookkeeping-schatkistbankieren`).
- `Account.isSchatkistAccount: boolean` field extension on T1
  `Account` schema (default `false`).
- `SchatkistPosition` register holding one record per
  administration per business day with `(administrationId, date,
  position, totalDeposits, totalWithdrawals,
  thresholdAtTimeOfRecord)`.
- Daily aggregation declared as OR `ScheduledWorkflow` (not a
  `*Job` class).
- `x-openregister-aggregations` filtering `GLLine` /
  `BankTransaction` by `isSchatkistAccount=true`.
- Threshold-crossing notification via
  `x-openregister-notifications`.
- Schatkist position widget on `CnDashboardPage` via
  `x-openregister-widgets`.
- Manifest navigation under `Overheid` (visibility for
  `gemeente`/`provincie`/`waterschap`).

### Out of Scope

- **Treasury deposit / withdrawal HTTP integration** — operator-
  configured via OpenConnector source (separate change); shillinq
  references the symbolic source name only.
- **Implementation code** — spec-only change.
- **Parallel ledger** — schatkist transactions post to GL like any
  other bank transaction per ADR-022 (no parallel ledger anti-pattern).

## Approach

One delta with ADDED Requirements under `REQ-SBK-*`.

## New Dependencies

None. Consumes T1 GL + T2 bank-reconciliation + existing OR
abstractions.

## Impact

- `lib/Settings/shillinq_register.json` — extends T1 `Account`
  with `isSchatkistAccount`; adds 1 new schema with lifecycle +
  aggregations.
- `lib/Settings/seeds/schatkist-thresholds.json` — new file (4
  admin-type thresholds), SPDX header,
  `_meta.source: "Wet HOF art. 2 + ministerial regeling"`.
- `src/manifest.json` — adds 1 navigation entry + 1 dashboard
  widget under `Overheid`, visibility predicate for municipal
  admins.
- Repair step extension to import the threshold seed and register
  the daily `ScheduledWorkflow`.
- No new PHP services.

## Cross-Project Dependencies

- **OpenRegister** — relies on `ScheduledWorkflow` + cross-schema
  aggregations. Standard shape.

## Risks

### Risk 1: Daily-aggregation cadence vs business days

**Severity**: Low
**Mitigation**: `ScheduledWorkflow` cron is once-per-business-day
(operator-configurable; weekends + Dutch bank holidays skipped via
the OR cron-expression library).

### Risk 2: Threshold revision

**Severity**: Low
**Mitigation**: Versioned seed; coexistence trivial.

### Risk 3: Account flagging on historic data

**Severity**: Low
**Mitigation**: Aggregation is forward-only from the date the
flag is set; pre-flagging postings are not retroactively included.

## Rollback Strategy

Spec-only change. Standard rollback: revert the commit; delete the
change folder. After implementation: revert the PR, run the repair
step in down-direction. Registers are non-destructive.

## Open Questions

1. **Drempelbedrag formula** — current law sets 0.75% / 0.5%
   thresholds; ministerial regeling may update. `REQ-SBK-005`
   declares as seeded values, not hardcoded.
2. **Position-mid-day query** — operator may want intra-day
   position; out of scope here (only end-of-day aggregation).
   Confirm with treasury-officer persona.
