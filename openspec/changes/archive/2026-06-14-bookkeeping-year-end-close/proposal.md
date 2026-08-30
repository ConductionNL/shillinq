# Proposal: add-shillinq-bookkeeping-year-end-close

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`ClosingEntry`, `RetainedEarnings`) +
`x-openregister-lifecycle` + aggregations + manifest entries. No PHP
closing service classes are authored (subject to ADR-031 exception:
at most one single-method `ClosingEntryGuard` if OR's closing-workflow
extension is not yet stable).

## Summary

Introduce the **fiscal year-end close** capability for Shillinq as one
of the T4 advanced engine features (per
`adr-001-bookkeeping-tier-roadmap.md`). This capability operationalises
the transition from one fiscal year to the next, declaring the
`ClosingEntry`, `RetainedEarnings`, and `ClosingAccount` registers;
the year-end close lifecycle (open → in-progress → closed) consuming
OR's closing-workflow per ADR-022; automated closing-entry generation
(reversals, retained-earnings rollforward, opening-balance seeding);
closing checklist and approval workflow; archive-period locking; and
balance-carryforward validation.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`add-shillinq-bookkeeping-foundation`](../add-shillinq-bookkeeping-foundation/proposal.md)
(general ledger and chart of accounts),
[`add-shillinq-bookkeeping-compliance`](../add-shillinq-bookkeeping-compliance/proposal.md)
(trial balance, period-close machinery, financial statements),
[`add-shillinq-bookkeeping-advanced`](../add-shillinq-bookkeeping-advanced/proposal.md)
(multi-currency if applicable).

## Motivation

Year-end close is a mandatory bookkeeping cycle that transitions one
fiscal year to the next through a series of controlled steps: verify
trial balance, record accruals, depreciate fixed assets, declare
unrealised gains/losses, close revenue/expense accounts to retained
earnings, and lock the period. Per ADR-022, the closing workflow and
checklist come from OR's closing-workflow extension, not from an
app-local closing-service. Per ADR-031, closing-entry generation is
declarative, not a PHP `ClosingEntryService`.

The legacy AP/AR feature cluster from intelligence-db
(`competitor_features` with `app_slug=shillinq`) calls out year-end
close automation + balance carryforward + audit trail as top-tier
financial-officer features.

This is one of seven T4 capability changes; this proposal scopes only
the year-end close slice.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-year-end-close`); declares 3 new registers
  (`ClosingEntry`, `RetainedEarnings`, `ClosingAccount`) with
  lifecycles and aggregations; adds 2 manifest navigation entries
  (Year-End Close Checklist, Closing Entries).
- [ ] Project: openregister — no source changes; consumes existing
  closing-workflow (if stable; else ADR-031 exception),
  `x-openregister-lifecycle`, `x-openregister-aggregations`.

## Scope

### In Scope

- One new capability spec (`bookkeeping-year-end-close`) — see the
  `specs/` folder.
- The `ClosingEntry` register tracking accruals, reversals, and
  closing postings with template-driven generation and audit trail.
- The `RetainedEarnings` register tracking opening balance, net income,
  distributions, and closing balance with rollforward validation.
- The `ClosingAccount` register (FK to Account with `isClosingAccount`
  flag) designating the single closing/income-summary account per
  administration.
- The year-end close lifecycle (open → in-progress → closed) consuming
  OR's closing-workflow per ADR-022, with approval gates.
- Automated closing-entry generation: template-driven accrual posting
  (custom accruals per operator input), reversal of prior-year accruals,
  depreciation posting (per T4 `bookkeeping-fixed-assets-depreciation`),
  closing-entry generation (debit revenues, credit income-summary), and
  opening-balance seeding for the next fiscal year.
- Closing checklist (trial balance verified, all accruals recorded, all
  fixed-asset depreciation posted, FX gains/losses declared,
  related-party transactions reviewed) as declarative preconditions on
  the close → closed transition.
- Archive-period locking: once closed, the fiscal year becomes
  immutable (read-only for GL transactions, GL lines, etc.) per
  `x-openregister-lifecycle` immutable-period flag.
- Balance-carryforward validation: opening balance of next FY matches
  closing balance of current FY + retained earnings.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately not
  in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **Multi-currency revaluation** — T5. T4 declares the shape for single
  closed-out-in-T5.
- **Management reporting** — balance sheet and income statement sheets
  are T2 (compliance); management close-variance analysis is T4-specialized.
- **IRS/tax-specific closing** — DEP, LIFO reserve, excess-deduction
  carryback are sector-specific (T4-specialized).

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-year-end-close`** — declares the three registers, the
year-end close lifecycle (consuming OR closing-workflow), the automated
closing-entry generation rules, the closing checklist, the archive-period
locking, and balance-carryforward validation.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-YEC-*` for
traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 new schemas
  (`ClosingEntry`, `RetainedEarnings`, `ClosingAccount`); declares
  lifecycle on `ClosingEntry` and `FiscalYear`, aggregations on
  retained-earnings rollforward and closing-entry generation.
- `src/manifest.json` — adds 2 navigation entries (Year-End Close
  Checklist, Closing Entries).
- No new PHP services (subject to ADR-031 exception: one
  single-method `ClosingEntryGuard` if OR's closing-workflow extension
  is not yet stable).
- No new bespoke Vue components.

## Cross-Project Dependencies

- **OpenRegister** — depends on closing-workflow (ADR-022 — if stable;
  else ADR-031 exception path), `x-openregister-lifecycle`,
  `x-openregister-aggregations`, immutable-period flag.
- **T1 general ledger** — depends on `add-shillinq-bookkeeping-foundation`
  for GL transactions, journal entries, and chart-of-accounts hierarchy.
- **T2 compliance** — depends on
  `add-shillinq-bookkeeping-compliance` for trial-balance aggregation,
  period-close machinery, and financial statements.
- **T4 fixed-asset depreciation** — depends on
  `add-shillinq-bookkeeping-advanced` (fixed-assets spec) for automated
  depreciation posting during closing.

## Risks

### Risk 1: Closing workflow not yet stable on OR

**Severity**: Medium
**Mitigation**: If OR's closing-workflow extension is still draft at
T4 implementation time, the spec captures the gap, files an OR issue,
and the implementing cycle MAY ship a single-method
`OCA\Shillinq\Lifecycle\ClosingEntryGuard` per ADR-031 §"PHP guards
remain a legitimate seam". The guard is removed once OR's extension
lands. Spec is shape-neutral.

### Risk 2: Closing-entry generation rules are complex and operator-configurable

**Severity**: Medium
**Mitigation**: REQ-YEC-005 declares closing-entry generation as
template-driven (operator defines which accounts close to retained
earnings, which reverse, which depreciate). Templates are editable
via manifest entries. No PHP `ClosingEntryGenerator`; the lifecycle
action invokes OR's closing-workflow with the configured templates.

### Risk 3: Archive-period locking may break late corrections

**Severity**: Low
**Mitigation**: REQ-YEC-009 declares archive-period as immutable via
OR's `x-openregister-lifecycle` immutable flag. If a correction is
needed after close, the operator must unclose the period (reversing
immutability), post the correction, and re-close. This is audited per
T2 `bookkeeping-audit-trail`. The trade-off favors audit integrity
over convenience.

### Risk 4: Multi-currency revaluation deferred to T5

**Severity**: Low
**Mitigation**: T4 closes each currency independently; T5 attaches
multi-currency consolidation. REQ-YEC-010 declares the shape for
single-currency closed-out-in-T5.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
closed fiscal years remain queryable and unaffected.

## Open Questions

1. **Closing-workflow stability on OR** — see Risk 1; resolved in
   `opsx-ff` discovery; OR issue filed if needed.
2. **Default closing-entry templates** — which account groups close to
   retained earnings by default? (e.g., all revenues/expenses, or
   operative-only?); resolved during the implementing cycle's UX review.
3. **Unclose capability** — once a fiscal year is closed, can an
   operator unclose it for late corrections? Or require a journal entry
   to record corrections post-close? Settled in governance review.
4. **Retained-earnings account reconciliation** — should closing
   verification include a reconciliation report (current FY net income
   + prior FY retained earnings = this FY closing retained earnings)?
   Settled in implementing cycle.
