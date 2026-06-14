# Proposal: add-shillinq-chart-of-accounts

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries + RGS seed data. No PHP service
classes are authored.

## Summary

Introduce the **hierarchical chart of accounts** capability for Shillinq
as the first slice of the bookkeeping foundation (Tier 1 of the 5-tier
bookkeeping rollout per `adr-001-bookkeeping-tier-roadmap.md`). This
change declares the `Account` register with
`x-openregister-lifecycle` rules (per ADR-031), wires the navigation
into `src/manifest.json` (per ADR-024), and ships three RGS
(Referentie Grootboek Schema) seed templates loaded via
`ConfigurationService::importFromApp()` (per ADR-022). No PHP service
classes, no custom database tables, no Vue components — the entire
capability lands as register metadata + manifest entries + seed JSON.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()`
repair-step seeding.

## Motivation

Shillinq's `adr-000-data-model.md` already enumerates `Account` and
`GeneralLedgerAccount` as foundational entities, but neither is yet
declared in `lib/Settings/shillinq_register.json` — the register file
ships only a placeholder `example` schema. Every downstream Shillinq
capability (general ledger postings, journal entries, sub-ledgers,
trial balance, financial reporting) depends on a hierarchical, RGS-
conformant chart of accounts being present. Until the chart of accounts
is laid down, no other Tier 1 capability can start.

This proposal is the **first of three sibling changes** that together
constitute the Tier 1 bookkeeping foundation:

1. `add-shillinq-chart-of-accounts` (this change) — foundation, depends
   on nothing.
2. `add-shillinq-general-ledger` — depends on this change.
3. `add-shillinq-journal-entries` — depends on both above.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown.

## Affected Projects

- [x] Project: shillinq — adds 1 new register/schema (`Account`) to
  `lib/Settings/shillinq_register.json`, adds 1 manifest navigation
  entry in `src/manifest.json`, ships 3 RGS seed templates in
  `lib/Settings/seeds/`
- [ ] Project: openregister — no source changes; this change consumes
  existing OR abstractions (audit, RBAC, `x-openregister-lifecycle`,
  `x-openregister-relations`)

## Scope

### In Scope

- One new capability spec (`bookkeeping-chart-of-accounts`) — see the
  `specs/` folder.
- Hierarchical chart of accounts (assets / liabilities / equity /
  revenue / expenses, sub-accounts via self-relation, account-level
  active/blocked/archived lifecycle, closing-account designation).
- RGS (Referentie Grootboek Schema) seed templates for three variants:
  SMB (`rgs-3.5-mkb.json`), ZZP (`rgs-3.5-zzp.json`), and
  government/BBV (`rgs-bbv.json`). Per-administration override allowed.
- Manifest navigation entry (Bookkeeping > Chart of Accounts) using
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
- **General ledger postings + journal entries** — owned by sibling
  changes `add-shillinq-general-ledger` and
  `add-shillinq-journal-entries`.
- **Industry-specific RGS templates** (housing corp, healthcare,
  education) — record on T2+ roadmap.
- **Frontend Vue components** beyond what `CnIndexPage` /
  `CnDetailPage` from `@conduction/nextcloud-vue` already render
  generically from a register manifest. No bespoke Vue files in this
  spec.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-chart-of-accounts`** — declares the `Account` register
(renaming the existing data-model entity `Account` to its bookkeeping
role; the existing entry in `adr-000-data-model.md` is already
aligned). Hierarchical via `parentAccountNumber`. Lifecycle
`active → blocked → archived` declared as
`x-openregister-lifecycle`. Seed templates loaded via
`ConfigurationService::importFromApp()` during the repair step.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-CoA-*` for
traceability.

## New Dependencies

None. This change consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35` (from
`shillinq-manifest-tier4`).

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 schema (`Account`);
  declares `x-openregister-lifecycle` on `Account` and
  `x-openregister-relations` self-relation on `parentAccountNumber`.
- `lib/Settings/seeds/rgs-3.5-mkb.json`,
  `lib/Settings/seeds/rgs-3.5-zzp.json`,
  `lib/Settings/seeds/rgs-bbv.json` — new files, seed account
  templates. Imported via `ConfigurationService::importFromApp()` in
  the repair step.
- `src/manifest.json` — adds 1 navigation entry
  (Chart of Accounts) and 1 `type: index` + 1 `type: detail` page entry.
- Repair step (`lib/Migration/Version*.php` or repair class) —
  reuses the existing register-import pattern; one additional step
  to seed the chosen RGS template per administration.
- No new PHP services. No new Vue components. No new controllers.

## Cross-Project Dependencies

- **OpenRegister** — depends on the following abstractions being
  stable: `x-openregister-lifecycle` (ADR-031), audit-trail-immutable
  (ADR-022), `x-openregister-relations` self-relation. No new OR
  features required for this change.

## Risks

### Risk 1: RGS template scope creep

**Severity**: Low
**Mitigation**: Ship the three named templates only (SMB / ZZP /
BBV). Industry-specific templates (housing corp, healthcare,
education) are explicitly out of scope; record them on the T2+
roadmap. Operators can extend a seeded template through normal
OpenRegister object edits — no template forking required.

### Risk 2: Account-hierarchy shape locks downstream

**Severity**: Medium
**Mitigation**: General ledger and journal entries (sibling changes)
both FK into `Account.accountNumber`. Getting the field name and
typing wrong forces a destructive migration later. Mitigation is
rigorous spec review against the data-model ADR and against existing
competitor schemas (Exact, Twinfield, AFAS, Yuki — captured in
`design.md`'s Reuse Analysis). Acceptance criterion: a competent
bookkeeper can read the spec and confirm the model matches a real
Dutch SMB chart-of-accounts.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact because no implementation lands until
`opsx-apply` is run on the spec. After implementation (separate
cycle), rollback follows the standard pattern: revert the implementing
PR, run the repair step in down-direction (registers are
non-destructive — unused schemas remain queryable but unreferenced).
No data migration risk at the spec stage.

## Open Questions

1. **RGS version pinning** — RGS 3.5 is the current SBR-compatible
   release as of 2026-05. If 4.x ships before implementation, the seed
   file naming carries the version (`rgs-3.5-*.json`) so side-by-side
   coexistence is trivial.
2. **Closing-account designation cardinality** — exactly one
   closing account per accountType cluster, or per administration?
   `REQ-CoA-009` defines per-administration single closing account;
   confirm with the bookkeeper persona before `opsx-apply`.
