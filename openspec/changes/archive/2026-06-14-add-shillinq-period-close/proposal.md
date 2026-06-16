# Proposal: add-shillinq-period-close

`kind: config` per ADR-032 — the centre of mass is the new
`FiscalPeriod` register with an `x-openregister-lifecycle`
state machine + manifest entries + a closed-period precondition
added to T1's `GLTransaction.post`. No PHP state-machine code is
authored.

## Summary

Introduce the **period close** capability for Shillinq as one of the
T2 compliance + operations capabilities (per
`adr-001-bookkeeping-tier-roadmap.md`). This change promotes T1's
stub-string `GLLine.periodId` to a full `FiscalPeriod` register
with an `open → closing → closed → audit-locked` lifecycle declared
declaratively per ADR-031. Postings against a closed period are
rejected by an OR lifecycle precondition added additively to T1's
existing `GLTransaction.post` precondition list. Reopening a closed
period requires elevated role + audit-trailed reason; the
`audit-locked` state is irreversible. Year-end close
(opening-balance journal generation, retained-earnings rollover) is
explicitly deferred to T3.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`add-shillinq-trial-balance`](../add-shillinq-trial-balance/proposal.md)
(pre-close preview), [`add-shillinq-general-ledger`](../add-shillinq-general-ledger/proposal.md)
(closed-period precondition added to `GLTransaction.post`).

## Motivation

A period close turns a running ledger into auditable financial
history. Without it, postings can be backdated and audits fail. Per
ADR-022, the audit-locked state freezes the period so auditors can
sign off; per ADR-031, the lifecycle is declarative, not a PHP state
machine.

Bookkeepers run monthly or quarterly close cycles; the pattern is
industry-standard (matches Exact / AFAS / Twinfield). The first
spec-driven decision is to land the close as a lifecycle on a real
`FiscalPeriod` register (currently a stub string on `GLLine`),
which both AP / AR / financial-statement sibling specs FK into.

This is one of eight T2 capability changes; this proposal scopes
only the period-close slice.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-period-close`); declares 1 new register
  (`FiscalPeriod`) in `lib/Settings/shillinq_register.json` with
  the lifecycle block; adds an additive closed-period rejection
  clause to T1's `GLTransaction.post` precondition list; promotes
  T1's stub-string `GLLine.periodId` to a real FK via additive
  `x-openregister-relations`; adds 1 manifest navigation entry.
- [ ] Project: openregister — no source changes; consumes
  `x-openregister-lifecycle` (precondition + reopen workflow) and
  `x-openregister-relations` (the FK promotion).

## Scope

### In Scope

- One new capability spec (`bookkeeping-period-close`) — see the
  `specs/` folder.
- The `FiscalPeriod` register with fields `periodId`, `name`,
  `startDate`, `endDate`, `fiscalYear`, `administrationId`, `state`,
  `closedAt`, `closedBy`, `auditLockedAt`, `auditLockedBy`,
  `closeReason`, `reopenedHistory`.
- The `open → closing → closed → audit-locked` lifecycle declared
  declaratively per ADR-031.
- Closed-period posting precondition: additive clause added to T1's
  `GLTransaction.post` rejecting postings whose `periodId` resolves
  to a `FiscalPeriod` in state `closed` or `audit-locked`.
- Reopen workflow: requires elevated role + audit-trailed reason;
  original close timestamp + actor preserved in `reopenedHistory`.
- Manifest navigation entry (Bookkeeping > Period Close) with
  `type: index` + `type: detail` pages binding to `FiscalPeriod`;
  the detail page surfaces lifecycle action buttons + a
  trial-balance preview link per the depends-on chain.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **Year-end close** — opening-balance journal generation,
  retained-earnings rollover are T3.
- **Period roll-forward to a new fiscal year** — T3.
- **VAT period close** — T3.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-period-close`** — declares the `FiscalPeriod`
register, its lifecycle, the closed-period precondition on
`GLTransaction.post`, the reopen workflow, and the navigation
entry. The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-PC-*` for
traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 new schema
  (`FiscalPeriod`); declares its lifecycle; additively augments T1's
  `GLLine.periodId` with `x-openregister-relations` resolving
  against `FiscalPeriod.periodId`; additively augments T1's
  `GLTransaction.post` precondition list with the closed-period
  rejection clause.
- `src/manifest.json` — adds 1 navigation entry + 1 `type: index` +
  1 `type: detail` page entry.
- No new PHP services, controllers, or Vue components.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-lifecycle`
  preconditions being able to additively augment T1's existing
  precondition list (stable today).
- **T1 general ledger** — depends on `add-shillinq-general-ledger`
  having landed; the FK promotion + precondition augment T1's
  schema.
- **T2 trial balance** — depends on
  `add-shillinq-trial-balance` for the pre-close preview link.

## Risks

### Risk 1: Backdating prevention vs operator correction workflow

**Severity**: Medium
**Mitigation**: Once a period is closed, ALL postings dated within
that period are rejected. Operators must reopen the period
(elevated role + audit-trailed reason) to post a correction. This
is industry-standard and matches Exact / AFAS / Twinfield
behaviour. The reopen workflow is declared in the spec.

### Risk 2: T1 `GLLine.periodId` FK promotion breaks existing stub-string values

**Severity**: Low
**Mitigation**: The FK promotion is additive — existing
stub-string values resolve against the new `FiscalPeriod` records
by exact match on `periodId`. No data migration needed; the
implementing cycle's seed step creates `FiscalPeriod` records for
every distinct historical `periodId` value.

### Risk 3: `audit-locked` state irreversibility too strict for late corrections

**Severity**: Medium
**Mitigation**: `audit-locked` is irreversible by design — after
an auditor signs off, the period must freeze. The corrective path
is a compensating journal in the next open period, not a reopen.
This is industry-standard; documented in the spec's reopen
workflow.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
registers are non-destructive; `FiscalPeriod` records remain
queryable but unreferenced.

## Open Questions

1. **Quarterly vs monthly default cadence** — confirmed during the
   implementing cycle's UX review; the spec is cadence-neutral
   (operator-configurable per administration).
2. **Auditor role plumbing for `audit-locked` transition** —
   confirmed against the Nextcloud group system + OR ACL during
   the implementing cycle's RBAC review.
3. **Reopen history retention** — the `reopenedHistory` field is
   append-only; retention follows OR's audit retention rules per
   `bookkeeping-audit-trail`.
