# Design — EMU Reporting

**status: pr-created**

## Context

EMU-saldo and EMU-schuld are reported per ESA 2010 conventions,
adapted via the BBV handleiding EMU-saldo. The computation operates
over posted GL data filtered + categorised by ESA-2010 sector
classifier. Per ADR-031, the computation MUST be declarative — but
if the aggregation engine cannot express the multi-sector filter,
the ADR-031 exception path (single ~20-LOC PHP guard) applies.

This change is **implemented**. The `opsx-apply` cycle landed the
following artefacts — this doc explains *why* the shape is what it is.

## Declarative-vs-imperative decision (ADR-031)

The `x-openregister-aggregations` engine does not yet support
cross-schema filter joins (GLLine → Account.esaClassifier) combined with
a dynamic emuInclusionRule filter sourced from a sibling schema
(WaterschapHeffingPosting). Therefore, the ADR-031 exception path
applies: `lib/Guard/EmuCalculator.php` provides a single-class PHP guard
(two public entry-point methods + one private helper, ≤ 30 LOC of domain
logic) referenced from the `guard:` clause on both aggregation
declarations in `lib/Settings/shillinq_register.json`. When the engine
gains the required multi-schema filter capability, the guard class can be
removed and the declarative aggregation will handle the full computation.

**status: pr-created**

## Decisions

### D1 — ESA-2010 classifier as a flag on `Account`, NOT a parallel sector register

`Account` gains an optional `esaClassifier` enum field. Every
account tagged with its ESA sector lets the EMU aggregation filter
+ group by sector without any join logic. The seed file ships
the canonical ESA-2010 classifier codes. The alternative (a
separate `EsaClassification` register joined to accounts) was
rejected per the parent envelope's design D4 — the flag fits
cleanly under ADR-031.

### D2 — EMU-saldo as a declarative aggregation grouped by sector with inclusion rules in calculation metadata

EMU-saldo = sum of `(debit - credit)` grouped by sector with
inclusion/exclusion rules applied. The rules live as schema
metadata (`emuInclusionRule: included | excluded | partial`) on
the sector-specific postings (declared by the waterschap sibling).
Defaults match the 2026 BBV handleiding. The aggregation reads
both the `esaClassifier` and `emuInclusionRule` fields at compute
time. The ADR-031 exception path (single-method PHP guard) applies
only if the aggregation engine cannot express the combined filter
+ grouping.

### D3 — Quarterly + annual cadences, reproducible from audit-trail

Two cadences:
- **Quarterly**: rides the IV3-base quarterly aggregation; outputs
  to the CBS EMU-bestand via the sibling
  `add-shillinq-cbs-bestanden-extended` REQ-CBSE-005.
- **Annual**: from the closed jaarrekening via
  `bookkeeping-financial-statements`.

Every EMU computation run records a stable aggregation hash + the
classifier state at compute time + the exclusion-rule metadata
applied. Re-running with unchanged inputs MUST produce identical
output.

### D4 — No parallel EMU register

The EMU computation is read-only over existing data. No
`EmuComputationResult` register is declared — runs are recorded
via the audit-trail-immutable + a tail-list aggregation
("historical runs") rendered in the manifest detail page. The
alternative (materialised result register) was rejected — the
computation is cheap to re-run and the materialised table
introduces a sync problem.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| ESA-2010 classifier on account | None | This change ships `esaClassifier` enum + seed. |
| Sector inclusion/exclusion rules | Sibling `add-shillinq-waterschappen-bbv-variant` REQ-WSB-005 declares `emuExclusionRule` on `WaterschapHeffingPosting` | This spec reads the same field at EMU compute time. |
| Quarterly IV3 aggregation | T3 `bookkeeping-iv3-reporting` | Quarterly EMU aggregation rides the same data. |
| Annual jaarrekening | T2 `bookkeeping-financial-statements` | Annual EMU aggregation reads the closed-year rollup. |
| Audit trail | OR audit-trail-immutable (ADR-022) | Every EMU run writes an audit event with hash. |
| Seed data import | `ConfigurationService::importFromApp()` | Seeds the ESA-2010 classifier list. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | 1 entry behind `featureFlags.gov-emu` listing historical runs. |

**Net new code in implementation cycle**: 1 schema field
(`esaClassifier`) + 2 aggregation declarations (quarterly + annual)
+ 1 seed JSON file + 1 manifest entry. No new PHP service unless
the ADR-031 exception path applies (single ~20-LOC guard).

## Seed Data

| File | Purpose | Approximate row count |
|---|---|---|
| `lib/Settings/seeds/esa-2010-classifier.json` | ESA-2010 sector classifier codes (S.1311 = centrale overheid, S.1313 = lokale overheid, S.1314 = wettelijke sociale verzekering, plus S.11/S.12/S.13/S.14/S.15/S.2 + canonical sub-codes) | ~25 |

SPDX header (EUPL-1.2 + Copyright Conduction B.V.) inside docblock;
`_meta` block (`source: 'ESA 2010 Eurostat'`, `year: 2010`); loaded
via `ConfigurationService::importFromApp()` in the repair step.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Engine cannot express multi-sector filter | ADR-031 exception path — single-method ~20-LOC `EmuCalculator` documented in the implementing cycle's design doc. |
| BBV handleiding EMU exclusion rules drift yearly | `emuInclusionRule` defaults match the 2026 BBV handleiding; operator override permitted per posting. |
| Re-run computation cost on large GL | Cheap aggregation; if cost grows, materialise as a scheduled-workflow output rather than ad-hoc. |
