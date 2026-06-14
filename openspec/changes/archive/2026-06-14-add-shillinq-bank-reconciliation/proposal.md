# Proposal: add-shillinq-bank-reconciliation

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`BankStatement`, `BankStatementLine`, `MatchingRule`,
`ReconciliationMatch`) + `x-openregister-lifecycle` +
`x-openregister-aggregations` for rule-driven match emission +
manifest entries. No PHP matching service is authored (subject to
ADR-031 exception: at most one single-method `StatementParser` if
OR's calculation extension does not yet support CAMT.053 / MT940
primitives).

## Summary

Introduce the **bank reconciliation** capability for Shillinq as one
of the T2 compliance + operations capabilities (per
`adr-001-bookkeeping-tier-roadmap.md`). This change declares the
`BankStatement` (header per upload), `BankStatementLine` (per
transaction), `MatchingRule` (predicate-based matching policy), and
`ReconciliationMatch` (candidate / confirmed match) registers.
Matching against AP/AR is rule-driven: a `MatchingRule` schema
declares predicates consumed by an `x-openregister-aggregations`
query that emits candidates; the operator confirms / rejects
matches through the standard register UI. Unmatched lines route to
a designated suspense account. The reconciliation workflow is an OR
lifecycle on `BankStatement`. CAMT.053 + MT940 + manual CSV import
supported. PSD2 live-feed connectors are explicitly T4.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`add-shillinq-general-ledger`](../add-shillinq-general-ledger/proposal.md)
(suspense account posts to GL; matched lines clear AP / AR sub-
ledger via downstream specs),
[`add-shillinq-document-attachment-integration`](../add-shillinq-document-attachment-integration/proposal.md)
(original statement file archived via docudesk).

## Motivation

Bank reconciliation is the daily-operations loop that connects
external reality (the bank statement) to the internal ledger. The
legacy AP/AR draft cluster from intelligence-db
(`competitor_features` with `app_slug=shillinq`) calls it out as a
top-3 customer-asked capability.

Per ADR-031, the matching engine is declarative: rule predicates
on `MatchingRule` consumed by an aggregation query that emits
candidate matches. The `BankReconciliationService.php` /
`MatcherService.php` that shillinq could otherwise grow into are
the canonical anti-pattern. Per ADR-022, the statement file is
archived via docudesk, not in an app-local store.

This is one of eight T2 capability changes; this proposal scopes
only the bank-reconciliation slice.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-bank-reconciliation`); declares 4 new registers
  (`BankStatement`, `BankStatementLine`, `MatchingRule`,
  `ReconciliationMatch`) with lifecycles + aggregations; adds 2
  manifest navigation entries (Bank Reconciliation, Matching
  Rules).
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-lifecycle`, `x-openregister-aggregations`. If OR's
  calculation extension does not yet support CAMT.053 / MT940
  parsing primitives, ADR-031 exception path applies (one single-
  method `StatementParser`).
- [ ] Project: docudesk — no source changes; original statement
  files (XML / MT940 text / CSV upload) archived via docudesk per
  `bookkeeping-document-attachment-integration`.

## Scope

### In Scope

- One new capability spec (`bookkeeping-bank-reconciliation`) — see
  the `specs/` folder.
- 4 new registers: `BankStatement` (header), `BankStatementLine`
  (per transaction), `MatchingRule` (predicate-based matching
  policy), `ReconciliationMatch` (candidate / confirmed match).
- Import formats: CAMT.053 (ISO 20022 XML), MT940 (legacy SWIFT
  text), manual CSV upload.
- Matching rule predicates: `exact-amount + exact-reference`,
  `amount-range + customer-name`, `amount + counterparty-iban`,
  `partial-amount + multi-line aggregation`, etc.
- `x-openregister-aggregations` query consuming `MatchingRule`
  predicates and emitting `ReconciliationMatch` candidate records.
- Operator confirmation: the standard register UI lets the operator
  approve / reject candidate matches.
- Suspense account routing: unmatched lines post against a
  designated account (administration-configurable; flagged via
  `Account.isSuspenseAccount: true` on T1's `Account` schema, OR
  carried as an administration setting per discovery).
- Bank statement lifecycle:
  `imported → in-progress → reconciled → audit-locked`.
- Duplicate-import constraint: declarative uniqueness on file
  checksum + period overlap.
- Original statement file archived via docudesk per
  `bookkeeping-document-attachment-integration`.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **PSD2 live-feed bank connectors** — T4. T2 supports
  CAMT.053 + MT940 + manual upload only.
- **Multi-currency translation** — T5. Statement lines carry
  currency; FX revaluation lives in T5.
- **AP / AR sub-ledger clearing logic** — owned by AP / AR sibling
  specs. This capability only emits the candidate match; AP / AR
  spec lifecycles consume the confirmation.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-bank-reconciliation`** — declares the four registers,
the import-format parser declaration, the predicate-based matching
rule + aggregation, the suspense-account routing, the reconciliation
lifecycle, and the duplicate-import constraint.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-BR-*` for
traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 4 new schemas;
  declares lifecycle on `BankStatement`; declares aggregation on
  the matching engine; declares calculation (or ADR-031-exception
  parser guard) for CAMT.053 + MT940 parsing.
- T1's `Account` schema may gain an additive `isSuspenseAccount`
  boolean (optional, default `false`) per the suspense-account
  decision (resolved during discovery — either schema flag or
  administration setting).
- `src/manifest.json` — adds 2 navigation entries + their
  `type: index` + `type: detail` pages.
- No new PHP services (subject to ADR-031 exception: at most one
  single-method `StatementParser`).
- No new bespoke Vue components.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-lifecycle`,
  `x-openregister-aggregations`, and `x-openregister-calculations`
  (if the latter supports structured-text parsing). If parsing
  primitives are not yet supported, ADR-031 exception path
  applies.
- **T1 general ledger** — depends on `add-shillinq-general-ledger`
  for suspense-account posting.
- **T2 document-attachment-integration** — depends on
  `add-shillinq-document-attachment-integration` for statement-file
  archival.

## Risks

### Risk 1: CAMT.053 / MT940 format drift across Dutch banks

**Severity**: Medium
**Mitigation**: Use battle-tested OR parsing extension if
available; per-bank quirks handled through matching-rule
customisation rather than parser changes. Spec is parser-neutral —
declarative parsing extension OR single-method `StatementParser`
fallback per ADR-031 exception.

### Risk 2: Parsing extension not yet shipped on OR

**Severity**: Medium
**Mitigation**: ADR-031 exception path: a single-method
`OCA\Shillinq\Lifecycle\StatementParser::parse(string $contents,
string $format): array` ships, ~50 LOC, no state, no orchestration.
Removed when OR's calculation extension lands.

### Risk 3: Suspense account designation ambiguity

**Severity**: Low
**Mitigation**: Two paths — schema flag (`Account.isSuspenseAccount:
true`) or administration setting — resolved during `opsx-ff`
discovery. Spec is shape-neutral.

### Risk 4: Duplicate import on retried bank webhook

**Severity**: Low
**Mitigation**: REQ-BR-009 declares uniqueness on file checksum +
period overlap; duplicate imports rejected by the lifecycle
precondition. Idempotent re-import path documented.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
registers are non-destructive — bank statements remain queryable.

## Open Questions

1. **Parser path** — declarative extension or `StatementParser`
   guard; resolved in `opsx-ff` discovery.
2. **Suspense account designation** — schema flag or administration
   setting; resolved in `opsx-ff` discovery.
3. **Default matching-rule packs** — does T2 ship seed matching
   rules? Current decision: no — operators author rules on first
   use; rule templates roadmap item.
