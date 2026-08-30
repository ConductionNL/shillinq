# Spec: bookkeeping-innovatiebox-administratie

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (MKB / innovation)
**Depends on:** bookkeeping-cost-centers-dimensions, bookkeeping-vpb-corporate-tax

## ADDED Requirements

### Requirement: REQ-IBA-001: The system SHALL declare an `IPAssetValuation` register for immateriële activa that qualify for the innovatiebox under the afpelmethode

Per Wet Vpb art. 12b, profit attributable to self-produced
immateriële activa MAY be taxed at the innovatiebox tariff
(currently 9%; see REQ-IBA-007 for historical rates). The
`IPAssetValuation` register applies to the **afpelmethode** route
only — taxpayers electing the **forfaitair** route do NOT register
per-asset valuations (see REQ-IBA-002).

The `IPAssetValuation` register MUST declare records with the
following fields:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `assetNaam` | string | Yes | Human-readable name of the IP asset |
| `assetType` | enum | Yes | One of `s-en-o-certificaat \| octrooi \| kwekersrecht \| softwareprogrammatuur \| model-tekening` |
| `wbsoVerklaringNummer` | string | No | FK to S&O-administratie (required when `assetType: 's-en-o-certificaat'`) |
| `octrooiNummer` | string | No | Patent registration number (required when `assetType: 'octrooi'`) |
| `valuationBedrag` | number ≥ 0 | Yes | Capitalised valuation in euros |
| `valuationDate` | date | Yes | Effective valuation date |
| `applicableTariff` | number | Yes | Innovatiebox tariff in effect at `valuationDate`; default `0.09` (per REQ-IBA-007 seed) |
| `vpbBalansLinkId` | FK | Yes | Reference to `VpbBalansLink` (REQ-VPB-002) |
| `schema:type` | annotation | — | `schema:Intangible` (Schema.org mapping for the IP asset record) |

Per ADR-031 this is a declarative register — no PHP IP-service.

#### Scenario: An S&O-certificaat asset is registered under the afpelmethode

- **GIVEN** a Vpb-pligtige administration that elected the
  afpelmethode and holds an S&O-certificaat
- **WHEN** an `IPAssetValuation` with `assetType:
  's-en-o-certificaat'`, `valuationBedrag: 250000.00`, and
  `applicableTariff: 0.09` is saved
- **THEN** the save MUST succeed; **AND** the asset MUST appear in
  the innovatiebox-administratie aggregation (REQ-IBA-003) under
  the afpelmethode branch.

### Requirement: REQ-IBA-002: The system SHALL support both the forfaitair election and the afpelmethode as mutually exclusive innovatiebox routes per fiscal year

The taxpayer MUST elect exactly one of the two innovatiebox routes
per fiscal year. The system MUST express the election declaratively
via an `InnovatieboxElection` register with fields:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | FK to the administration |
| `fiscalYear` | integer | Yes | The year the election applies to |
| `route` | enum | Yes | `forfaitair \| afpelmethode` |
| `applicableTariff` | number | Yes | Innovatiebox tariff for the fiscal year; default `0.09` (per REQ-IBA-007 seed) |
| `forfaitairCapBedrag` | number | Yes (route=forfaitair) | Statutory cap on innovation-attributed profit; default `25000` (per seed) |
| `forfaitairPercentage` | number | Yes (route=forfaitair) | Default `0.25` (25% of the operating profit per Wet Vpb art. 12bg) |

The two routes MUST behave as follows:

- **Forfaitair** (Wet Vpb art. 12bg): a per-fiscal-year flat
  election. The innovation-attributed profit is computed as
  `min(forfaitairPercentage × operatingProfit, forfaitairCapBedrag)`
  — i.e. 25% of the operating profit, capped at €25 000 of
  innovation-attributed profit per year. NO per-IP-asset
  valuation entries are required. This route is intended for SMEs
  that prefer a fixed approximation over per-asset accounting.
- **Afpelmethode** (Wet Vpb art. 12b): explicit per-IP-asset profit
  attribution via `IPAssetValuation` (REQ-IBA-001) and
  `WinstToerekening` (REQ-IBA-004). No statutory cap; profit
  attribution is asset-by-asset.

Per ADR-031 the election MUST be declarative — no PHP
method-selector service.

#### Scenario: An MKB taxpayer elects the forfaitair route for a fiscal year

- **GIVEN** an MKB administration with operating profit
  `€200 000` in fiscal year 2026
- **WHEN** an `InnovatieboxElection` with `fiscalYear: 2026`,
  `route: 'forfaitair'`, `forfaitairPercentage: 0.25`,
  `forfaitairCapBedrag: 25000`, `applicableTariff: 0.09` is saved
- **THEN** the save MUST succeed; **AND** the innovation-attributed
  profit MUST be computed as `min(0.25 × 200 000, 25 000) = 25 000`;
  **AND** NO `IPAssetValuation` records MAY be required for the
  same fiscal year.

#### Scenario: Forfaitair cap binds when 25% of profit exceeds €25 000

- **GIVEN** an MKB administration with a forfaitair election for
  fiscal year 2026 and operating profit `€500 000`
- **WHEN** the innovatiebox aggregation runs for 2026
- **THEN** the innovation-attributed profit MUST be capped at
  `€25 000` (because `0.25 × 500 000 = 125 000 > 25 000`); **AND**
  an audit-trail entry MUST record that the cap was applied.

### Requirement: REQ-IBA-003: The system SHALL produce the innovatiebox-administratie as a declarative aggregation

The innovatiebox-administratie MUST be an
`x-openregister-aggregations` declaration that, per fiscal year,
summarises the innovation-attributed profit and the resulting Vpb
impact at the applicable innovatiebox tariff. The aggregation MUST
branch on the `InnovatieboxElection.route` for that fiscal year:

- For `route: 'forfaitair'`, the aggregation MUST consume operating
  profit from the Vpb-balans (REQ-VPB-003) and apply
  `min(forfaitairPercentage × operatingProfit, forfaitairCapBedrag)`.
- For `route: 'afpelmethode'`, the aggregation MUST consume
  `IPAssetValuation` and `WinstToerekening` records per IP asset.

Per ADR-031, no PHP innovatiebox-service.

#### Scenario: Aggregation produces the 9% tariff impact per asset under the afpelmethode

- **GIVEN** an IP asset with `€100 000` attributed profit and
  `applicableTariff: 0.09` for fiscal year 2026
- **WHEN** the innovatiebox aggregation for 2026 runs
- **THEN** the innovatiebox-taxed base MUST be `€100 000`; **AND**
  the Vpb impact MUST be `€9 000` (9% of €100 000), versus
  approximately `€25 800` at the standard 25.8% Vpb rate.

### Requirement: REQ-IBA-004: The system SHALL declare a `WinstToerekening` overlay for the afpelmethode

For `InnovatieboxElection.route: 'afpelmethode'`, a
`WinstToerekening` register MUST be available with fields:
`ipAssetId` (FK to `IPAssetValuation`), `periodId` (FK to
`FiscalPeriod`), `toegerekendeWinst` (number ≥ 0),
`verdeelsleutel` (enum `omzet-aandeel | r-en-d-uren |
custom-formula`), and `parameters` (JSON, conditioned on
`verdeelsleutel`). The verdeelsleutel MUST be a declarative
calculation on the `toegerekendeWinst` field. This register MUST
NOT be populated under the forfaitair route.

#### Scenario: Afpelmethode attributes profit by revenue share

- **GIVEN** an IP asset with `verdeelsleutel: 'omzet-aandeel'`,
  total revenue `€1 000 000`, IP-related revenue `€300 000`, and
  total profit `€200 000`
- **WHEN** the toerekening calculation runs for the period
- **THEN** `toegerekendeWinst` MUST be `€60 000` (30% of €200 000).

### Requirement: REQ-IBA-005: The innovatiebox section SHALL appear in the Vpb-aangifte voorbereiding (REQ-VPB-004) via a docudesk template

The docudesk template for the Vpb-aangifte voorbereiding
(REQ-VPB-004) MUST include an innovatiebox section that reflects
the elected route for the fiscal year:

- For `forfaitair`, the section MUST show the operating profit, the
  25% application, the €25 000 cap, and the resulting
  innovation-attributed profit.
- For `afpelmethode`, the section MUST show one row per IP asset
  with its valuation, attributed profit, and innovatiebox-tariff
  impact.

The section MUST be generated from the aggregation in REQ-IBA-003
— no PHP section-renderer.

#### Scenario: The innovatiebox section appears in the Vpb-aangifte voorbereiding under the afpelmethode

- **GIVEN** a fiscal year with an `InnovatieboxElection` of
  `route: 'afpelmethode'` and at least one `IPAssetValuation`
  record
- **WHEN** the Vpb-aangifte voorbereiding is rendered
- **THEN** an innovatiebox section MUST appear with one row per IP
  asset; **AND** the total innovatiebox-taxed base MUST contribute
  to the Vpb-aangifte total line.

### Requirement: REQ-IBA-006: Innovatiebox-administratie SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST declare a feature-flag-controlled menu
entry (`featureFlags.mkb-innovatiebox`) under
`Bookkeeping > Innovatiebox` with `type: index` for the per-year
elections plus `type: detail` per election (showing the route,
applicable tariff, and — for afpelmethode — the IP assets and
their winsttoerekening). Per ADR-024 Tier-4, no bespoke Vue files.

#### Scenario: Innovatiebox menu toggles with the feature flag

- **GIVEN** the manifest declares `featureFlags.mkb-innovatiebox`
- **WHEN** the flag is ON
- **THEN** the Innovatiebox menu MUST appear.
- **WHEN** the flag is OFF
- **THEN** the menu MUST NOT render.

### Requirement: REQ-IBA-007: Innovatiebox tariffs and forfaitair parameters SHALL be seeded, not hard-coded

A seed file `innovatiebox-tariefen.json` MUST ship under
`lib/Settings/seeds/` and be loaded into an `InnovatieboxTariff`
register on first install. The seed MUST carry the historical
tariff schedule and the current forfaitair parameters with
`effectiveFrom` / `effectiveTo` windows so that future statutory
changes are a seed update, not a spec edit. The historical
schedule is:

| Period | `applicableTariff` | Notes |
|---|---|---|
| before 2018 | `0.05` | Original innovatiebox rate |
| 2018 – 2020 | `0.07` | Interim rate |
| 2021 – present | `0.09` | Current statutory rate |

The forfaitair parameters seed MUST carry
`forfaitairPercentage: 0.25` and `forfaitairCapBedrag: 25000` per
Wet Vpb art. 12bg (current regime).

Per ADR-031, tariffs and forfaitair parameters are NOT baked as
schema enums.

#### Scenario: A future tariff change is a seed update, not a code change

- **GIVEN** a hypothetical future statute changes the innovatiebox
  tariff to `0.10` effective 2028
- **WHEN** a new seed file `innovatiebox-tariefen-2028.json` is
  shipped
- **THEN** the `InnovatieboxTariff` register MAY hold both records
  with non-overlapping `effectiveFrom` / `effectiveTo` windows;
  **AND** REQ-IBA-003's aggregation MUST read the active tariff
  for the elected fiscal year.
