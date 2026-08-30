---
status: done
---

# Spec: bookkeeping-innovatiebox-administratie

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (MKB + R&D-intensive scale-ups)
**Depends on:** bookkeeping-cost-centers-dimensions, bookkeeping-chart-of-accounts, bookkeeping-vpb-corporate-tax, bookkeeping-wbso-sno-administratie, bookkeeping-payroll

## Purpose

This specification defines the requirements for bookkeeping innovatiebox administratie in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude pure backend/compliance: innovatiebox admin — not browser-testable


### REQ-IBA-001: The system SHALL register kwalificerende immateriele activa (IP assets) with validated access-tickets

The system SHALL satisfy this requirement: The system SHALL register kwalificerende immateriele activa (IP assets) with validated access-tickets.

Per Wet Vpb art. 12ba, three routes are available for IP assets:
1. **Octrooi-route** (art. 12ba lid 1 sub a): patent, utility model, plant breeder's right, orphan drug cert, supplementary protection cert
2. **S&O-route** (art. 12ba lid 1 sub b): asset from R&D work with RVO S&O-verklaring (WBSO)
3. **Combinatie-route** (art. 12ba lid 3): for groups > €50M revenue, BOTH S&O-verklaring AND octrooi required

The system MUST declare a `QualifyingAsset` register with:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `naam` | string | Yes | IP asset name |
| `type` | enum | Yes | `software \| octrooi \| kwekersrecht \| weesgeneesmiddel \| gebruiksmodel \| aanvullend_beschermingscertificaat \| combinatie` |
| `toegangsticket.soort` | enum | No | `so_verklaring \| octrooi \| kwekersrecht \| weesgeneesmiddel \| gebruiksmodel \| abc` |
| `toegangsticket.so_verklaring_nummer` | string | No | RVO verklaring (format S{jaar}/{6-cijfer}); required if soort=so_verklaring |
| `toegangsticket.so_verklaring_periode` | object | No | {van, tot} date range for S&O cert validity |
| `toegangsticket.octrooi_nummer` | string | No | Patent registration; required if soort=octrooi |
| `toegangsticket.octrooi_land` | string | No | Patent jurisdiction |
| `toegangsticket.octrooi_aanvraagdatum` | date | No | Patent filing date |
| `toegangsticket.kwekersrecht_nummer` | string | No | Plant cert number |
| `type_immaterieel_activum` | enum | Yes | `software \| hardware-related \| data-algoritme \| other` |
| `ontwikkelingsperiode.start` | date | Yes | Development start |
| `ontwikkelingsperiode.einde_verwacht` | date | No | Expected completion |
| `in_gebruik_genomen` | date | No | Date asset entered service |
| `voortbrenger` | enum | Yes | `interne_ontwikkeling \| externe_aankoop \| combinatie` |
| `kostendrager_id` | FK | Yes | Cost carrier (R&D team / project) |
| `verbonden_lichaam_betrokken` | boolean | No | Whether related-party R&D involved |
| `drempelbedrag_van_toepassing` | boolean | No | True if small-amount exemption applies |
| `status` | enum | Yes | `valid \| invalid_access_ticket \| awaiting_renewal \| expired` |

For `type: 'combinatie'`, both `so_verklaring_nummer` AND (`octrooi_nummer` OR `kwekersrecht_nummer`) MUST be present.

#### Scenario: S&O-route asset is registered with RVO certificate validation

- **GIVEN** an administration with a valid S&O-verklaring S2024/001234 (van 2024-01-01, tot 2024-12-31)
- **WHEN** a `QualifyingAsset` with `type: 'software'`, `toegangsticket.soort: 'so_verklaring'`, `toegangsticket.so_verklaring_nummer: 'S2024/001234'` is saved
- **THEN** the system MUST validate the verklaring format (S{jaar}/{6-cijfer}); **AND** set `status: 'valid'`; **AND** the asset MUST be eligible for nexus + profit-attribution calculations

#### Scenario: Invalid access-ticket excludes asset from innovatiebox calculations

- **GIVEN** an asset with an expired or missing access-ticket
- **WHEN** the innovatiebox aggregation runs
- **THEN** the asset MUST be excluded from nexus/profit-attribution (status != 'valid'); **AND** a warning MUST appear in the Vpb-aangifte prep

### REQ-IBA-002: The system SHALL compute OECD BEPS Action 5 nexus per IP asset per fiscal year

The system SHALL satisfy this requirement: The system SHALL compute OECD BEPS Action 5 nexus per IP asset per fiscal year.

Per Wet Vpb art. 12bc + OECD BEPS Action 5 modified nexus approach:

```
nexusbreuk = min(1; 1.3 × (eigen R&D + uitbesteed aan derden niet-verbonden) / (totale R&D incl. verbonden))
```

The system MUST declare a `NexusCalculation` register with:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `qualifying_asset_id` | FK | Yes | Link to `QualifyingAsset` |
| `boekjaar` | integer | Yes | Fiscal year |
| `eigen_rd_kosten` | number ≥ 0 | Yes | Internal R&D costs (loon + material) |
| `rd_kosten_uitbesteed_derden` | number ≥ 0 | Yes | R&D outsourced to **unrelated** third parties (counts in teller) |
| `rd_kosten_uitbesteed_verbonden` | number ≥ 0 | Yes | R&D outsourced to **related** entities per art. 10a Vpb (teller only, not uplift) |
| `totale_rd_kosten` | number ≥ 0 | Yes | Sum of above three |
| `uplift_factor` | number | Yes | Fixed 1.3 per OECD BEPS Action 5 |
| `nexus_teller_voor_uplift` | number | Yes | eigen_rd_kosten + uitbesteed_derden |
| `nexus_teller_na_uplift` | number | Yes | min(uplift_factor × teller_voor_uplift, totale_rd_kosten) [before cap] |
| `nexus_noemer` | number | Yes | totale_rd_kosten |
| `nexusbreuk_ongecapt` | number | Yes | nexus_teller_na_uplift / nexus_noemer |
| `nexusbreuk_toegepast` | number | Yes | min(nexusbreuk_ongecapt, 1.0) [capped at 100%] |
| `berekend_op` | datetime | Yes | Calculation timestamp |
| `berekend_door` | string | No | User who calculated |

The record MUST be immutable after creation (audit-trail enforces this).

#### Scenario: Uplift-factor 1.3 applies; cap binds at 100%

- **GIVEN** an asset with eigen_rd €480k, uitbesteed_derden €120k, uitbesteed_verbonden €80k → totaal €680k
- **WHEN** nexus calculation runs: teller_voor_uplift = €600k, teller_na_uplift = min(1.3 × €600k, €680k) = min(€780k, €680k) = €680k, ratio = €680k/€680k = 1.0, capped = 1.0
- **THEN** `nexusbreuk_toegepast: 1.0` (100%); **AND** all qualifying profit counts toward innovatiebox (no uplift loss, no cap loss)

#### Scenario: Uplift insufficient; cap binds at < 100%

- **GIVEN** an asset with eigen_rd €100k, uitbesteed_derden €50k, uitbesteed_verbonden €300k → totaal €450k
- **WHEN** nexus: teller_voor_uplift = €150k, teller_na_uplift = min(1.3 × €150k, €450k) = €195k, ratio = €195k/€450k = 0.433, capped = 0.433
- **THEN** `nexusbreuk_toegepast: 0.433` (43.3%); **AND** profit is reduced by 56.7% due to related-party R&D outsourcing

### REQ-IBA-003: The system SHALL attribute profit per IP asset using three configurable methods

The system SHALL satisfy this requirement: The system SHALL attribute profit per IP asset using three configurable methods.

Per Wet Vpb art. 12bd, three methods exist:
1. **Per-asset afpelmethode** (default): Opbrengst - routinewinst (mfg/distrib/marketing) = kwalificerende winst
2. **Forfaitaire methode** (art. 12bg): 25% of profit, capped at €25k/year, no per-asset required, 3-year election
3. **Cost-plus methode**: Intra-group transfer pricing (rare; for sibling transactions)

The system MUST declare an `IBProfitAttribution` register with:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `qualifying_asset_id` | FK | Yes | Link to `QualifyingAsset` |
| `boekjaar` | integer | Yes | Fiscal year |
| `methode` | enum | Yes | `per_asset_afpelmethode \| forfaitair_25pct \| cost_plus` |
| `bruto_opbrengst_activum` | number ≥ 0 | Yes | Gross revenue attributable to asset |
| `directe_kosten_activum` | number ≥ 0 | No | Direct costs (material, subcontracting for production) |
| `routine_marketing_winst` | number ≥ 0 | No | Arm's-length routine profit (marketing, sales) |
| `routine_distributie_winst` | number ≥ 0 | No | Arm's-length routine profit (distribution, logistics) |
| `routine_productie_winst` | number ≥ 0 | No | Arm's-length routine profit (manufacturing) |
| `kwalificerende_winst_voor_nexus` | number | Yes | Residual profit = bruto_opbrengst - directe_kosten - routines |
| `nexus_calculation_id` | FK | Yes | Link to `NexusCalculation` for this year |
| `nexusbreuk_toegepast` | number | Yes | From `NexusCalculation.nexusbreuk_toegepast` |
| `kwalificerende_winst_na_nexus` | number | Yes | kwalificerende_winst_voor_nexus × nexusbreuk |
| `effectief_tarief` | number | Yes | Innovatiebox tariff (0.09 per Wet Vpb art. 12b 2026) |
| `vpb_op_innovatiedeel` | number | Yes | kwalificerende_winst_na_nexus × effectief_tarief |
| `vpb_zonder_innovatiebox` | number | Yes | kwalificerende_winst_voor_nexus × 0.258 [standard rate for comparison] |
| `voordeel_innovatiebox` | number | Yes | vpb_zonder_innovatiebox - vpb_op_innovatiedeel |
| `drempel_2024` | number ≥ 0 | No | Loss carry-forward offset amount |
| `drempel_resterend` | number ≥ 0 | No | Remaining carry-forward loss after offset |

Mutual exclusion: exactly one `IBProfitAttribution` per `(qualifying_asset_id, boekjaar)`.

#### Scenario: Afpelmethode with nexus reduction

- **GIVEN** an asset with bruto €2.4M, direct costs €850k, routine profits (mfg €480k, distrib €90k, marketing €180k) = €750k routines, nexus 100%
- **WHEN** profit attribution: kwalif_voor_nexus = €2.4M - €0.85M - €0.75M = €800k; kwalif_na_nexus = €800k × 1.0 = €800k; vpb_impact = €800k × 0.09 = €72k
- **THEN** the record MUST show voordeel = €206.4k - €72k = €134.4k savings vs 25.8% rate

#### Scenario: Forfaitair method does NOT use nexus

- **GIVEN** an SME with operating profit €200k, elects forfaitair for 2026
- **WHEN** profit attribution: kwalif = min(0.25 × €200k, €25k) = €25k (cap does NOT bind); nexus NOT applied
- **THEN** the record MUST have `methode: 'forfaitair_25pct'`, `kwalificerende_winst_na_nexus: 25000`, `vpb_impact: €2.250k`

### REQ-IBA-004: The system SHALL enforce strict cost allocation per asset with doorsnijdingsverbod validation

Per Wet Vpb art. 12bd lid 2, costs allocated to innovatiebox assets MUST NOT be deducted again in regular GL (doorsnijdingsverbod = non-duplication rule).

The system MUST declare an `IBExpenseAllocation` register with:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `qualifying_asset_id` | FK | Yes | IP asset this cost is allocated to |
| `boekjaar` | integer | Yes | Fiscal year |
| `periode` | string | Yes | `2024-Q1 \| 2024-Q2 \| 2024-M01` (quarterly or monthly) |
| `kostensoort` | enum | Yes | `rd_loonkosten \| materiaal \| afschrijving \| licentie \| uitbesteding_derden \| uitbesteding_verbonden \| overhead_opslag` |
| `bron` | enum | Yes | `loonadministratie \| faktuur \| journal_entry \| calculation` |
| `bron_referentie.so_verklaring` | string | No | S&O-uren approval number (for rd_loonkosten) |
| `bron_referentie.medewerker_ids` | array | No | Employee IDs charged (from payroll) |
| `bron_referentie.totale_so_uren` | number | No | Total S&O-uren charged |
| `bron_referentie.uurtarief_intern` | number | No | Internal rate (€/uur) |
| `bedrag` | number ≥ 0 | Yes | Allocated cost in EUR |
| `grootboekrekening` | string | Yes | GL account code (e.g., 4010) |
| `kostenplaats` | string | Yes | Cost center (e.g., rd-team-1) |
| `kostendrager_id` | FK | Yes | Cost carrier (project / team) |
| `boekstuk_referentie` | string | No | Voucher / memo reference |
| `exclusief_in_winstbepaling` | boolean | Yes | If true, cost MUST NOT appear in regular GL deduction |

**Doorsnijdingsverbod validation aggregation MUST run at year-end and BLOCK closing if:**
- Same (GL-account, kostenplaats) pair appears in BOTH `IBExpenseAllocation` with `exclusief_in_winstbepaling: true` AND in the GL regular-deduction feed
- Conflicting must be resolved before Vpb-aangifte submission

#### Scenario: R&D loon is allocated to innovatiebox, must not duplicate in GL

- **GIVEN** employee loon costs €60k allocated to asset A via S&O-uren, marked `exclusief_in_winstbepaling: true` on account 4010
- **WHEN** GL also shows a €60k entry on account 4010 for the same employee/periode
- **THEN** doorsnijdingsverbod aggregation MUST flag: *"€60k (account 4010, kostenplaats rd-team) appears in both innovatiebox allocation AND GL regular deduction. Resolve conflict before year-end close."* **AND** year-end close MUST block until resolved

### REQ-IBA-005: The system SHALL track innovation losses and carry them forward per asset only

The system SHALL satisfy this requirement: The system SHALL track innovation losses and carry them forward per asset only.

Per Wet Vpb art. 12be, innovation losses (negative kwalificerende winst) are asset-specific. Losses from asset A can only offset future profits on asset A.

The system MUST declare a `CarryForwardLoss` register with:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `qualifying_asset_id` | FK | Yes | The asset that incurred the loss |
| `ontstaansboekjaar` | integer | Yes | Year loss was incurred |
| `negatief_kwalificerend_resultaat` | number < 0 | Yes | Loss amount (e.g., -€215k) |
| `verrekend_boekjaar` | array | No | [{jaar, bedrag, saldo_na}] records of offset applications |
| `saldo_open` | number ≥ 0 | Yes | Remaining unrecovered loss balance |
| `status` | enum | Yes | `open \| volledig_verrekend \| expired` |
| `vervaldatum` | date | No | If applicable (e.g., fiscal consolidation ends) |

**Loss carry-forward aggregation logic:**
1. For a given asset in fiscal year Y, if `CarryForwardLoss.saldo_open > 0` for prior years:
2. First offset open loss at FULL tariff (no nexus reduction): loss × full_profit_pct = tax reduction
3. Remainder profit at 9% × nexus

#### Scenario: Loss from 2023 offsets first-profit of 2024 at full tariff

- **GIVEN** asset A incurred loss €215k in 2023, has saldo_open €215k
- **WHEN** asset A has €800k kwalif_profit in 2024 (after nexus), offset logic runs:
  - First €215k profit offsets loss at full tariff (e.g., 25.8% = €55.5k tax benefit)
  - Remaining €585k profit at 9% nexus = €52.65k tax benefit
  - Total benefit: €108.15k (vs. pure 9% = €72k, demonstrating loss offsets at higher effective rate)
- **THEN** `CarryForwardLoss` MUST record verrekend: [{jaar: 2024, bedrag: 215000, saldo_na: 0}]; status: 'volledig_verrekend'

### REQ-IBA-006: Vpb-aangifte innovatiebox-sectie SHALL render per-asset contributions via docudesk template

The system MUST register a docudesk template for Vpb-aangifte innovatiebox-sectie that:
- Shows one row per `QualifyingAsset` (status: valid)
- Displays: asset name, kwalif_winst_voor_nexus, nexus ratio, kwalif_winst_na_nexus, tariff (0.09), Vpb-impact (euros)
- Subtotal per asset; grand total contributed to Vpb-aangifte regel 23
- Includes carry-forward offset reconciliation (if applicable)

#### Scenario: Vpb-aangifte section renders afpelmethode assets

- **GIVEN** fiscal year 2024, afpelmethode, two assets: asset-A (€800k after nexus), asset-B (€300k after nexus)
- **WHEN** docudesk template renders
- **THEN** template MUST display:
  ```
  Innovatiebox-administratie
  ====================================
  Asset-A: €800k × 0.09 = €72k
  Asset-B: €300k × 0.09 = €27k
  ────────────────────────────
  TOTAAL:  €1.1M × 0.09 = €99k
  ```

### REQ-IBA-007: Loss carry-forward aggregation MUST prioritize prior-year offsets at full tariff before 9% applies

The system SHALL satisfy this requirement: Loss carry-forward aggregation MUST prioritize prior-year offsets at full tariff before 9% applies.

Per Wet Vpb art. 12be, loss recovery follows strict accounting:
1. Open carry-forward loss (prior year(s)) offsets first against current-year profit, at full statutory tariff (NOT reduced by nexus)
2. Residual profit subject to 9% × nexus

The aggregation MUST enforce this order and audit-trail each step.

#### Scenario: Loss offset reduces nexus benefit (full tariff beats nexus reduction)

- **GIVEN** asset A: loss_2023 €100k open, profit_2024 €200k (after nexus 100%)
- **WHEN** aggregation offsets: first €100k profit × (say) 25.8% (full rate) = €25.8k tax benefit; remaining €100k × 9% = €9k benefit
- **THEN** total benefit €34.8k (higher than pure 9% × €200k = €18k) because loss offsets at full tariff

### REQ-IBA-008: The system SHALL support VSO (vaststellingsovereenkomst) audit trails for multi-year compliance

Per Belastingdienst practice, innovatiebox positions MUST be defensible under audit. The system MUST:
- Maintain immutable audit-trail (via OR audit-trail-immutable per ADR-022) of every nexus, profit-attribution, and loss carry-forward change
- Support VSO locking: once a fiscal year is approved by Belastingdienst (VSO signed), records become read-only for that year
- Provide export for Belastingdienst: SBR/XBRL + PDF + CSV of all calculations per year

#### Scenario: VSO-locked year prevents amendment without audit record

- **GIVEN** fiscal year 2024 signed off in VSO 2024-001 (Belastingdienst approval)
- **WHEN** user attempts to change `IBProfitAttribution.kwalificerende_winst_voor_nexus` for 2024
- **THEN** system MUST reject with message: *"Year 2024 is VSO-locked (VSO-001). Amendments require amended aangifte + Belastingdienst approval."*; **AND** system MUST audit-trail the attempted change

### REQ-IBA-009: The system SHALL provide scenario-testing for nexus impact on taxpayer's Vpb position

Taxpayers (especially scale-ups planning R&D headcount) need to forecast nexus impact on Vpb benefit. The system MUST support:
- Read-only scenario: "What-if I add €500k outsourced R&D to related party?" → recalculate nexus → show benefit impact
- Snapshots: save & compare scenarios (e.g., "2024-strategy-A" vs "2024-strategy-B")
- No scenario data persists unless explicitly saved

#### Scenario: Scenario planning for R&D outsourcing strategy

- **GIVEN** asset with eigen_rd €500k, scenario: add €300k to related-party R&D
- **WHEN** user runs scenario: new nexus = min(1.3 × €500k / (€500k + €300k)) = min(1.3 × 0.625) = 0.8125 (81.25%, was 100%)
- **THEN** system MUST display: *"Outsourcing €300k reduces nexus from 100% to 81.25%. Vpb-impact change: €XX → €YY"* without modifying actual records

### REQ-IBA-010: Statutory tariff 0.09 (per Wet Vpb art. 12b 2026) SHALL be hard-coded; legislative changes ship as spec updates

The system SHALL satisfy this requirement: Statutory tariff 0.09 (per Wet Vpb art. 12b 2026) SHALL be hard-coded; legislative changes ship as spec updates.

The statutory rate of 9% is encoded in `applicableTariff: 0.09` across all registers. Future statutory changes (e.g., 2028 hike to 15%) require spec update.

The system MUST NOT support tariff seed configuration (unlike the earlier add-shillinq proposal). Tariff is immutable per fiscal year.

#### Scenario: Statutory tariff is used for all calculations

- **GIVEN** fiscal year 2026
- **WHEN** innovatiebox aggregation calculates Vpb-impact
- **THEN** system MUST apply `effectief_tarief: 0.09` for all qualifying profit; future rates ship as spec updates

## Standards & Sources

- **Wet op de vennootschapsbelasting 1969, art. 12b–12bg** (Afdeling 2.3 Innovatiebox)
- **Besluit Innovatiebox 2023** (Stcrt. 2023, 21084)
- **OECD/G20 BEPS Action 5** (modified nexus approach, uplift 1.3, cap 100%)
- **WBSO-Wet** (hoofdstuk VIII, S&O-verklaring)
- **Wet Vpb art. 10a** (related-entity definition)
- **Belastingdienst transfer pricing guidelines** (arm's-length routine-profit benchmarking)

## Manifest Navigation

`src/manifest.json` MUST declare entry `Bookkeeping > Innovatiebox` (behind `featureFlags.mkb-innovatiebox`) with pages:
1. **Assets** (index): list all `QualifyingAsset` with status, access-ticket, nexus summary
2. **Nexus** (detail): per asset, display `NexusCalculation` with R&D breakdown, uplift, cap logic
3. **Profit Attribution** (detail): per asset, display method, winst-split, tariff impact
4. **Cost Allocation** (detail): per asset, per periode, cost-allocation detail + doorsnijdingsverbod summary
5. **Export** (action): SBR/PDF for Belastingdienst submission
