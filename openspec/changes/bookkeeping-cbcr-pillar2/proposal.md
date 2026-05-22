# Proposal: bookkeeping-cbcr-pillar2

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`group-entity-registry`, `cbcr-jurisdiction-summary`,
`pillar2-jurisdiction-computation`, `pillar2-safe-harbour`, `qdmtt-return`,
`globe-information-return`, `cbcr-return`, `tax-treaty-overview`) + 
`x-openregister-lifecycle` for annual CbCR / Pillar 2 reporting workflows.
No PHP GloBE calculation service is authored; all corrections and ETR
computations are declarative per ADR-031.

## Summary

Introduce the **Country-by-Country Reporting (CbCR)** and **OESO Global
Anti-Base Erosion (GloBE) / Pillar Two (Global Minimum Tax)** reporting
capability for Shillinq. This change declares eight new registers to handle
multinationals with consolidated group revenue ≥ EUR 750M:

- `group-entity-registry` — multinationale groepentiteiten met consolidatiedatails
- `cbcr-jurisdiction-summary` — per-jurisdictie CbCR aggregatie (7 verplichte velden)
- `pillar2-jurisdiction-computation` — per-jurisdictie Pillar 2 ETR + top-up tax
- `pillar2-safe-harbour` — transitional safe harbour tests (de minimis, simplified ETR, routine profits)
- `qdmtt-return` — Nederlandse QDMTT-aangifte (Qualified Domestic Minimum Top-up Tax)
- `globe-information-return` — GIR XML/XBRL indiening (OESO schema)
- `cbcr-return` — CbCR XML rapport (OESO schema)
- `tax-treaty-overview` — DTA referentie voor withholding correcties

The complete pipeline: per-jurisdictie aggregatie van financiële kerncijfers
→ GloBE-correcties (35 voorgeschreven posten) → ETR berekening per jurisdictie
→ top-up tax en IIR/UTPR/QDMTT toewijzing → XML/XBRL-export voor
Belastingdienst / OESO indiening.

**Depends on:**
- [`bookkeeping-consolidation-commercial`](../bookkeeping-consolidation-commercial/proposal.md) — per-jurisdictie aggregatie van financiële kerncijfers
- [`bookkeeping-deferred-tax`](../bookkeeping-deferred-tax/proposal.md) — adjusted covered taxes (Vpb + timing differences)
- [`bookkeeping-vpb-mkb`](../add-shillinq-vpb-corporate-tax/proposal.md) — Vpb cijfers en Belastingdienst aangifte
- [`bookkeeping-fixed-assets-depreciation`](../add-shillinq-fixed-assets-depreciation/proposal.md) — tangible assets voor CbCR + Pillar 2 SBIE
- [`hrmq`](../../hrmq) — payroll per jurisdictie voor FTE en SBIE carve-out

## Motivation

CbCR verplicht sinds 1-1-2016 voor multinationale groepen ≥ EUR 750M.
Pillar 2 sinds 31-12-2023. Beide vereisen aanlevering bij Belastingdienst
via SBR/XBRL; administratieve lasten zijn groot (EUR 200K–2M jaarlijks
consultancy voor grotere groepen). Deze spec integreert de complete
pipeline in Shillinq: consolidatiedata is al present; de GloBE-correcties
worden declaratief toegepast; XML-export gebeurt automatisch. Voor
multinationals die richting EUR 750M groeien (300+ NL groepen 2025–2030)
is dit een differentiating capability.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-cbcr-pillar2`); declares 8 new registers
  (`group-entity-registry`, `cbcr-jurisdiction-summary`,
  `pillar2-jurisdiction-computation`, `pillar2-safe-harbour`,
  `qdmtt-return`, `globe-information-return`, `cbcr-return`,
  `tax-treaty-overview`) with lifecycles + XML export; adds 5 manifest
  navigation entries (Entity Registry, CbCR Summaries, Pillar 2 Computations,
  GIR/QDMTT Returns, Safe Harbour Tests).
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-lifecycle`, `x-openregister-aggregations` for
  per-jurisdictie summaries, GloBE-correctie applicatie, ETR berekening.
- [ ] Project: openconnector — optional integration for automated SBR/Digipoort
  submission (T4) of XML exports via PKIoverheid services.
- [ ] Project: decidesk — routing van materiële Pillar 2 uitkomsten
  (top-up tax > EUR 500K) voor audit committee goedkeuring voordat indiening.

## Scope

### In Scope

- Eén capability spec (`bookkeeping-cbcr-pillar2`) — zie `specs/` folder.
- 8 registers: `group-entity-registry` (entiteiten + consolidatiedatails),
  `cbcr-jurisdiction-summary` (7 CbCR velden per jurisdictie),
  `pillar2-jurisdiction-computation` (ETR, GloBE income, top-up tax),
  `pillar2-safe-harbour` (transitional tests), `qdmtt-return` (NL aangifte),
  `globe-information-return` (GIR), `cbcr-return` (CbCR), `tax-treaty-overview`
  (DTA referentie).
- EUR 750M drempeldetectie op basis voorgaand boekjaar consolidatieomzet.
- CbCR aggregatie: omzet derden + intragroep, winst vóór belasting, Vpb cash,
  Vpb accrual, aandelenkapitaal, ingehouden winsten, FTE, materiële vaste activa.
- Pillar 2 ETR berekening: GloBE income (commercieel + 35 OESO-correcties),
  adjusted covered taxes (Vpb + soortgelijke heffingen), ETR = taxes / GloBE income.
- Top-up tax: (15% − ETR) × (GloBE income − SBIE), met SBIE carve-out (5% payroll + 5% tangible assets, afgebouwd 2023–2033).
- QDMTT prioriteit: NL QDMTT vóór IIR-claim van hoger gelegen moeder.
- Safe harbour tests: de minimis (omzet < EUR 10M + winst < EUR 1M), simplified ETR (≥15% in 2024), routine profits.
- XML/XBRL export: OESO CbC schema v2.0, OESO GIR schema, NL aangifte minimumbelasting.
- Reconciliatie met geconsolideerde jaarrekening: CbCR-totaal ↔ groepsjaarrekening.

### Out of Scope

- Geen PHP GloBE calculatieservice (GlobeIncomeCalculator.php).
- Geen real-time asset-management connectors (Bloomberg, FactSet).
- Geen transfer pricing documentatie generatie (separate spec).
- Geen DNB/pensioenuitvoerder rapportages.
- Geen SBR/Digipoort submission automation (T4; openconnector owned).

## Risks & Trade-offs

| Risk | Mitigation |
|---|---|
| Consolidatiedata van schakels kan incompleet/onnauwkeurig zijn; CbCR-totaal stemt niet af op groepsjaarrekening | Reconciliatierapport verplicht (REQ-CBC-010); residueel verschil > EUR 1M gemerkt als ongereconcilieerd; controller sign-off voordat indiening |
| Payroll & tangible assets per jurisdictie (voor SBIE carve-out) ontbreken; HRMQ/Fixed Assets integratie heeft lag | Fallback op prior-year data als current niet beschikbaar; waarschuwing aan controller; optionele manual override |
| GloBE-correcties (35 posten) interpretatie verschilt per adviesbureau; Shillinq-berekening divergeert van externe actuaris | Gedetailleerde audit trail op alle correcties; sensitivity analyse op hoofdaannames (ETR gevoeligheid); connector API (T4) voor structured feed van Big-4 |
| Safe harbour testen zijn transitional (2024–2026); complexiteit in overgangspercentages | Schema enforcement op planningperiode; waarschuwing bij einde overgangsperiode; upgrade procedure voor 2027+ permanente regime |
| QDMTT-bedrag kan conflicteren met buitenlandse IIR-berekening; coördinatie met UPE-jurisdictie moeilijk | QDMTT return separaat opgesteld; credit-mechanism in GIR automatisch; audit trail op toewijzingslogica |

## Rollback

CbCR / Pillar 2 zijn wettelijk verplicht indieningen. Rollback is mogelijk
tot en met filing deadline (12 maanden CbCR, 15–18 maanden GIR na FYE), mits
geen indiening gedaan. Na indiening is correctie via amended return, niet
deletion.

## Open Questions

1. **Consolidatiemethode variant**: Proportional consolidation vs equity method
   voor JV's — impact op per-jurisdictie summaries. Richtlijn: volg
   geconsolideerde jaarrekening methode.
2. **Actuarial input source**: GloBE-correcties handmatig entry (v1) vs
   connector feed van Big-4 bureaus (T4). Aanbeveling: v1 manual.
3. **Payroll definition**: Bruto of netto; incl. soziale bijdragen? RJ 271
   standaard vs practijk. Aanbeveling: OESO guidance volgen.

## Dependencies

- **bookkeeping-consolidation-commercial**: Per-jurisdictie aggregatie input.
- **bookkeeping-deferred-tax**: Adjusted covered taxes (Vpb + DTA impact).
- **bookkeeping-vpb-mkb**: Vpb cash & accrual per entiteit.
- **bookkeeping-fixed-assets-depreciation**: Tangible assets NBV per jurisdictie.
- **hrmq**: Payroll & FTE per jurisdictie.

## Success Criteria

- Head of Tax / Global Tax Director kan een multinationale groep registreren,
  per-jurisdictie CbCR aggregatie en Pillar 2 ETR automatisch laten berekenen,
  safe harbour tests toepassen, QDMTT/IIR/UTPR toewijzing zien, en CbCR XML
  + GIR XML + NL QDMTT-aangifte exporteren zonder handmatig Excel-werk.
- EUR 750M drempel automatisch gedetecteerd; controller gewaarschuwd voor
  eerste CbCR indiening (12 maanden na FYE) + eerste GIR (18 maanden).
- Reconciliatie CbCR ↔ groepsjaarrekening getoond; residueel verschil
  ongereconcilieerd gemarkeerd als > EUR 1M.
- Audit trail op alle GloBE-correcties, ETR-berekeningen, safe-harbour tests
  en QDMTT/IIR-toewijzingen zichtbaar voor externe accountant.
- XML-exports conform OESO CbC v2.0 en GIR schema's; klaar voor SBR/Digipoort
  indiening.
