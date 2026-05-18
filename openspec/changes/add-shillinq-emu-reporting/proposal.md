# Proposal: add-shillinq-emu-reporting

`kind: config` per ADR-032 — the centre of mass is an `esaClassifier`
overlay on `Account`, an ESA-2010 seed file, plus declarative
aggregations + calculation metadata for EMU-saldo / EMU-schuld. No
PHP service classes are authored (a single ~20-LOC ADR-031 exception
guard is conditional on engine-limited multi-sector filter
expressivity).

## Summary

Introduce the **EMU reporting** capability for Shillinq as one slice
of the Tier 4-specialized rollout per
`adr-001-bookkeeping-tier-roadmap.md`. EMU-saldo
(vorderingsoverschot / -tekort) and EMU-schuld MUST be computed per
the ESA 2010 (European System of Accounts) conventions, as adapted
for Dutch decentrale overheden via the BBV handleiding EMU-saldo.
This change declares an `esaClassifier` enum field on `Account`
(S.1311 / S.1312 / S.1313 / S.1314 / S.11 / S.12 / S.13 / S.14 /
S.15 / S.2), ships a `esa-2010-classifier.json` seed, declares the
quarterly + annual EMU computations as aggregations, and exposes
per-sector inclusion/exclusion rules as calculation metadata (the
field `emuInclusionRule` lives on sector-specific postings declared
by the waterschap sibling).

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-bbv-compliance`](../../specs/bookkeeping-bbv-compliance/spec.md)
  — supplies the BBV-base register the EMU computation projects from.
- [`bookkeeping-iv3-reporting`](../../specs/bookkeeping-iv3-reporting/spec.md)
  — supplies the IV3 quarterly aggregation the quarterly EMU
  computation rides.

## Motivation

EMU reporting is a BZK obligation for every decentrale overheid.
Without dedicated primitives, the EMU-saldo / EMU-schuld computation
either lives in spreadsheets or in a separate product. Per ADR-031,
the computation is declarative — an aggregation summing `(debit -
credit)` grouped by ESA-2010 sector classifier, with per-sector
inclusion/exclusion rules as schema metadata, reading from existing
GL data. Per the parent envelope's design D4, the EMU computation
is reproducible from the audit-trail — every run cites which
periods + sectors + exclusion rules contributed.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown.

## Affected Projects

- [x] Project: shillinq — adds `esaClassifier` enum field on
  `Account`, ships `lib/Settings/seeds/esa-2010-classifier.json`,
  declares quarterly + annual EMU aggregations + calculation
  metadata, adds 1 manifest navigation entry behind
  `featureFlags.gov-emu`
- [ ] Project: openregister — no source changes (unless engine
  cannot express multi-sector filter; then a thin ~20-LOC PHP
  guard per ADR-031 exception path)

## Scope

### In Scope

- One new capability spec (`bookkeeping-emu-reporting`) — see the
  `specs/` folder.
- `esaClassifier` enum field on `Account` with values per ESA 2010
  (S.1311 = centrale overheid, S.1313 = lokale overheid, etc.).
- `lib/Settings/seeds/esa-2010-classifier.json` seed loaded via
  `ConfigurationService::importFromApp()`; SPDX header + `_meta`
  block.
- Quarterly EMU aggregation (riding IV3 data) + annual EMU
  aggregation (from closed jaarrekening).
- Per-sector inclusion/exclusion rules declared as schema metadata
  (`emuInclusionRule` field on sector-specific postings declared
  by the waterschap sibling) — defaults match the 2026 BBV
  handleiding.
- Reproducibility: every EMU run cites contributing periods,
  classifier state, exclusion rules; same input → identical output.
- Manifest navigation entry (Bookkeeping > EMU-rapportage) behind
  `featureFlags.gov-emu` listing historical runs + `type: detail`
  for each.

### Out of Scope

- **Implementation code** — spec-only change.
- **EMU-bestand serialisation to CBS** — owned by sibling
  `add-shillinq-cbs-bestanden-extended` REQ-CBSE-005; this spec
  produces the EMU value; the bestand renders it.
- **Frontend Vue components** beyond `CnIndexPage` /
  `CnDetailPage`.

## Approach

One delta, adding ADDED Requirements to a brand-new spec
(`bookkeeping-emu-reporting`). Each requirement is prefixed
`REQ-EMU-*`. RFC 2119 keywords; `#### Scenario:` with
GIVEN/WHEN/THEN.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds `esaClassifier`
  enum field on `Account`; declares the EMU aggregations +
  calculation metadata.
- `lib/Settings/seeds/esa-2010-classifier.json` — new file (~25
  records), SPDX in docblock, `_meta` block.
- `src/manifest.json` — adds 1 navigation entry +
  `type: index` + `type: detail` pages behind
  `featureFlags.gov-emu`.
- No new PHP services (conditional ~20-LOC ADR-031 exception
  guard if engine cannot express multi-sector filter).

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-aggregations`
  supporting filter clauses with classifier metadata; if not, the
  ADR-031 exception path applies (a single-method ~20-LOC
  `EmuCalculator`).

## Risks

### Risk 1: Aggregation engine cannot express multi-sector EMU filter

**Severity**: Medium
**Mitigation**: Same shape as parent envelope Risk 2 — if
`x-openregister-aggregations` can't express the multi-schema filter
inside the required transformation, the implementing cycle uses
a thin PHP calculation via ADR-031 §"PHP guards remain a
legitimate seam" exception path. The spec is shape-neutral on the
mechanism.

### Risk 2: BBV handleiding EMU exclusion rules drift across years

**Severity**: Low
**Mitigation**: `emuInclusionRule` defaults match the 2026 BBV
handleiding; operator override permitted per posting; re-validated
yearly with the BBV reviewer persona.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the
change folder. Post-implementation rollback follows the standard
additive-aggregation pattern.

## Open Questions

1. **EMU computation scope** — quarterly + annual confirmed in
   REQ-EMU-003; an intra-period rolling view is deferred. Confirm
   with the BBV reviewer persona before `opsx-apply`.
