# Design — Innovatiebox Administratie

## Context

Per Wet Vpb art. 12b–12bg, profit on self-developed IP assets MAY be taxed at 
9% instead of 25.8% corporate rate. The law imposes five linked constraints: 
(1) access-ticket validation (octrooi, S&O-verklaring, etc.), (2) OECD BEPS 
Action 5 modified nexus approach (uplift factor 1.3, cap at 100%), (3) three 
profit-attribution methods (forfaitair 25%, per-asset afpelmethode, cost-plus), 
(4) strict cost allocation with doorsnijdingsverbod (costs can't be deducted 
twice), (5) loss carry-forward only on the same asset (art. 12be). Without 
dedicated primitives, all five live in spreadsheets.

This change is **spec-only**. Implementation lands later through `opsx-apply`; 
this doc explains *why* the shape is what it is.

## Decisions

### D1 — Five registers as separate entities, not a monolithic "innovatiebox" record

The alternative (one fat `Innovatiebox` record with nested arrays) was rejected 
because: (a) each register has distinct lifecycle (asset status, calculation 
frequency, cost allocation period), (b) auditability requires per-entity 
versioning, (c) reuse: `IBExpenseAllocation` feeds cost-center reconciliation, 
`NexusCalculation` is queried by compliance reports. Five registers with clear 
FKs respect the OpenRegister separation-of-concerns principle.

### D2 — `QualifyingAsset` as the root anchor; status-driven exclusion

Each IP asset is a `QualifyingAsset` record. Assets without valid access-tickets 
automatically get `status: invalid_access_ticket` (enum: valid, invalid_access_ticket, 
awaiting_renewal, expired). Aggregations filter to `status: valid` only. The 
alternative (reject on insert) was rejected — assets may be in-progress (RVO 
S&O-verklaring pending approval).

### D3 — Nexus per asset per fiscal year, immutable after Belastingdienst approval

`NexusCalculation` is uniquely identified by `(qualifyingAssetId, boekjaar)`. 
Once calculated, the record is immutable (audit trail enforces this). The uplift 
factor (1.3) and cap (100%) are baked in — no configuration. The modified nexus 
approach is OECD standard per BEPS Action 5 and Wet Vpb art. 12bc.

### D4 — Three profit-attribution methods, one active per asset per fiscal year

Per Wet Vpb art. 12bd, three methods exist: forfaitair (25% of profit, €25k 
cap), per-asset afpelmethode (explicit winst-split per asset), cost-plus 
(intra-group transfer pricing). A single `IBProfitAttribution` record per 
`(qualifyingAssetId, boekjaar)` designates which method applies. Mutual 
exclusion enforced at aggregation layer (no duplicate records).

### D5 — `IBExpenseAllocation` with `exclusiefInWinstbepaling` flag for doorsnijdingsverbod

Each cost-allocation record carries `exclusiefInWinstbepaling: true` if it's 
allocated to innovatiebox (i.e., must NOT appear in GL regular deduction). 
The doorsnijdingsverbod validation aggregation scans for dual-posting: same 
`(accountNumber, kostenplaats)` pair appearing both in allocations AND in 
regular GL. This is a blocking validation (prevents closing).

### D6 — Loss carry-forward aggregation prioritizes carry-forward offset at full tariff

Per art. 12be, innovation losses are asset-specific. A loss from 2023 on asset A 
can only offset 2024 profit on asset A. The aggregation for a given asset walks 
the carry-forward queue: first open loss gets offset at full tariff (no nexus 
reduction), remainder profit at 9%. This ensures losses don't inherit nexus 
reductions.

### D7 — Statutory tariff (0.09) is hard-coded; legislative changes ship as spec updates

The 0.09 rate is correct per Wet Vpb art. 12b 2026. The alternative (seed-driven 
tariff table) was rejected — regulatory complexity makes the rate a spec 
constant, not an operational parameter. A future statutory change (say 2028 
rate hike to 15%) will be a spec update.

### D8 — Vpb-aangifte innovatiebox-sectie is docudesk template, NOT a calculation

The docudesk template renders per-asset rows from `IBProfitAttribution` + 
`NexusCalculation` + `CarryForwardLoss`, summing to a single Vpb-aangifte 
rule-23 value. The actual tax calculation (9% × adjusted profit) happens in 
the aggregation; the docudesk template is display only.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Cost-center + cost-carrier dimension | T4-base `bookkeeping-cost-centers-dimensions` | `IBExpenseAllocation.kostendrager_id` FK; `NexusCalculation` can group R&D by cost-carrier for transparency. |
| GL account structure + journal postings | T1 `bookkeeping-chart-of-accounts` + `bookkeeping-general-ledger` | `IBExpenseAllocation` references account + kostenplaats; doorsnijdingsverbod validation queries GL. |
| Vpb-balans surface | Sibling `bookkeeping-vpb-corporate-tax` | Innovatiebox-sectie attaches to Vpb-balans as a separate filtration (kwalificerende winst). |
| S&O-verklaring access tickets | Sibling `bookkeeping-wbso-sno-administratie` | `QualifyingAsset.toegangsticket.so_verklaring_nummer` references RVO cert; S&O-uren feed `IBExpenseAllocation` via SoUrenStaat. |
| Payroll loonheffing rates | T2 `bookkeeping-payroll` | R&D-loon costs in `IBExpenseAllocation` come from payroll; payroll uurloon (€ per uur) × S&O-uren. |
| Calculation engine | `x-openregister-calculations` (ADR-031) | Nexus formula (1.3 × teller / noemer, capped at 1.0), forfaitair min(25% × profit, €25k), per-asset tariff application. |
| Aggregation engine | `x-openregister-aggregations` (ADR-032) | Nexus per asset, profit-attribution per asset, loss carry-forward per asset, doorsnijdingsverbod cross-check, Vpb-sectie row rendering. |
| Document rendering | docudesk (ADR-022) | Vpb-aangifte innovatiebox-sectie + innovatiebox-administratie summary template for internal review. |
| Audit trail | OR audit-trail-immutable (ADR-022) | Every nexus calculation, profit-attribution change, cost allocation, and loss carry-forward write an immutable event. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | 1 entry behind `featureFlags.mkb-innovatiebox` (Assets, Nexus, Profit Attribution, Cost Allocation). |

**Net new code in implementation cycle**: 5 schema declarations (`QualifyingAsset`, 
`NexusCalculation`, `IBProfitAttribution`, `IBExpenseAllocation`, `CarryForwardLoss`) 
+ 4 aggregation declarations (nexus, profit-attribution, loss carry-forward, 
doorsnijdingsverbod validation) + 2 docudesk templates + 1 manifest entry + 
3 PHP service classes (asset access-ticket validator, nexus calculator, 
doorsnijdingsverbod checker).

## Seed Data

| File | Purpose | Approximate row count |
|---|---|---|
| None — all records are operationally authored | | |

No seed required. `QualifyingAsset` records are created by end-user (fiscalist / 
controller). Access-ticket validation happens on insert (no bootstrap data needed).

## Dutch Translation Key Terms

| English | Dutch |
|---|---|
| IP asset / Qualifying asset | Kwalificerend immaterieel activum |
| Access ticket | Toegangsticket |
| Nexus ratio | Nexusbreuk |
| Profit attribution | Winsttoerekening |
| Cost allocation | Kostentoerekening |
| Routine profit (manufacturing / distribution / marketing) | Routinewinst (productie / distributie / marketing) |
| Qualifying profit (IP-attributed) | Kwalificerende winst |
| Uplift factor | Uplift-factor |
| Carry-forward loss | Voortwenteling verlies |
| Inter-company / Related body | Verbonden lichaam |
| Doorsnijdingsverbod (non-duplication rule) | Doorsnijdingsverbod |
| S&O declaration | S&O-verklaring |
| Patent | Octrooi |
| Plant breeder's right | Kwekersrecht |
| Orphan drug certification | Weesgeneesmiddel |
| Supplementary protection certificate | Aanvullend beschermingscertificaat (ABC) |
| Trade secret | Know-how / trade secret (informally "bedrijfsgeheim") |
| Vpb filing (corporate tax return) | Vpb-aangifte |
| Vpb-aangifte rule 23 | Innovatiebox-sectie regel 23 |

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Nexus uplift-factor changes (e.g., OECD BEPS Action 5 revision) | Factor (1.3) is baked into spec; if revised, spec update required. Very unlikely (BEPS 2015 is stable). |
| Loss carry-forward assignment dispute (which asset does a loss belong to?) | `CarryForwardLoss.qualifyingAssetId` is immutable once created; audit trail records origin. |
| Doorsnijdingsverbod false positives (legitimate dual-posting in GL for allocation staging) | Aggregation flagged as *warning* initially; blocking only after user ack. |
| Statutory tariff change mid-year | Tariff is per boekjaar; all calculations use year-end statutory rate (0.09 for 2024–2026). |
| Forfaitair cap binding | Aggregation surfaces cap-application event in audit trail (e.g., "profit €200k → 25% = €50k, capped to €25k"). |
| Profit-attribution method change between years | Aggregation enforces one method per `(asset, boekjaar)`; changing methods year-over-year is allowed (consistency not enforced across years). |
| Related-body cost allocation without transfer-pricing doc | `IBExpenseAllocation` has optional `transferPricingRef` field; warning if R&D → verbonden lichaam but no TP doc linked. |
| VSO (vaststellingsovereenkomst) compliance drift | Aggregation supports historical snapshots; each fiscal year locks the calculation for VSO signature. |

## Example Flow: Forfaitair vs Afpelmethode

**Forfaitair scenario**: BV has €500k operating profit, chooses 25% / €25k cap.
- Profit attribution: min(0.25 × €500k, €25k) = €25k
- Tariff (9%): €25k × 0.09 = €2.250k tax benefit (vs €129k under 25.8%)
- Nexus is NOT applied (forfaitair elects out of per-asset valuation)

**Afpelmethode scenario**: BV has software asset (S&O-verklaring), €800k opbrengst.
- Gross profit after direct costs: €500k
- Routine profit (mfg + distrib + marketing): €300k
- Qualifying profit (IP residual): €200k
- Nexus calculation: own R&D €480k, third-party €120k, related-party €80k → 
  (1.3 × (€480k + €120k)) / (€680k) = 1.147, capped to 1.0 → 100%
- Qualifying profit after nexus: €200k × 1.0 = €200k
- Tariff (9%): €200k × 0.09 = €18k tax benefit

## Example: Doorsnijdingsverbod Detection

R&D employee charged to project "Platform" costs €60k. Allocator marks as 
`exclusiefInWinstbepaling: true`, assigns to `IBExpenseAllocation` on asset 
"Slimme routeringsalgoritme".

GL posting also appears in account 4100 (R&D-loonkosten) with kostenplaats 
"Platform-team". Doorsnijdingsverbod aggregation flags: *"€60k (account 4100, 
kostenplaats Platform-team) appears in both innovatiebox allocation AND GL 
regular deduction. Resolve before year-end close."*

User action: remove GL posting from 4100 (it's now exclusively in innovatiebox 
track), OR remove the allocation (if a mistake).

## Audit Trail Example

2024-03-15, Nexus calculation for asset qa-2024-001:
- Event: `NexusCalculation.created`
- Details: eigen_rd_kosten €480k, uplift_factor 1.3, nexus_teller_na_uplift 
  €780k, nexus_noemer €680k, nexusbreuk_ongecapt 1.147, nexusbreuk_toegepast 1.0
- Actor: controller@bv-x.nl
- Status: calculated

2024-12-31, Vpb filing freeze (year-end close):
- Event: `IBProfitAttribution.finalized`
- Details: methode afpelmethode, kwalificerende_winst_na_nexus €200k, tariff 0.09, 
  vpb_impact €18k
- Actor: fiscalist@bv-x.nl
- Status: locked (no further changes allowed until amended aangifte)

All events are immutable per OR audit-trail-immutable (ADR-022).
