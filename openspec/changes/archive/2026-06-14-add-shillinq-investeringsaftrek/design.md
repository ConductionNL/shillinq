# Design — Investeringsaftrek

## Context

The four investeringsaftrek regelingen — KIA (kleinschaligheid),
EIA (energie), MIA (milieu), Vamil (vrije afschrijving) — are
computed from the existing T4-base `FixedAsset` register. Each
regeling has its own annual schalen + bedrijfsmiddel-lijst
maintained by RvO. Without dedicated primitives, the computation
either lives in spreadsheets against RvO schalen, or in a separate
product.

This change is **spec-only**. Implementation lands later through
`opsx-apply`; this doc explains *why* the shape is what it is.

## Decisions

### D1 — Classifier as an overlay register, NOT a parallel `InvesteringAsset` register

Per ADR-031 + the parent envelope's design D9, the four regelingen
are overlays on existing `FixedAsset` records. An
`InvesteringClassifier` overlay carries `aftrekType`,
`bedrijfsmiddelCode`, `aanvraagDatum`, `aanvraagNummer`, and
`toegekendBedrag`. An asset MAY carry multiple classifiers (KIA
+ MIA cumulatively where RvO permits). The alternative (forking
`FixedAsset` per regeling) was rejected — every regeling is a
classification, not a distinct asset class.

### D2 — Aftrek as declarative calculations reading the seeded tarieven

Each regeling's calculation lives as an `x-openregister-calculations`
block on `FixedAsset`:

- **KIA**: flat-rate percentage on total invested, with threshold
  + rampup + maximum + taper zone (lookup from seed).
- **EIA**: 40% on Energielijst-codes (configurable).
- **MIA**: 13.5/27/36% per category A/B/C (configurable).
- **Vamil**: vrije afschrijving up to 75% in year 1 for
  Milieulijst assets.

If the calculation engine cannot express KIA's
threshold/rampup/maximum/taper as a lookup table, the ADR-031
exception path applies (a single-method ~20-LOC `KiaSchalenLookup`).

### D3 — Annual tarieven seed, version-pinned in filename

`investeringsaftrek-tarieven-2026.json` contains the 2026 schalen
+ Energielijst + Milieulijst codes + Vamil-eligible codes. Filename
version-pinning means a 2027 seed lives side-by-side; operator
switches active seed per fiscal year. Spec references the
regulation, not values.

### D4 — RvO aanvraagdossier + mededeling-feed via docudesk + openconnector

Per ADR-019 + ADR-022, the RvO roundtrip is config:

- aanvraagdossier rendered as a docudesk template (per
  bedrijfsmiddel-aanvraag for EIA/MIA/Vamil; KIA requires no
  separate aanvraag).
- aanvraag submission rides openconnector source row.
- mededeling-feed (RvO returns award status) rides a second
  openconnector source row; updating `toegekendBedrag` writes an
  audit-trail event.

No `lib/Service/RvoClient.php`.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| FixedAsset register | T4-base `bookkeeping-fixed-assets-depreciation` | Overlay `InvesteringClassifier`; no fork. |
| Calculation engine | `x-openregister-calculations` (ADR-031) | KIA / EIA / MIA / Vamil formulas declared per regime. ADR-031 exception path covers engine-limited KIA lookup. |
| Document rendering | docudesk (ADR-022) | aanvraagdossier template per regeling. |
| External submission + feed | openconnector (ADR-019) | aanvraag + mededeling source rows. |
| Audit trail | OR audit-trail-immutable (ADR-022) | Award updates log automatically. |
| Seed data import | `ConfigurationService::importFromApp()` | Loads the annual tarieven. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | 1 entry behind `featureFlags.mkb-investeringsaftrek`. |

**Net new code in implementation cycle**: 1 overlay schema
(`InvesteringClassifier`) + 1 calculation declaration (4 regimes)
+ 1 seed JSON file + 1 docudesk template + 2 openconnector source
rows + 1 manifest entry. Possibly 1 single-method PHP guard
(KIA-schalen lookup, ADR-031 exception path).

## Seed Data

| File | Purpose | Approximate row count |
|---|---|---|
| `lib/Settings/seeds/investeringsaftrek-tarieven-2026.json` | KIA drempel/oploop/maximum/taper zone + EIA percentage + Energielijst codes + MIA percentages per categorie + Milieulijst codes + Vamil-eligible bedrijfsmiddel codes (2026 RvO release) | ~100 |

SPDX header (EUPL-1.2 + Copyright Conduction B.V.) inside docblock;
`_meta` block (`source: 'RvO investeringsaftrek-regelingen'`,
`year: 2026`); loaded via `ConfigurationService::importFromApp()`
in the repair step.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| RvO schalen revise yearly | Seed file version-pinned; spec references regulation, not values. |
| KIA-schalen lookup engine-limited | ADR-031 exception path — single-method ~20-LOC `KiaSchalenLookup`. |
| Cumulative KIA + EIA / MIA combinations | Validation per regeling; operator warning when a combination is disallowed per active 2026 schalen. |
| Asynchronous award update lag | Mededeling-feed runs on a schedule (scheduled-workflow); audit-trail records the mededeling lineage. |
