# Proposal: bookkeeping-general-ledger

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries. No PHP service classes are
authored (except possibly a single ~20 LOC lifecycle balance guard,
see Risk 1).

## Summary

Establish the **general ledger foundation** capability for Shillinq
as part of the Tier 1 bookkeeping rollout (per `adr-001-bookkeeping-tier-roadmap.md`).
This change declares the `GLTransaction` (header) and `GLLine` (line)
registers, with `x-openregister-lifecycle` rules enforcing the
balance invariant (sum of debits = sum of credits) on the `post`
transition per ADR-031, wired into `src/manifest.json` per ADR-024,
and consuming OpenRegister's audit and RBAC abstractions per ADR-022.
No PHP service classes, no custom database tables, no Vue components —
the entire capability lands as register metadata + manifest entries.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()`
repair-step seeding.

**Depends on:** [`bookkeeping-chart-of-accounts`](../add-shillinq-chart-of-accounts/proposal.md).
`GLLine.accountNumber` foreign-keys into `Account.accountNumber`.

## Motivation

The market demand for general ledger and financial management capabilities
is strong (1616 demand score, 538 tender mentions). Shillinq's core
accounting mission requires a balanced double-entry posting engine with
period-stamped lines as the foundation for all downstream capabilities
(trial balance, financial reporting, tax compliance, multi-currency).

Until the GL is laid down — with a balanced double-entry posting engine
and period-stamped lines — no trial balance, no financial statement,
and no period close can be implemented. This change owns the balanced
ledger itself, with support for:

- Asset repair ledger linking (174 combined demand from features 2–3)
- General ledger reporting and analytics (119 demand)
- Sub-ledger account reconciliation and management (76 combined demand from features 5–7)

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown.

## Affected Projects

- [x] Project: shillinq — adds 2 new registers/schemas (`GLTransaction`,
  `GLLine`) to `lib/Settings/shillinq_register.json`, adds 1 manifest
  navigation entry in `src/manifest.json`
- [ ] Project: openregister — no source changes; this change consumes
  existing OR abstractions (audit, RBAC, `x-openregister-lifecycle`,
  `x-openregister-relations`). If the lifecycle engine cannot express
  the cross-line balance constraint declaratively (see Risk 1), the
  gap is filed as an OR issue and a thin ~20 LOC PHP guard is
  registered through the engine's exception path per ADR-031.

## Scope

### In Scope

- One new capability spec (`bookkeeping-general-ledger`) — see the
  `specs/` folder.
- Header/line split for GL transactions: `GLTransaction` carries
  period, posting date, description, source reference, balanced-state,
  posting-state; `GLLine` carries account FK, amount, side
  (`debit`|`credit`), optional sub-ledger FK, optional cost centre.
- Double-entry posting engine — every GL transaction is a balanced
  set of journal lines (sum of debits = sum of credits in the
  administration's base currency), enforced by an
  `x-openregister-lifecycle` precondition on the `post` transition.
- Sub-ledger references (AP / AR / project) by FK only — Tier 2 owns
  the sub-ledgers themselves.
- Period-stamped postings — every `GLLine` carries a `periodId`
  resolved at post-time against the active fiscal-period record
  (FK to a `FiscalPeriod` schema declared later by Tier 3; in
  Tier 1 the field is a plain string identifier).
- Reversal lifecycle (`draft → posted → reversed`) declared
  declaratively; reversed transactions emit an inverse audit event.
- Asset repair integration hooks (via sub-ledger FK and post-date
  linking mechanism) — downstream specs will leverage.
- Manifest navigation entry (Bookkeeping > General Ledger) using
  `type: index`/`type: detail` page renderers from
  `@conduction/nextcloud-vue`.
- Audit trail consumed from OpenRegister's audit-trail-immutable
  abstraction per ADR-022 — DO NOT reimplement.

### Out of Scope

- **Implementation code** — this is a spec-only change. PHP services,
  Vue components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **Chart of accounts** — owned by sibling
  `bookkeeping-chart-of-accounts`.
- **Journal entries (human surface)** — owned by sibling
  `bookkeeping-journal-entries`.
- **Sub-ledgers (AP/AR), trial balance, period close** — Tier 2/Tier 3
  capabilities.
- **Multi-currency translation, CTA postings** — Tier 5.
- **Asset repair module** — referred to as downstream integration
  point, not part of this change.

## Risks

### Risk 1: Balance Precondition — Declarative vs. Imperative

The balance invariant (sum of debits = sum of credits per transaction)
must be enforced as a precondition on the `posted` state transition.

**Uncertainty:** OpenRegister's `x-openregister-lifecycle.requires`
engine may not yet support cross-line aggregation queries. If the
engine cannot express the constraint declaratively, this change's
implementation cycle will:

1. File an issue on the OpenRegister repo documenting the gap.
2. Register a thin ~20 LOC guard class implementing the check in PHP,
   annotated with `@spec openspec/changes/bookkeeping-general-ledger/tasks.md#task-7`
   and the ADR-031 exception reason.

**Mitigation:** The spec will be written to assume the declarative path.
If the exception path is taken, the guard is scoped to a single method
and the design.md will document the decision with alternatives.

### Risk 2: Period Misalignment — Fiscal Period Dependency

The `periodId` field references a `FiscalPeriod` schema not declared
until Tier 3. Until then:

1. Tier 1 treats `periodId` as a plain string identifier (e.g. `"2026-Q1"`).
2. Tier 3 will introduce the `FiscalPeriod` register and add the
   `x-openregister-relations` FK.
3. The auto-resolution precondition on `posted` transition (REQ-GL-006)
   is a no-op in Tier 1, allowing draft lines with any `periodId` and
   accepting the transition as long as the line's `periodId` string
   matches the parent's `periodId` string.

**Mitigation:** The spec clearly marks this as "stub until Tier 3" and
the implementation cycle documents the migration path.

## Rollback

All schema and manifest changes are non-breaking additions to existing
register metadata. Rollback is a single git revert of the commit
introducing `lib/Settings/shillinq_register.json` GLTransaction/GLLine
definitions and the `src/manifest.json` navigation entry. No data
migrations are required.

## Open Questions

1. **BalanceGuard path:** Will OpenRegister's lifecycle engine support
   the cross-line aggregation query for the balance precondition?
   (Answer expected during implementation cycle.)
2. **Asset repair deep-linking:** What is the exact POST endpoint
   shape for creating an asset-repair-linked GL transaction? (Answer
   in downstream spec for asset repair integration — out of scope here.)
