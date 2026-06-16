# Proposal: add-shillinq-bookkeeping-foundation

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries + RGS seed data. No PHP service
classes are authored.

## Summary

Introduce the foundational double-entry bookkeeping engine for Shillinq as
**Tier 1 of a 5-tier bookkeeping rollout**. This change adds three new
declarative capabilities — chart of accounts, general ledger, and journal
entries — declared as OpenRegister registers + schemas with
`x-openregister-lifecycle` rules (per ADR-031), wired into
`src/manifest.json` (per ADR-024), and consuming OpenRegister's audit,
RBAC, and approval-workflow abstractions instead of reimplementing them
(per ADR-022). No PHP service classes, no custom database tables, no
Vue components — the entire bookkeeping foundation lands as register
metadata + manifest entries.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()`
repair-step seeding.

## Motivation

Shillinq's `adr-000-data-model.md` already enumerates 225 financial
entities including `GeneralLedgerAccount`, `GeneralLedgerEntry`,
`JournalEntry`, and `FiscalYear` (all marked
**Primary spec: financial-reporting-accountability**), but none of
those entities are yet declared in `lib/Settings/shillinq_register.json`
— the register file ships only a placeholder `example` schema. Every
downstream Shillinq capability (invoicing, accounts payable/receivable,
trial balance, period close, multi-currency, tax reporting, financial
reporting, treasury, procurement spend) depends on a balanced
double-entry ledger being present and trustworthy. Until the
foundation is laid, the rest of the rollout cannot start.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown. This change delivers **Tier 1**:
`add-shillinq-bookkeeping-foundation`.

T1 is intentionally narrow: a balanced general ledger with hierarchical
accounts and manual + recurring + reversing journals. Every downstream
tier (T2, T3, T4-base, T4-specialized — all in this PR) consumes this
surface.

## Affected Projects

- [x] Project: shillinq — adds 3 new registers/schemas to
  `lib/Settings/shillinq_register.json`, adds 2 manifest navigation
  entries in `src/manifest.json`, ships RGS seed templates in
  `lib/Settings/seeds/`
- [ ] Project: openregister — no source changes; this change consumes
  existing OR abstractions (audit, RBAC, `x-openregister-lifecycle`,
  approval workflow, attachments). If a needed extension is missing,
  it is filed as an OR issue and the gap recorded in `design.md`.
- [ ] Project: docudesk — no source changes; journal entries
  reference docudesk attachments by foreign-key URI per ADR-022.

## Scope

### In Scope

- Three new capability specs (`bookkeeping-chart-of-accounts`,
  `bookkeeping-general-ledger`, `bookkeeping-journal-entries`) — see
  the `specs/` folder.
- Hierarchical chart of accounts (assets / liabilities / equity /
  revenue / expenses, sub-accounts, account-level
  active/blocked/archived lifecycle, closing-account designation).
- RGS (Referentie Grootboek Schema) seed templates for three variants:
  SMB (`rgs-3.5-mkb.json`), ZZP (`rgs-3.5-zzp.json`), and
  government/BBV (`rgs-bbv.json`). Per-administration override allowed.
- Double-entry posting engine — every general-ledger posting is a
  balanced set of journal lines (sum of debits = sum of credits in
  the administration's base currency), enforced by an
  `x-openregister-lifecycle` rule on the GL transaction schema.
- Sub-ledger references (AP / AR / project) by FK only — T2 owns the
  sub-ledgers themselves.
- Opening-balances import as a journal-entry type with
  source-document FK to a docudesk attachment.
- Period-stamped postings — every GL line carries a `period_id`
  resolved at post-time against the active fiscal-period record.
- Manual journal entries (memoriaalboekingen) with multi-line balanced
  entries, recurring journals (depreciation / subscriptions /
  accruals), and reversing journals (auto-reversing on next period
  boundary).
- Approval-gate workflow consumed from OpenRegister's approval-workflow
  abstraction per ADR-022 — DO NOT reimplement.
- Manifest navigation entries (Bookkeeping > Chart of Accounts,
  Bookkeeping > General Ledger, Bookkeeping > Journals) using
  `type: index`/`type: detail` page renderers from
  `@conduction/nextcloud-vue` (Tier-4 manifest renderer adopted on
  `feature/adopt-app-manifest`).
- Audit trail consumed from OpenRegister's
  audit-trail-immutable abstraction per ADR-022 — DO NOT reimplement.

### Out of Scope

- **Implementation code** — this is a spec-only change. PHP services,
  Vue components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle on the spec.
- **Subsequent tiers (T2, T3, T4-base, T4-specialized)** ship in this
  same PR as separate changes; see
  [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
  for the breakdown:
  - T2 (`add-shillinq-bookkeeping-compliance`) — sub-ledgers + period
    machinery (AP/AR, trial balance, period close, financial
    statements, bank reconciliation, audit-trail + document-attachment
    integrations).
  - T3 (`add-shillinq-bookkeeping-operations`) — operations + NL
    regulatory core. **VAT/BTW filing ships here** as
    `bookkeeping-vat-btw-filing`; it is not part of T1's surface.
  - T4-base (`add-shillinq-bookkeeping-advanced`) — advanced engine
    features (SBR/XBRL, fixed assets, multi-currency translation,
    cost centers + dimensions, year-end close, bank connectors,
    reconciliation reports). The schemas in T1 carry a `currency`
    field but FX revaluation / CTA postings live in T4-base.
  - T4-specialized (`add-shillinq-gov-sector-mkb-advanced`) — NL gov
    sector variants + Vpb + innovation regimes + MKB R&D +
    detachering bridge.
- **Future T5 work** — UBL/Peppol BIS 3.0 outbound, intercompany
  eliminations, advanced group consolidation, treasury cash
  forecasting, IFRS rebridge, multi-administration aggregation —
  tracked separately and explicitly OUT of this PR.
- **Frontend Vue components** beyond what `CnIndexPage` /
  `CnDetailPage` from `@conduction/nextcloud-vue` already render
  generically from a register manifest. No bespoke Vue files in this
  spec.

## Approach

Three deltas, each adding ADDED Requirements to a brand-new spec:

1. **`bookkeeping-chart-of-accounts`** — declares the `Account`
   register (renaming the existing data-model entity `Account` to its
   bookkeeping role; the existing entry in `adr-000-data-model.md` is
   already aligned). Hierarchical via `parentAccountNumber`. Lifecycle
   `active → blocked → archived` declared as
   `x-openregister-lifecycle`. Seed templates loaded via
   `ConfigurationService::importFromApp()` during the repair step.
2. **`bookkeeping-general-ledger`** — declares the `GLTransaction`
   and `GLLine` registers (the existing data-model entry
   `GeneralLedgerEntry` is the per-line equivalent; this spec
   formalises the header/line split needed for balancing). The
   header carries balanced-state and posting-state lifecycle
   transitions; the balance constraint is an
   `x-openregister-lifecycle` precondition on `post`, not a PHP
   service check.
3. **`bookkeeping-journal-entries`** — declares the `JournalEntry`
   register as a higher-level human-authored construct that
   materialises one or more GL transactions on post. Sub-types:
   `manual` (memoriaal), `recurring` (with `cadence`), and
   `reversing` (auto-creates inverse on next period boundary).
   Approval gate consumed via the OpenRegister approval-workflow
   abstraction.

All three specs follow the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed for traceability
(`REQ-CoA-*`, `REQ-GL-*`, `REQ-JE-*`) and numbered starting at
REQ-001 within each capability.

## New Dependencies

None. This change consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35` (from
`shillinq-manifest-tier4`).

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 schemas
  (`Account`, `GLTransaction`, `GLLine`) and 1 supporting schema
  (`JournalEntry`); declares `x-openregister-lifecycle` rules on
  `GLTransaction` (balance + posting) and `JournalEntry` (approval).
- `lib/Settings/seeds/rgs-3.5-mkb.json`,
  `lib/Settings/seeds/rgs-3.5-zzp.json`,
  `lib/Settings/seeds/rgs-bbv.json` — new files, seed account
  templates. Imported via `ConfigurationService::importFromApp()` in
  the repair step.
- `src/manifest.json` — adds 3 navigation entries
  (Chart of Accounts, General Ledger, Journals) and 3 `type: index` +
  3 `type: detail` page entries.
- Repair step (`lib/Migration/Version*.php` or repair class) —
  reuses the existing register-import pattern; one additional step
  to seed the chosen RGS template per administration.
- No new PHP services. No new Vue components. No new controllers.

## Cross-Project Dependencies

- **OpenRegister** — depends on the following abstractions being
  stable: `x-openregister-lifecycle` (ADR-031), audit-trail-immutable
  (ADR-022), approval-workflow (ADR-022 + the `object-interactions`
  spec). If a needed shape is missing (e.g. cross-schema sum
  constraint inside a lifecycle precondition), the gap is filed as an
  OR issue and the relevant requirement is annotated in `design.md`
  under "Declarative-vs-imperative decision".
- **docudesk** — journal entries reference attachments by foreign-key
  URI for source documents. No code coupling — the FK is a plain
  string field validated by JSON Schema.

## Risks

### Risk 1: OpenRegister's lifecycle engine cannot express the cross-line balance constraint declaratively

**Severity**: Medium
**Mitigation**: The balance constraint requires summing
`GLLine.debit` and `GLLine.credit` rows that reference the parent
`GLTransaction` and asserting equality before allowing the
`post` transition. Whether this fits inside an
`x-openregister-lifecycle.requires` declaration depends on whether
the engine supports cross-schema aggregations in preconditions. If
not, ADR-031's exception path applies: a thin
`BookkeepingBalanceGuard::isBalanced(transactionId)` PHP guard is
called from the lifecycle's `requires` field. The guard is single-
method, no state, ~20 LOC. Document the gap as an OR issue. The
spec author resolves this during `opsx-ff` discovery, not during
`opsx-apply`.

### Risk 2: RGS template scope creep

**Severity**: Low
**Mitigation**: Ship the three named templates only (SMB / ZZP /
BBV). Industry-specific templates (housing corp, healthcare,
education) are explicitly out of scope; record them on the T2+
roadmap. Operators can extend a seeded template through normal
OpenRegister object edits — no template forking required.

### Risk 3: Tier-cascade — downstream specs blocked if T1 lands incomplete

**Severity**: Medium
**Mitigation**: T1 is the foundation; getting the schema shape wrong
forces a destructive migration later. Mitigation is rigorous spec
review (reviewer checks every requirement against the
data-model ADR and against existing competitor schemas — Exact, Twinfield,
AFAS, Yuki — captured in `design.md`'s Reuse Analysis). Acceptance
criterion: a competent bookkeeper can read the three specs and
confirm the model matches a real Dutch SMB chart-of-accounts +
double-entry posting flow without modification.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact because no implementation lands until
`opsx-apply` is run on the spec. After implementation (separate
cycle), rollback follows the standard pattern: revert the implementing
PR, run the repair step in down-direction (registers are
non-destructive — unused schemas remain queryable but unreferenced).
No data migration risk at the spec stage.

## Open Questions

1. **Cross-schema balance precondition** — see Risk 1. The
   `opsx-ff` design phase resolves whether the lifecycle engine
   supports the constraint or whether a `requires` PHP guard is
   needed. The spec itself is shape-neutral: `REQ-GL-005` mandates
   the balance invariant without prescribing implementation.
2. **RGS version pinning** — RGS 3.5 is the current SBR-compatible
   release as of 2026-05. If 4.x ships before T1 implementation,
   the seed file naming carries the version (`rgs-3.5-*.json`) so
   side-by-side coexistence is trivial.
3. **Closing-account designation cardinality** — exactly one
   closing account per accountType cluster, or per administration?
   `REQ-CoA-009` defines per-administration single closing account;
   confirm with the bookkeeper persona before `opsx-apply`.
