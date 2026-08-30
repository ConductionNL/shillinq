# Proposal: add-shillinq-waterschappen-bbv-variant

`kind: config` per ADR-032 — the centre of mass is a `bbvVariant`
overlay flag + a thin sector-specific posting register + a year-
versioned seed file. No PHP service classes are authored.

## Summary

Introduce the **BBV-Waterschappen variant** capability for Shillinq
as one slice of the Tier 4-specialized rollout per
`adr-001-bookkeeping-tier-roadmap.md`. Waterschappen use the same
BBV regulatory framework as gemeenten, with a different programma /
kostentoedeling structure, sector-specific belastingen
(watersysteem-, zuiverings-, verontreinigingsheffing), and a
sector-specific EMU-saldo exclusion ruleset. This change declares
the variant as a `bbvVariant: 'waterschap'` overlay on the existing
T3 `bookkeeping-bbv-compliance` register plus a
`WaterschapHeffingPosting` register for the three sector-specific
heffingen, wires the navigation into `src/manifest.json` per ADR-024
behind a `featureFlags.gov-waterschap` flag, and ships
`bbv-waterschappen-programmas-2026.json` loaded via
`ConfigurationService::importFromApp()` per ADR-022.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-bbv-compliance`](../../specs/bookkeeping-bbv-compliance/spec.md)
  — the BBV-base register that the waterschap variant overlays.

## Motivation

Without a waterschap-aware overlay, every BBV-compliant
waterschap has to bolt on a separate product for sector-specific
programma-rollups, heffing-postings, and EMU exclusion handling.
The variant flag fits cleanly under ADR-031's "declare the variation
as schema metadata" rule — 80% of the BBV surface is shared with
gemeenten; only programma structure, three heffing postings, and the
EMU exclusions are sector-specific. The alternative (forking three
BBV specs) was rejected per the parent envelope's design D1:
80% overlap, drift risk, three-times the review surface for one
regulatory framework.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown.

## Affected Projects

- [x] Project: shillinq — adds 1 schema (`WaterschapHeffingPosting`),
  adds a `bbvVariant` flag on the existing BBV-base schemas,
  extends `src/manifest.json` with 1 feature-flag-controlled
  navigation entry (`featureFlags.gov-waterschap`), ships
  `lib/Settings/seeds/bbv-waterschappen-programmas-2026.json`
- [ ] Project: openregister — no source changes; consumes existing
  abstractions (`x-openregister-aggregations`,
  `x-openregister-lifecycle`, audit-trail-immutable)

## Scope

### In Scope

- One new capability spec
  (`bookkeeping-waterschappen-bbv-variant`) — see the `specs/`
  folder.
- `bbvVariant: gemeente | waterschap | provincie` overlay enum on
  `Account` and `BBVProgramma` (default `gemeente`).
- `programmaStructure: taakveld | kostentoedeling` discriminator
  on `BBVProgramma`; waterschap variant uses `kostentoedeling`.
- `WaterschapHeffingPosting` register with `heffingType` enum
  (`watersysteemheffing | zuiveringsheffing |
  verontreinigingsheffing`) materialising balanced
  `GLTransaction` records.
- `emuExclusionRule` field on `WaterschapHeffingPosting` honoured
  by the EMU-saldo aggregation declared in
  `bookkeeping-emu-reporting`.
- `bbv-waterschappen-programmas-2026.json` seed loaded via
  `ConfigurationService::importFromApp()` repair step.
- Manifest navigation entry (Bookkeeping > Waterschapsbelastingen)
  behind `featureFlags.gov-waterschap`, using `type: index` /
  `type: detail` page renderers from `@conduction/nextcloud-vue`.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes land via a
  separate `opsx-apply` cycle.
- **Provincie variant** — owned by sibling
  `add-shillinq-provincies-bbv-variant`.
- **EMU computation itself** — owned by sibling
  `add-shillinq-emu-reporting`; this spec only declares the per-
  heffing `emuExclusionRule` flag the EMU aggregation reads.
- **Frontend Vue components** beyond what `CnIndexPage` /
  `CnDetailPage` from `@conduction/nextcloud-vue` already render.

## Approach

One delta, adding ADDED Requirements to a brand-new spec
(`bookkeeping-waterschappen-bbv-variant`) declaring the variant
overlay, the `WaterschapHeffingPosting` register, the seed file,
and the manifest entry. Each requirement is prefixed `REQ-WSB-*`.
RFC 2119 keywords; `#### Scenario:` with GIVEN/WHEN/THEN.

## New Dependencies

None. This change consumes existing OpenRegister abstractions and
the already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 schema
  (`WaterschapHeffingPosting`); adds `bbvVariant` enum field on
  `Account` and `BBVProgramma`; adds `programmaStructure`
  discriminator on `BBVProgramma`; adds `emuExclusionRule` on
  `WaterschapHeffingPosting`.
- `lib/Settings/seeds/bbv-waterschappen-programmas-2026.json` —
  new file, ~30 records, SPDX header inside docblock, `_meta`
  block (`source: 'BBVW handleiding'`, `year: 2026`).
- `src/manifest.json` — adds 1 navigation entry +
  `type: index` + `type: detail` pages behind
  `featureFlags.gov-waterschap`.
- Repair step — extends the existing register-import pattern to
  seed the waterschap programmas when the feature flag is on.
- No new PHP services. No new Vue components. No new controllers.

## Cross-Project Dependencies

- **OpenRegister** — consumes `x-openregister-aggregations`,
  `x-openregister-lifecycle`, audit-trail-immutable. No new OR
  features required.

## Risks

### Risk 1: BBVW handleiding revisions yearly

**Severity**: Low
**Mitigation**: Seed filename version-pins
(`bbv-waterschappen-programmas-2026.json` →
`bbv-waterschappen-programmas-2027.json`). Spec references the
regulation, not year-specific values.

### Risk 2: EMU exclusion ruleset drift between waterschappen handleiding and shillinq defaults

**Severity**: Low
**Mitigation**: `emuExclusionRule` field defaults match the 2026
BBVW handleiding; operator may override per posting. Re-validated
yearly with the BBVW reviewer persona.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the
change folder; no runtime impact (no implementation lands until
`opsx-apply`). Post-implementation rollback follows the standard
additive-register pattern — revert the implementing PR; existing
records remain queryable but unreferenced.

## Open Questions

1. **EMU exclusion default for verontreinigingsheffing** —
   `REQ-WSB-005` proposes `excluded` per the BBVW handleiding;
   confirm with the BBVW reviewer persona before `opsx-apply`.
