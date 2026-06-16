# Proposal: add-shillinq-r-d-subsidies-mkb

`kind: config` per ADR-032 — the centre of mass is a
`subsidieRegeling` enum overlay on the existing T3 `Subsidie`
register + per-regeling kostencategorieën constraints + declarative
voortgangsrapportage aggregations + per-regeling audit-pack
docudesk templates. No PHP service classes are authored.

## Summary

Introduce the **R&D subsidies (MIT / SBIR / EU Horizon / EFRO /
REACT-EU)** capability for Shillinq as one slice of the Tier
4-specialized rollout per `adr-001-bookkeeping-tier-roadmap.md`.
T3's `bookkeeping-subsidie-verantwoording` already provides the
generic subsidie register with budget bewaking, voortgangsrapportage,
and kostendossiers. Each R&D regeling has its own
**kostencategorieën** (e.g. EU Horizon's personnel /
subcontracting / other direct costs / indirect-25-percent) and
audit-trail eisen. This change declares a `subsidieRegeling` enum
overlay on `Subsidie`, declares per-regeling kostencategorieën
constraints via JSON Schema `oneOf`/`if-then`, declares per-regeling
voortgangsrapportage aggregations, registers per-regeling audit-
pack docudesk templates, and adds budget-monitoring warnings for
kostencategorie sub-maxima (e.g. Horizon indirect-25%).

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-subsidie-verantwoording`](../../specs/bookkeeping-subsidie-verantwoording/spec.md)
  — the T3 subsidie register the R&D regelingen overlay.

## Motivation

Without dedicated R&D-subsidie primitives, each regeling forces
hand-mapped kostendossiers + bespoke audit packs. Per the parent
envelope's design D11, each regeling is a clean overlay on the
existing subsidie register — enum + per-regeling kostencategorieën
constraint + per-regeling audit-pack template. The current scope
covers the four most common R&D regelingen in NL MKB (MIT, SBIR, EU
Horizon, EFRO/REACT-EU); additional regelingen extend the enum
without spec churn.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown.

## Affected Projects

- [x] Project: shillinq — adds `subsidieRegeling` enum on
  `Subsidie`, declares per-regeling kostencategorieën constraints,
  declares per-regeling voortgangsrapportage aggregations,
  registers per-regeling audit-pack docudesk templates, adds 1
  manifest navigation entry behind
  `featureFlags.mkb-r-d-subsidies`
- [ ] Project: openregister — no source changes
- [ ] Project: docudesk — registers per-regeling audit-pack +
  voortgangsrapportage templates

## Scope

### In Scope

- One new capability spec (`bookkeeping-r-d-subsidies-mkb`) — see
  the `specs/` folder.
- `subsidieRegeling: mit | sbir | eu-horizon | efro | react-eu |
  other` enum on `Subsidie`; `schema:Grant` annotation (with
  `schema:ResearchProject` materialisation when the subsidie funds
  R&D work).
- Per-regeling kostencategorieën constraints (JSON Schema
  `oneOf`/`if-then`):
  - MIT: `personnel`, `materials`, `external-services`,
    `equipment-depreciation`, `other-direct`.
  - SBIR: `personnel`, `materials`, `equipment-depreciation`,
    `other-direct`.
  - EU Horizon: `personnel`, `subcontracting`, `other-direct`,
    `indirect-25-percent`.
  - EFRO: `personnel`, `external-services`, `materials`,
    `equipment`, `other`, `indirect-flat-rate`.
  - REACT-EU: same as EFRO + `green-recovery`.
- Per-regeling voortgangsrapportage aggregations grouping
  `kostenpost` by `(kostencategorie, periodId)` filtered on the
  parent subsidie; rendered via per-regeling docudesk template
  (Horizon Periodic Report layout, MIT voortgangsrapport, etc.).
- Per-regeling audit-pack docudesk templates assembling from
  OR audit-trail-immutable + kostendossier + external attachments.
- Per-regeling budget monitoring `x-openregister-calculations`
  surfacing ≥90% warning when a kostencategorie sub-max approaches
  (e.g. Horizon indirect-25%).
- Manifest navigation entry (Bookkeeping > R&D Subsidies) behind
  `featureFlags.mkb-r-d-subsidies` with `type: index` per regeling
  + `type: detail` per subsidie.

### Out of Scope

- **Implementation code** — spec-only change.
- **Subsidie register itself** — owned by T3
  `bookkeeping-subsidie-verantwoording`.
- **SiSa specifieke uitkeringen** — owned by sibling
  `add-shillinq-sisa-reporting`.
- **Frontend Vue components** beyond `CnIndexPage` /
  `CnDetailPage`.

## Approach

One delta, adding ADDED Requirements to a brand-new spec
(`bookkeeping-r-d-subsidies-mkb`). Each requirement is prefixed
`REQ-RDS-*`. RFC 2119 keywords; `#### Scenario:` with
GIVEN/WHEN/THEN.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds `subsidieRegeling`
  enum on `Subsidie`; declares per-regeling kostencategorieën
  constraints; declares voortgangsrapportage aggregations +
  budget-monitoring calculations.
- `src/manifest.json` — adds 1 navigation entry +
  `type: index` + `type: detail` pages behind
  `featureFlags.mkb-r-d-subsidies`.
- `lib/Settings/docudesk-templates.json` — registers per-regeling
  audit-pack + voortgangsrapportage templates.
- No new PHP services. No new Vue components.

## Cross-Project Dependencies

- **OpenRegister** — consumes `x-openregister-aggregations`,
  `x-openregister-calculations`, audit-trail-immutable.
- **docudesk** — per-regeling templates (audit-pack + voortgangs).

## Risks

### Risk 1: New R&D regelingen emerge

**Severity**: Low
**Mitigation**: `subsidieRegeling` is an enum; adding a new value
+ kostencategorieën + template is the only required work — no
spec churn.

### Risk 2: Per-regeling kostencategorieën evolve

**Severity**: Low
**Mitigation**: Allowed categorieën live in the schema's `oneOf`/
`if-then` rules; updates are JSON-Schema edits, NOT code changes.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the
change folder. Post-implementation rollback follows the standard
additive-overlay pattern.

## Open Questions

1. **Cumulative `other` category bucket** — currently MIT allows
   `other-direct`; SBIR same. Confirm with R&D subsidie reviewer
   whether the `other` bucket should be split per kostentype.
