# Proposal: add-shillinq-provincies-bbv-variant

`kind: config` per ADR-032 — the centre of mass is the same
`bbvVariant` overlay flag (declared by the waterschap variant)
extended with `'provincie'`, plus a thin
`ProvincialeFondsPosting` register, an `opcentenTarief` field on
`GLLine`, and a year-versioned kerntaken seed. No PHP service
classes are authored.

## Summary

Introduce the **provinciale BBV-variant** capability for Shillinq as
one slice of the Tier 4-specialized rollout per
`adr-001-bookkeeping-tier-roadmap.md`. Provincies use the BBV
framework with kerntaken (ruimte, mobiliteit, water, milieu,
cultuur, economie, bestuur) instead of gemeente-taakvelden, plus
sector-specific provinciale-fonds boekingen (provinciefonds,
algemene uitkering, decentralisatie-uitkering,
integratie-uitkering) and opcenten op de motorrijtuigenbelasting.
This change declares the variant as a `bbvVariant: 'provincie'`
overlay plus a `ProvincialeFondsPosting` register, an
`opcentenTarief` field on `GLLine`, the kerntaken seed, and the
manifest entry behind `featureFlags.gov-provincie`.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-bbv-compliance`](../../specs/bookkeeping-bbv-compliance/spec.md)
  — the BBV-base register that the provincie variant overlays.

## Motivation

The provinciale BBV-variant differs from gemeenten in three concrete
ways: the seven canonical kerntaken replace the gemeente-taakvelden,
provinciale fondsen are a distinct posting type, and opcenten MRB
require per-provincie tariefopslag. Without these, a provincie
running shillinq would have to bolt on a separate product for
fonds-boekingen and kerntaken-rollups. Per the parent envelope's
design D1, the variant flag fits cleanly under ADR-031's "declare
the variation as schema metadata" rule — no fork of three BBV specs.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown.

## Affected Projects

- [x] Project: shillinq — adds 1 schema (`ProvincialeFondsPosting`),
  extends `bbvVariant` enum to include `'provincie'`, extends
  `programmaStructure` discriminator to include `'kerntaak'`, adds
  `opcentenTarief` field on `GLLine`, ships
  `lib/Settings/seeds/bbv-provincies-kerntaken-2026.json`, adds
  1 navigation entry behind `featureFlags.gov-provincie`
- [ ] Project: openregister — no source changes

## Scope

### In Scope

- One new capability spec (`bookkeeping-provincies-bbv-variant`) —
  see the `specs/` folder.
- `'provincie'` value on the `bbvVariant` overlay (declared by
  sibling `add-shillinq-waterschappen-bbv-variant`).
- `'kerntaak'` value on the `programmaStructure` discriminator
  (declared by the same sibling).
- `ProvincialeFondsPosting` register with `fondsType` enum
  (provinciefonds / algemene-uitkering / decentralisatie-uitkering /
  integratie-uitkering) materialising balanced `GLTransaction`
  records.
- `opcentenTarief` field on `GLLine` for MRB-opcenten posting.
- `bbv-provincies-kerntaken-2026.json` seed loaded via
  `ConfigurationService::importFromApp()` (the seven canonical
  kerntaken with RGS-aligned account sub-trees).
- Manifest navigation entry (Bookkeeping > Provinciale fondsen)
  behind `featureFlags.gov-provincie`.

### Out of Scope

- **Implementation code** — spec-only change.
- **Waterschap variant** — owned by sibling
  `add-shillinq-waterschappen-bbv-variant`; this spec extends the
  variant enum that sibling declares.
- **Frontend Vue components** beyond what `CnIndexPage` /
  `CnDetailPage` already render.

## Approach

One delta, adding ADDED Requirements to a brand-new spec
(`bookkeeping-provincies-bbv-variant`) declaring the variant value,
the `ProvincialeFondsPosting` register, the opcenten tarief field,
the kerntaken seed, and the manifest entry. Each requirement is
prefixed `REQ-PRB-*`. RFC 2119 keywords; `#### Scenario:` with
GIVEN/WHEN/THEN.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the bumped
`@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 schema
  (`ProvincialeFondsPosting`); extends the `bbvVariant` enum to
  accept `'provincie'`; extends `programmaStructure` to accept
  `'kerntaak'`; adds `opcentenTarief` field on `GLLine`.
- `lib/Settings/seeds/bbv-provincies-kerntaken-2026.json` — new
  file (~15 records), SPDX header inside docblock, `_meta` block
  (`source: 'Provinciale handleiding BBV'`, `year: 2026`).
- `src/manifest.json` — adds 1 navigation entry +
  `type: index` + `type: detail` pages behind
  `featureFlags.gov-provincie`.
- Repair step — seeds the kerntaken when the feature flag is on.
- No new PHP services. No new Vue components.

## Cross-Project Dependencies

- **OpenRegister** — consumes `x-openregister-aggregations`,
  `x-openregister-lifecycle`, audit-trail-immutable.

## Risks

### Risk 1: Provinciale BBV handleiding revisions yearly

**Severity**: Low
**Mitigation**: Seed filename version-pinned. Spec references
regulation, not year-specific values.

### Risk 2: Opcenten tariefopslag varies per provincie per period

**Severity**: Low
**Mitigation**: `opcentenTarief` is a per-line field — operators
set the actual tarief per posting; no static lookup table.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the
change folder. Post-implementation rollback follows the standard
additive-register pattern.

## Open Questions

1. **Provinciefonds boekings-account-cluster** — confirm with the
   BBV-reviewer persona before `opsx-apply` whether the fondsen
   should land on a separate sub-cluster or on the
   algemene-dekkingsmiddelen rollup.
