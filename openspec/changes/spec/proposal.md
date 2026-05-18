# Proposal: add-shillinq-general-ledger

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries. No PHP service classes are
authored (except possibly a single ~20 LOC lifecycle balance guard,
see Risk 1).

## Summary

Introduce the **double-entry general ledger** capability for Shillinq
as the second slice of the bookkeeping foundation (Tier 1 of the
5-tier bookkeeping rollout per `adr-001-bookkeeping-tier-roadmap.md`).
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

**Depends on:** [`add-shillinq-chart-of-accounts`](../add-shillinq-chart-of-accounts/proposal.md).
`GLLine.accountNumber` foreign-keys into `Account.accountNumber`.

## Motivation

Shillinq's `adr-000-data-model.md` lists `GeneralLedgerEntry` as a
foundational entity. Every downstream Shillinq capability (sub-ledgers,
trial balance, period close, financial reporting, multi-currency, tax)
reads from the general ledger. Until the GL is laid down — with a
balanced double-entry posting engine and period-stamped lines — no
trial balance, no financial statement, and no period close can be
implemented.

The sibling `add-shillinq-chart-of-accounts` change declares the
`Account` register that GL lines reference. The sibling
`add-shillinq-journal-entries` change adds the human-author surface
that materialises GL transactions on post. This change owns the
machine-side balanced ledger itself.

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
  `add-shillinq-chart-of-accounts`.
- **Journal entries (human surface)** — owned by sibling
  `add-shillinq-journal-entries`.
- **Sub-ledgers (AP/AR), trial balance, period close** — Tier 2/Tier 3
  capabilities.
- **Multi-currency translation, CTA postings** — Tier 5.
- **Frontend Vue components** beyond what `CnIndexPage` /
  `CnDetailPage` from `@conduction/nextcloud-vue` already render
  generically from a register manifest. No bespoke Vue files in this
  spec.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-general-ledger`** — declares the `GLTransaction` and
`GLLine` registers (the existing data-model entry `GeneralLedgerEntry`
is the per-line equivalent; this spec formalises the header/line split
needed for balancing). The header carries balanced-state and posting-
state lifecycle transitions; the balance constraint is an
`x-openregister-lifecycle` precondition on `post`, not a PHP service
check.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-GL-*` for
traceability.

## New Dependencies

- **`add-shillinq-chart-of-accounts`** must land first — `GLLine`
  foreign-keys into `Account`.

Otherwise none. This change consumes existing OpenRegister abstractions
and the already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`
(from `shillinq-manifest-tier4`).

## Impact

- `lib/Settings/shillinq_register.json` — adds 2 schemas
  (`GLTransaction`, `GLLine`); declares `x-openregister-lifecycle` on
  `GLTransaction` (balance + posting).
- `src/manifest.json` — adds 1 navigation entry (General Ledger) and
  1 `type: index` + 1 `type: detail` page entry.
- No new PHP services unless Risk 1 confirms the cross-line balance
  precondition cannot run inside the declarative engine, in which
  case `lib/Lifecycle/BalanceGuard.php` ships (single method, ~20 LOC,
  ADR-031 exception path).
- No new Vue components. No new controllers.

## Cross-Project Dependencies

- **OpenRegister** — depends on the following abstractions being
  stable: `x-openregister-lifecycle` (ADR-031), audit-trail-immutable
  (ADR-022). If a needed shape is missing (cross-schema sum
  constraint inside a lifecycle precondition), the gap is filed as
  an OR issue and the relevant requirement is annotated in
  `design.md` under "Declarative-vs-imperative decision".

## Risks

### Risk 1: OpenRegister's lifecycle engine cannot express the cross-line balance constraint declaratively

**Severity**: Medium
**Mitigation**: The balance constraint requires summing
`GLLine.debit` and `GLLine.credit` rows that reference the parent
`GLTransaction` and asserting equality before allowing the `post`
transition. Whether this fits inside an
`x-openregister-lifecycle.requires` declaration depends on whether
the engine supports cross-schema aggregations in preconditions. If
not, ADR-031's exception path applies: a thin
`BookkeepingBalanceGuard::isBalanced(transactionId)` PHP guard is
called from the lifecycle's `requires` field. The guard is single-
method, no state, ~20 LOC. Document the gap as an OR issue. The
spec author resolves this during `opsx-ff` discovery, not during
`opsx-apply`.

### Risk 2: Header/line shape locks downstream

**Severity**: Medium
**Mitigation**: Sub-ledgers (Tier 2), trial balance (Tier 3),
financial statements (Tier 4) all read `GLLine`. Getting the field
names wrong forces a destructive migration later. Mitigation is
rigorous spec review against the data-model ADR and existing
competitor schemas (Exact, Twinfield, AFAS, Yuki — captured in
`design.md`'s Reuse Analysis). Acceptance criterion: a competent
bookkeeper can read the spec and confirm the model matches a real
RGS-conformant general ledger.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact because no implementation lands until
`opsx-apply` is run on the spec. After implementation (separate
cycle), rollback follows the standard pattern: revert the implementing
PR, run the repair step in down-direction (registers are
non-destructive — unused schemas remain queryable but unreferenced).
No data migration risk at the spec stage.

## Open Questions

1. **Cross-schema balance precondition** — see Risk 1. The `opsx-ff`
   design phase resolves whether the lifecycle engine supports the
   constraint or whether a `requires` PHP guard is needed. The spec
   itself is shape-neutral: `REQ-GL-005` mandates the balance
   invariant without prescribing implementation.
2. **Period-id type** — Tier 1 treats `periodId` as a plain string
   identifier; Tier 3 introduces `FiscalPeriod` as a real schema and
   converts the field to an FK. The conversion is additive (existing
   strings remain valid as period identifiers).
