# Design — Innovatiebox Administratie

**status: pr-created**

## Context

Per Wet Vpb art. 12b / 12bg, profit attributable to self-produced
immateriële activa MAY be taxed at the innovatiebox tariff
(currently 9% statutory per Wet Vpb art. 12b 2026). Two routes
exist: **forfaitair** (Wet Vpb art. 12bg — 25% of operating profit,
capped at €25 000) and **afpelmethode** (Wet Vpb art. 12b —
explicit per-IP-asset valuation + winsttoerekening). Without
dedicated primitives, both routes live in spreadsheets, then get
transcribed into the Vpb-aangifte.

This change is **spec-only**. Implementation lands later through
`opsx-apply`; this doc explains *why* the shape is what it is.

## Decisions

### D1 — Two routes as a per-fiscal-year election register, mutually exclusive

Per Wet Vpb, the route choice is per-fiscal-year. Modelled as an
`InnovatieboxElection` register with `administrationId`,
`fiscalYear`, `route` enum, plus forfaitair parameters
(`forfaitairCapBedrag` default 25000, `forfaitairPercentage`
default 0.25). Mutual exclusion enforced at the aggregation layer
— `(administrationId, fiscalYear)` MUST have exactly one election.
No PHP method-selector.

### D2 — Afpelmethode requires `IPAssetValuation`, forfaitair does NOT

The `IPAssetValuation` register applies to the afpelmethode route
ONLY. For forfaitair taxpayers, no per-asset entries are required
— the innovation-attributed profit is computed as `min(0.25 ×
operatingProfit, 25000)`. The aggregation picks the route from
the active election + computes accordingly.

### D3 — Statutory tariff is seeded, NOT hard-coded

Per REQ-IBA-007, the tarief schedule lives in
`lib/Settings/seeds/innovatiebox-tariefen.json`. The seed contains
the full historic schedule:

| Year range | Tariff |
|---|---|
| before 2018 | 0.05 |
| 2018–2020 | 0.07 |
| 2021–present | 0.09 |

Plus forfaitair parameters (`forfaitairCapBedrag: 25000`,
`forfaitairPercentage: 0.25`) per Wet Vpb art. 12bg. Aggregations
read the active tariff per fiscal year. A future statutory change
ships as a new seed file (`innovatiebox-tariefen-2028.json`); NO
code change required. The current statutory 9% (`0.09`) is correct
per Wet Vpb art. 12b 2026.

### D4 — Winsttoerekening as a per-period mapping register (afpelmethode)

For afpelmethode taxpayers, `WinstToerekening` maps per-period
omzet/winst to one or more IP-assets via a configurable
verdeelsleutel. The aggregation sums per-asset winst-toerekening
× tariff to produce the 5% / 7% / 9% impact per asset per fiscal
year. No PHP winsttoerekening service.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Vpb-balans surface | Sibling `add-shillinq-vpb-corporate-tax` | `IPAssetValuation.vpbBalansLinkId` FK; innovatiebox-sectie attaches to the Vpb-aangifte. |
| Cost-center | T4-base `bookkeeping-cost-centers-dimensions` | `WinstToerekening` references cost-centers as winstcluster. |
| WBSO S&O-certificaat | Sibling `add-shillinq-wbso-sno-administratie` | `IPAssetValuation.wbsoVerklaringNummer` FK when `assetType: 's-en-o-certificaat'`. |
| Calculation engine | `x-openregister-calculations` (ADR-031) | Forfaitair min(0.25 × operatingProfit, 25000) and afpelmethode per-asset × tariff. |
| Aggregation engine | `x-openregister-aggregations` | Innovatiebox-administratie rendering. |
| Document rendering | docudesk (ADR-022) | Vpb-aangifte innovatiebox-sectie template. |
| Audit trail | OR audit-trail-immutable (ADR-022) | Cap-application + tariff-application events log automatically. |
| Seed data import | `ConfigurationService::importFromApp()` | Loads the tariefen + forfaitair parameters seed. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | 1 entry behind `featureFlags.mkb-innovatiebox`. |

**Net new code in implementation cycle**: 3 schema declarations
(`IPAssetValuation`, `InnovatieboxElection`, `WinstToerekening`) +
1 aggregation declaration + 1 seed JSON file + 1 docudesk template
+ 1 manifest entry. No new PHP service.

## Seed Data

| File | Purpose | Approximate row count |
|---|---|---|
| `lib/Settings/seeds/innovatiebox-tariefen.json` | Innovatiebox tariefschedule per fiscal year (before 2018: 0.05; 2018–2020: 0.07; 2021–present: 0.09) + forfaitair parameters (cap 25000 EUR, percentage 0.25 per Wet Vpb art. 12bg) | ~10 |

SPDX header (EUPL-1.2 + Copyright Conduction B.V.) inside docblock;
`_meta` block (`source: 'Wet Vpb art. 12b/12bg'`); loaded via
`ConfigurationService::importFromApp()` in the repair step.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Statutory tariff change | Seed update only; aggregation reads active tariff per fiscal year. The current 0.09 is correct per Wet Vpb art. 12b 2026. |
| Route mutually-exclusive constraint drift | Aggregation enforces one election per `(administrationId, fiscalYear)`. |
| Forfaitair cap binding mid-year | Cap is per-fiscal-year; if profit grows beyond €100k (25% × profit > 25k), cap binds; audit-trail records cap application. |
| Afpelmethode winsttoerekening verdeelsleutel subjective | Configurable per administration; supporting documentation referenced in docudesk attachments. |
