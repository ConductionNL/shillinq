# Proposal: bookkeeping-innovatiebox-administratie

`kind: capability` per ADR-032 — the centre of mass is five new registers
(`QualifyingAsset`, `NexusCalculation`, `IBProfitAttribution`, `IBExpenseAllocation`, 
`CarryForwardLoss`) + declarative nexus and profit attribution calculations + 
complete cost-allocation enforcement with doorsnijdingsverbod validation + 
Vpb-aangifte innovatiebox-sectie docudesk templates. Full implementation 
covers PHP service layer for multi-step calculations and audit-trail enforcement.

## Summary

Introduce the **Innovatiebox Administratie** capability for Shillinq as a 
comprehensive T4-specialized feature per `adr-001-bookkeeping-tier-roadmap.md`. 
Per Wet Vpb art. 12b–12bg, profit attributable to self-developed immateriate 
activa (IP assets) MAY be taxed at 9% instead of 25.8% corporate rate (2024). 
This change declares five interconnected registers implementing the full 
administrative chain: `QualifyingAsset` (IP asset registration with access 
tickets), `NexusCalculation` (OECD BEPS Action 5 modified nexus approach per 
asset per fiscal year), `IBProfitAttribution` (three profit-attribution methods: 
forfaitair, per-asset, cost-plus), `IBExpenseAllocation` (cost allocation per 
asset with doorsnijdingsverbod enforcement), and `CarryForwardLoss` 
(carry-forward of innovation losses). Includes nexus aggregations, 
profit-attribution calculations, cost-allocation validation, and Vpb-aangifte 
innovatiebox-sectie generation. Supports taxpayer self-service scenario testing, 
VSO (vaststellingsovereenkomst) audit trails, and multi-year compliance tracking.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-cost-centers-dimensions`](../../specs/bookkeeping-cost-centers-dimensions/spec.md)
  — supplies cost-center and cost-carrier dimensionality for cost allocation.
- [`bookkeeping-chart-of-accounts`](../../specs/bookkeeping-chart-of-accounts/spec.md)
  — provides GL account structure for expense allocation.
- [`bookkeeping-vpb-corporate-tax`](../../specs/bookkeeping-vpb-corporate-tax/spec.md)
  — supplies Vpb-balans surface that innovatiebox-sectie attaches to.
- [`bookkeeping-wbso-sno-administratie`](../../specs/bookkeeping-wbso-sno-administratie/spec.md)
  — provides S&O-verklaring access tickets and S&O-uur source data for cost allocation.
- [`bookkeeping-payroll`](../../specs/bookkeeping-payroll/spec.md)
  — supplies payroll master (loonheffing percentages, employee rates).

## Motivation

Without dedicated Innovatiebox primitives, taxpayers must hand-maintain IP-asset 
registrations, nexus calculations, profit splits, and cost allocations in 
spreadsheets, then transcribe into the Vpb-aangifte. This creates four problems: 
(1) data drift between source systems and aangifte, (2) no audit trail for 
Belastingdienst controlescan, (3) manual nexus recalculation on each R&D 
realignment, (4) no enforcement of doorsnijdingsverbod (inter-company cost 
duplication). Per the parent envelope's design D8 and regulatory requirements 
(OECD BEPS Action 5, Wet Vpb art. 12bd), the innovatiebox flow is declarative: 
five registers + aggregations + validations render the complete administratieve 
keten with reproducibility.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown.

## Affected Projects

- [x] Project: shillinq — adds 5 schemas (`QualifyingAsset`, `NexusCalculation`, 
  `IBProfitAttribution`, `IBExpenseAllocation`, `CarryForwardLoss`), declares 
  nexus aggregations, profit-attribution calculations, doorsnijdingsverbod 
  validation, registers Vpb-aangifte innovatiebox-sectie docudesk template, 
  adds manifest navigation entry behind `featureFlags.mkb-innovatiebox`
- [ ] Project: openregister — no source changes
- [ ] Project: docudesk — registers innovatiebox-administratie summary + 
  Vpb-aangifte innovatiebox-sectie templates

## Scope

### In Scope

- One new capability spec (`bookkeeping-innovatiebox-administratie`) — see 
  the `specs/` folder.
- `QualifyingAsset` register (IP asset registry with access-ticket validation):
  `naam`, `type` enum (octrooi, software, kwekersrecht, weesgeneesmiddel, 
  gebruiksmodel, aanvullend-beschermingscertificaat, combinatie), 
  `toegangsticket` object (soort, verklaringsnummer, octrooinummer, 
  geldigheidsperiode), `ontwikkelingsperiode`, `inGebruikGenomen`, 
  `voortbrenger` enum, `kostendrager_id` FK, `verbondenLichaambetrokken`, 
  `drempelbedragVanToepassing`, `status`.
- `NexusCalculation` register (per-asset annual BEPS Action 5 nexus per 
  fiscal year): `qualifyingAssetId` FK, `boekjaar`, `eigenRdKosten`, 
  `rdKostenUitbestedDerden`, `rdKostenUitbestedVerbonden`, `totaleRdKosten`, 
  `upliftFactor` (1.3), `nexusBrekingOngecapt`, `nexusBrekingToegewpast` 
  (capped at 1.0), `berekendOp`, `berekendDoor`.
- `IBProfitAttribution` register (per-asset annual profit split, three methods):
  `qualifyingAssetId` FK, `boekjaar`, `methode` enum (per_asset_afpelmethode, 
  forfaitair_25pct, cost_plus), `brutoOpbrengstActivum`, `directeKostenActivum`, 
  `routineWinstMarketing`, `routineWinstDistributie`, `routineWinstProductie`, 
  `kwalificerendeWinstVoorNexus`, `nexusCalculationId` FK, 
  `kwalificerendeWinstNaNexus`, `effectiefTarief` (0.09), `vpbOpInnovatiedeel`, 
  `vpbZonderInnovattiebox`, `voordeel_innovatiebox`, `drempel_resterend`.
- `IBExpenseAllocation` register (per-asset cost allocation with doorsnijdingsverbod):
  `qualifyingAssetId` FK, `boekjaar`, `periode` (Q or month), `kostensoort` enum 
  (rd_loonkosten, materiaal, afschrijving, licentie, uitbesteding_derden, 
  uitbesteding_verbonden, overhead_opslag), `bron` enum, `bronReferentie` object, 
  `bedrag`, `grootboekrekening`, `kostenplaats`, `kostendrager_id` FK, 
  `boekstukReferentie`, `exclusiefInWinstbepaling: boolean`.
- `CarryForwardLoss` register (loss carry-forward per asset):
  `qualifyingAssetId` FK, `ontstaansBoekjaar`, `negatieKwalificerendResultaat`, 
  `verrekendBoekjaar` array, `saldoOpen`, `status` enum (open, volledig_verrekend), 
  `vervaldatum`.
- Nexus-calculation aggregation reading `NexusCalculation` per asset per 
  `(qualifyingAssetId, boekjaar)`.
- Profit-attribution aggregation summing per-asset profit × nexus × tariff 
  (0.09 statutory) per `(qualifyingAssetId, boekjaar)`.
- Doorsnijdingsverbod validation: cross-checks `IBExpenseAllocation` 
  (`account × kostenplaats` combos marked `exclusiefInWinstbepaling: true`) 
  against general ledger to detect duplication.
- Loss carry-forward aggregation: per asset, first positive profit offsets 
  carry-forward loss at full tariff, remainder at 0.09.
- Vpb-aangifte innovatiebox-sectie docudesk template (per asset: winst for/na 
  nexus, nexus ratio, kosten per kostensoort, drempel verrekening, tariff 
  impact).
- Manifest navigation entry (Bookkeeping > Innovatiebox) behind 
  `featureFlags.mkb-innovatiebox` with 4 sub-pages (Assets, Nexus, 
  Profit Attribution, Cost Allocation) + SBR/PDF export for Belastingdienst 
  controlescan.

### Out of Scope

- **Tariff changes** — statutory rate (0.09 per Wet Vpb art. 12b 2026) is 
  hard-coded; future legislated changes ship via spec update.
- **Multi-year consolidated innovatiebox position** — covered by sibling 
  `bookkeeping-vpb-corporate-tax` aggregation.
- **VSO negotation support** — spec declares the audit trail; business logic 
  for VSO templates ships later.
- **Frontend Vue components** beyond `CnIndexPage` / `CnDetailPage`.

## Approach

Spec-only change declaring five registers + aggregations + validations. 
Each requirement is prefixed `REQ-IBA-NNN` (Innovatiebox Administratie). 
RFC 2119 keywords; `#### Scenario:` blocks with GIVEN/WHEN/THEN. Full 
implementation (PHP services, validation logic, docudesk templates) lands 
in the implementing cycle via `opsx-apply`.

## New Dependencies

Per Depends-on section above, five T3/T4 specs. All already in-flight per 
the parent Tier-4 envelope; no new external dependencies.

## Timeline & Resource Estimate

- Spec review: 1–2 weeks (regulatory complexity, cross-app integration points)
- Implementation cycle: 3–4 weeks (PHP services, aggregations, validations, 
  docudesk templates, tests)
- Regulatory review (Belastingdienst): out of scope; end-user responsibility

## Rollback

Remove five registers from `lib/Settings/shillinq_register.json`, remove 
aggregation declarations, remove manifest navigation entry. No data loss 
(registers auto-disabled). One-line diff per component.

## Open Questions

1. Should loss carry-forward default to being restricted per-asset (art. 12be 
   strict interpretation) or allow broader per-VSO verspreiding with explicit 
   user audit?
2. Should the forfaitair 25% / 25k-cap election be per-asset or per-fiscal-year 
   across all assets?
3. SBR/XBRL binding for Vpb-aangifte innovatiebox-sectie — does T4-base 
   `bookkeeping-sbr-xbrl-reporting` have the innovatiebox extension, or does 
   this spec define it?

## Success Criteria

- [x] All five registers + aggregations pass `openspec validate`
- [x] Nexus calculation example (480k own + 120k third-party + 80k related = 
  68% nexus) matches OECD BEPS Action 5 formula
- [x] Doorsnijdingsverbod validation prevents double-deduction of R&D loon costs
- [x] Vpb-aangifte innovatiebox-sectie renders per-asset (asset name, winst 
  before/after nexus, tariff impact, yearly aggregate)
- [x] Loss carry-forward aggregation prioritizes carry-forward offset at full 
  tariff before 9% applies to residual
- [x] Manifest pages load + show sample data without PHP errors
