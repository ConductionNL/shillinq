# Proposal: add-shillinq-investeringsaftrek

`kind: config` per ADR-032 — the centre of mass is an
`InvesteringClassifier` overlay on `FixedAsset` + a year-versioned
tarieven seed + declarative aftrek calculations + an RvO
aanvraagdossier docudesk template + RvO openconnector source rows.
No PHP service classes are authored (a conditional ~20-LOC PHP guard
applies if the calculation engine cannot express KIA-schalen lookup).

## Summary

Introduce the **investeringsaftrek (KIA / EIA / MIA / Vamil)**
capability for Shillinq as one slice of the Tier 4-specialized
rollout per `adr-001-bookkeeping-tier-roadmap.md`. The four
investeringsaftrek regelingen are computed from the existing
T4-base `FixedAsset` register: KIA (kleinschaligheid) flat-rate
with threshold/rampup/maximum, EIA (energie) 40% on
Energielijst-codes, MIA (milieu) 13.5/27/36% per Milieulijst
category, Vamil (vrije afschrijving) up to 75% in year 1. This
change adds an `InvesteringClassifier` overlay (with cumulative
multi-regime support), ships an annual tarieven seed, declares the
aftrek calculation on `FixedAsset`, registers the RvO
aanvraagdossier template + mededeling-feed openconnector sources,
and adds the manifest navigation entry.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-fixed-assets-depreciation`](../../specs/bookkeeping-fixed-assets-depreciation/spec.md)
  — the T4-base FixedAsset register the overlay attaches to.

## Motivation

Without dedicated investeringsaftrek primitives, the four regelingen
require either spreadsheet computation against the annual RvO
schalen or a separate product. Per the parent envelope's design D9,
the regelingen are clean overlays on `FixedAsset` with the
calculation rules + tarieven living in a year-versioned seed file.
The RvO aanvraagdossier + mededeling roundtrip rides docudesk +
openconnector per ADR-019 + ADR-022 — no app-local RvO client.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown.

## Affected Projects

- [x] Project: shillinq — adds 1 overlay schema
  (`InvesteringClassifier`), ships
  `lib/Settings/seeds/investeringsaftrek-tarieven-2026.json`,
  declares the aftrek calculations on `FixedAsset`, registers
  RvO aanvraagdossier docudesk template, registers 2 RvO
  openconnector source rows (aanvraag + mededeling), adds 1
  manifest navigation entry behind
  `featureFlags.mkb-investeringsaftrek`
- [ ] Project: openregister — no source changes (conditional
  ~20-LOC PHP guard for KIA-schalen lookup if engine-limited)
- [ ] Project: docudesk — registers aanvraagdossier template
- [ ] Project: openconnector — registers RvO aanvraag +
  mededeling sources

## Scope

### In Scope

- One new capability spec (`bookkeeping-investeringsaftrek`) —
  see the `specs/` folder.
- `InvesteringClassifier` overlay register (with `schema:Thing`
  annotation) on `FixedAsset` with `aftrekType` enum (`kia | eia
  | mia | vamil`), `bedrijfsmiddelCode`, `aanvraagDatum` (optional
  — required for EIA/MIA/Vamil), `aanvraagNummer` (post-award),
  `toegekendBedrag` (post-award). An asset MAY carry multiple
  classifiers cumulatively.
- Aftrek calculations as `x-openregister-calculations` on
  `FixedAsset` reading the seeded tarieven (KIA threshold/rampup/
  maximum, EIA 40%, MIA 13.5/27/36% per category, Vamil up to 75%).
- `lib/Settings/seeds/investeringsaftrek-tarieven-2026.json` seed
  with the 2026 schalen for all four regelingen; SPDX in docblock;
  `_meta` block (`source: 'RvO investeringsaftrek-regelingen'`,
  `year: 2026`).
- RvO aanvraagdossier docudesk template (for EIA/MIA/Vamil; KIA
  requires no separate aanvraag).
- 2 openconnector source rows for RvO (aanvraag submission +
  mededeling feed); `toegekendBedrag` populated asynchronously from
  the mededeling.
- Audit-trail event on every award update.
- Manifest navigation entry (Bookkeeping > Investeringsaftrek)
  behind `featureFlags.mkb-investeringsaftrek` with `type: index`
  of classifiers + `type: detail` per classifier (asset link,
  aanvraag, toekenning, aftrek impact).

### Out of Scope

- **Implementation code** — spec-only change.
- **FixedAsset register itself** — owned by T4-base
  `bookkeeping-fixed-assets-depreciation`.
- **Frontend Vue components** beyond `CnIndexPage` /
  `CnDetailPage`.

## Approach

One delta, adding ADDED Requirements to a brand-new spec
(`bookkeeping-investeringsaftrek`). Each requirement is prefixed
`REQ-INV-*`. RFC 2119 keywords; `#### Scenario:` with
GIVEN/WHEN/THEN.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — declares
  `InvesteringClassifier` overlay register; declares the aftrek
  calculations on `FixedAsset`.
- `lib/Settings/seeds/investeringsaftrek-tarieven-2026.json` —
  new file (~100 records), SPDX in docblock, `_meta` block.
- `src/manifest.json` — adds 1 navigation entry +
  `type: index` + `type: detail` pages behind
  `featureFlags.mkb-investeringsaftrek`.
- `lib/Settings/docudesk-templates.json` — registers
  aanvraagdossier template.
- `lib/Settings/openconnector-sources.json` — 2 RvO source rows.
- No new PHP services (conditional ~20-LOC PHP guard for
  KIA-schalen lookup if engine-limited).

## Cross-Project Dependencies

- **OpenRegister** — consumes `x-openregister-calculations` for
  the aftrek formulas. If the engine cannot express
  threshold/rampup/maximum lookup, the ADR-031 exception path
  applies (a single-method ~20-LOC `KiaSchalenLookup`).
- **docudesk** — aanvraagdossier template.
- **openconnector** — RvO aanvraag + mededeling sources per
  ADR-019.

## Risks

### Risk 1: RvO schalen revise yearly

**Severity**: Low
**Mitigation**: Seed filename version-pinned
(`investeringsaftrek-tarieven-2026.json` → `-2027.json`); spec
references regulation, not values. Operator switches active seed
per fiscal year.

### Risk 2: KIA-schalen lookup engine-limited

**Severity**: Low
**Mitigation**: Single-method ~20-LOC `KiaSchalenLookup` per
ADR-031 exception path; documented in the implementing cycle's
design doc.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the
change folder. Post-implementation rollback follows the standard
additive-register pattern.

## Open Questions

1. **Cumulative KIA + EIA / MIA / Vamil rule clarity** — RvO
   permits some combinations cumulatively; per REQ-INV-001 the
   classifier allows multiple per asset. Confirm with the RvO
   reviewer persona which combinations are valid per 2026 schalen
   before `opsx-apply`.
