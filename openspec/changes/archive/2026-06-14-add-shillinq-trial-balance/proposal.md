# Proposal: add-shillinq-trial-balance

`kind: config` per ADR-032 — the centre of mass is declarative
`x-openregister-aggregations` over T1's `GLLine` register + a
manifest navigation entry. No PHP report builder is authored.

## Summary

Introduce the **trial balance** capability for Shillinq as one of the
first slices of the Tier 2 compliance + operations envelope (per
`adr-001-bookkeeping-tier-roadmap.md`). This change declares the
trial-balance output as one (or three composed)
`x-openregister-aggregations` query against the T1 `GLLine`
register, grouping by `(period_id, account_number, side)` with
opening / movement / closing buckets, and wires a manifest
navigation entry that renders the result. The
debit-credit-balance-verifies invariant lands as a declarative
schema invariant per ADR-031. Shillinq ships zero
`TrialBalanceService`, `TrialBalanceReportBuilder`, or similar
"assemble the trial balance" PHP classes.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`add-shillinq-general-ledger`](../add-shillinq-general-ledger/proposal.md).
The aggregation groups T1's `GLLine` rows; without GL, there is
nothing to aggregate.

## Motivation

The trial balance is the first thing any accountant opens to verify
the books — and the precondition for any monthly close, financial
statement, or audit. Without it, T1's general ledger is invisible
to bookkeepers and unauditable.

Per ADR-031, the trial balance MUST land as a declarative
aggregation, not a PHP report builder. The
`TrialBalanceReportService.php` that shillinq could otherwise grow
into is the canonical ADR-031 anti-pattern (explicitly enumerated
under "Aggregation service"). This change declares the shape that
keeps shillinq inside the declarative envelope.

This is one of eight T2 capability changes that together deliver the
compliance + operations envelope. The full set is enumerated in
`adr-001-bookkeeping-tier-roadmap.md`; this proposal scopes only the
trial-balance slice.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-trial-balance`); declares the trial-balance
  aggregation on the existing `GLLine` register in
  `lib/Settings/shillinq_register.json` and the
  debit-credit-balance invariant; adds 1 manifest navigation entry
  + 1 report/index page in `src/manifest.json`.
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-aggregations` extension. If the engine cannot
  express opening/closing buckets in a single query, the spec
  resolves to three composed queries (still declarative).

## Scope

### In Scope

- One new capability spec (`bookkeeping-trial-balance`) — see the
  `specs/` folder.
- Declarative trial-balance aggregation grouping `GLLine` by
  `(period_id, account_number, side)` with opening / movement /
  closing buckets.
- Debit-credit-balance invariant declared on the aggregation output
  (sum of debits = sum of credits across all accounts in the period).
- Manifest navigation entry (Bookkeeping > Trial Balance) using a
  `type: report` (or `type: index` fallback) page renderer from
  `@conduction/nextcloud-vue`.
- Drill-through from a trial-balance row to a filtered GL index page
  via a manifest-side URL parameter; no shillinq routing code.
- Exclusion of `state: reversed` parent transactions from
  movement totals (reversed lines remain queryable but do not
  contribute to closing balances).

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **Period close** — owned by sibling
  `add-shillinq-period-close`. Trial balance pre-close preview is
  a consumer of this aggregation, not part of this spec.
- **Balance Sheet / P&L / Cash Flow** — owned by sibling
  `add-shillinq-financial-statements`.
- **Year-to-date / multi-period comparatives** — declared as a
  manifest-side affordance (the report manifest declares N
  comparison periods); the aggregation runs once per period with no
  bespoke logic.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-trial-balance`** — declares the aggregation shape,
the invariant, the navigation entry, and the drill-through pattern.
Whether the three buckets land as one aggregation with bucket
discriminators or as three composed aggregations resolves during
`opsx-ff` design discovery; both shapes satisfy the requirement.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-TB-*` for
traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 aggregation
  declaration on `GLLine`; declares the balance invariant.
- `src/manifest.json` — adds 1 navigation entry + 1 `type: report`
  (or `type: index` fallback) page entry.
- No new PHP services, controllers, or Vue components.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-aggregations` being
  stable with multi-group support and opening/closing bucket
  semantics. If the bucket semantics aren't yet expressible in one
  query, the spec falls back to three composed aggregations (still
  declarative).
- **T1 general ledger** — depends on `add-shillinq-general-ledger`
  having landed; the aggregation references `GLLine.periodId`,
  `accountNumber`, `side`, and `amount` fields declared there.

## Risks

### Risk 1: OR's aggregation engine cannot express opening/closing buckets in one query

**Severity**: Medium
**Mitigation**: If the engine cannot express the three buckets in
one declarative pass, each bucket is its own aggregation query and
the presentation layer composes them (still declarative, no PHP
report builder). Decision lives in `spec.md` under
"Declarative-vs-imperative decision" during `opsx-ff` discovery.

### Risk 2: Reversed-transaction exclusion is missed

**Severity**: Medium
**Mitigation**: REQ-TB-002 explicitly excludes
`GLTransaction.state: reversed` parents from movement totals;
PHPUnit tests in the implementing cycle assert reversed-line
exclusion via a tampered-state fixture.

### Risk 3: Drill-through URL shape couples shillinq to OR's GL filter syntax

**Severity**: Low
**Mitigation**: Use the OR-canonical filter query-param shape
documented in the GL spec's manifest entry; no shillinq routing
code involved.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
the aggregation declaration is non-destructive (the underlying
`GLLine` data remains intact).

## Open Questions

1. **One aggregation vs three** — see Risk 1; resolved in `opsx-ff`
   discovery.
2. **Multi-period comparative columns** — the report manifest can
   declare N comparison periods; the implementing-cycle UX review
   confirms default count (e.g. current + 1 prior period).
3. **Renderer path** — `type: report` (preferred, depends on
   `CnReportPage` from nextcloud-vue) or `type: index` fallback.
   Spec is shape-neutral; renderer chosen during the implementing
   cycle based on library readiness.
