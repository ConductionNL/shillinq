# Proposal: add-shillinq-fixed-assets-depreciation

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries. No PHP service classes are
authored.

## Summary

Introduce the **fixed-assets & depreciation** capability for Shillinq
as part of the Tier 4 advanced bookkeeping engine (per
`adr-001-bookkeeping-tier-roadmap.md`). This change declares the
`FixedAsset` register with `x-openregister-calculations` derived fields
(`currentBookValue`, `monthlyDepreciation`, `commercialBookValue`,
`fiscalBookValue`) per ADR-031, parallel commercial / fiscal streams
(Wet IB / Wet VPB divergence), disposal as a declarative lifecycle
transition, monthly depreciation as an OR `ScheduledWorkflow`, and
wires the navigation into `src/manifest.json` (per ADR-024). No
materialised schedule table, no PHP `DepreciationService`, no bespoke
Vue components.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

## Motivation

Almost every administration capitalises assets; depreciation drives
both commercial books (IFRS / Dutch GAAP) and fiscal books (Wet IB /
Wet VPB) which routinely diverge (e.g. Wet IB's 20% fiscal cap on
certain asset classes). Without first-class fixed-asset accounting,
Shillinq cannot serve administrations with material capitalised assets
— which is most of them.

This proposal is one of seven sibling Tier 4 capability changes
extracted from the bundled `add-shillinq-bookkeeping-advanced` proposal
to satisfy ADR-032 spec-sizing (cap: 20 unchecked tasks per change).

## Affected Projects

- [x] Project: shillinq — adds 1 new register/schema (`FixedAsset`) to
  `lib/Settings/shillinq_register.json`, adds 1 manifest navigation
  entry in `src/manifest.json`.
- [ ] Project: openregister — no source changes; this change consumes
  existing OR abstractions (`x-openregister-calculations`,
  `x-openregister-lifecycle`, audit-trail-immutable, RBAC,
  `ScheduledWorkflow`).
- [ ] Project: docudesk — fixed-asset acquisition documents referenced
  by docudesk attachment URI per ADR-022.

## Scope

### In Scope

- One new capability spec (`bookkeeping-fixed-assets-depreciation`) —
  see the `specs/` folder.
- `FixedAsset` register declaration with `proposed → active → disposed
  → archived` lifecycle.
- Depreciation derived fields (`monthlyDepreciation`,
  `currentBookValue`, `commercialBookValue`, `fiscalBookValue`) as
  `x-openregister-calculations` per ADR-031.
- Parallel commercial / fiscal streams posting to dedicated sub-account
  or `bookSet` dimension.
- Disposal as a declarative `x-openregister-lifecycle` action emitting
  a closing `JournalEntry`.
- Monthly depreciation run as an OR `ScheduledWorkflow` (ADR-031 path
  2).
- Manifest navigation entry (Bookkeeping > Fixed Assets) using
  `type: index` / `type: detail` renderers from
  `@conduction/nextcloud-vue`.

### Out of Scope

- **Implementation code** — this is a spec-only change. PHP services,
  Vue components, controllers, tests, and CI changes are deliberately
  not in this proposal; the implementation lands via a separate
  `opsx-apply` cycle on the spec.
- **Materialised `DepreciationSchedule` table per asset** — explicitly
  rejected per design D2; depreciation is a derived field.
- **Frontend Vue components** beyond what `CnIndexPage` /
  `CnDetailPage` from `@conduction/nextcloud-vue` already render
  generically.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-fixed-assets-depreciation`** — declares the `FixedAsset`
register with parallel commercial/fiscal calculated fields, lifecycle
disposal transition, and monthly scheduled depreciation workflow.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-FA-*` for
traceability.

## New Dependencies

None. This change consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 schema (`FixedAsset`)
  with `x-openregister-lifecycle` and `x-openregister-calculations`.
- `src/manifest.json` — adds 1 navigation entry (Fixed Assets) and
  matching `type: index` + `type: detail` pages.
- One new `ScheduledWorkflow` record (monthly depreciation) registered
  with OR.
- No new PHP services. No new Vue components. No new controllers. No
  new TimedJobs.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-calculations`,
  `x-openregister-lifecycle`, audit-trail-immutable, RBAC, and
  `ScheduledWorkflow` being stable.
- **docudesk** — acquisition documents referenced by attachment URI.

## Risks

### Risk 1: Fixed-asset commercial / fiscal divergence (Wet IB / Wet VPB) edge cases

**Severity**: Medium
**Mitigation**: REQ-FA-004 mandates parallel streams with separate
postings (sub-account or `bookSet` dimension); this matches Exact /
AFAS / Twinfield practice. The implementing cycle's review MUST
include a competent Dutch bookkeeper persona confirming the divergent
rate behaviour against a real Wet IB depreciation rule (e.g. the 20%
fiscal cap on certain asset classes).

### Risk 2: Asset-hierarchy edge cases (parent/child capitalisations) not covered

**Severity**: Low
**Mitigation**: T4 spec keeps `FixedAsset` flat (no parent/child
hierarchy); group-asset modelling (e.g. a server rack containing
multiple capitalised disks) is operator-modeled through separate asset
records. If a real use case emerges, the schema additively gains a
`parentAssetNumber` relation without breaking existing assets.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact because no implementation lands until
`opsx-apply` is run on the spec. After implementation (separate
cycle), rollback follows the standard pattern: revert the implementing
PR, run the repair step in down-direction (registers are non-
destructive — unused schemas remain queryable but unreferenced).

## Open Questions

1. **Bookkeeper-persona review for fixed-asset Wet IB rules** —
   scheduled before the implementing PR merges; persona
   `/test-persona-janwillem` (SMB owner) plus a domain-expert
   accountant review.
