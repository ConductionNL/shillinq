# Proposal: bookkeeping-vpb-mkb

`kind: config` per ADR-032 — the centre of mass is declarative schema definitions
(`Belastingplichtige`, `VpbAangifte`, `FiscaleCorrectie`, `Innovatiebox`,
`Deelneming`, `FiscaleEenheid`, etc.) with state-machine workflows for the
aangifte → aanslag → bezwaar/beroep lifecycle. No PHP tax calculation service is
authored; all fiscal corrections, faciliteits-claims, tariff application, and
termijn-bewaking are declarative schema rules + aggregation queries.

## Summary

Introduce the **Vpb-aangifte (Vennootschapsbelasting) capability for BV/NV regular
income tax filing** as one of the T3 regulatory + compliance capabilities (per
`adr-001-bookkeeping-tier-roadmap.md`). This change declares the core Vpb register
with 13 primary entities:

- `Belastingplichtige` — belaste onderneming (KvK/RSIN, rechtsvorm, boekjaar)
- `VpbAangifte` — annual corporate tax return (concept → ingediend → aanslag → bezwaar/beroep)
- `FiscaleCorrectie` — line-by-line fiscal adjustment (commercial winst → fiscal winst)
- `Innovatiebox` — R&D facility claim (forfaitair or werkelijke-winst method, S&O-verklaring)
- `Deelneming` — shareholding (>=5% test, deelnemingsvrijstelling cumulatieve tests)
- `FiscaleEenheid` — consolidated tax group (voeging, ontvoeging, voorvoegingsverlies tracking)
- `Voorvoegingsverlies` — loss-carryforward (per-year regime: 9yr pre-2019, 6yr 2019-2021, unlimited post-2022 with 50% cap)
- `InvesteringsAftrek` — investment credit (KIA/EIA/MIA/Vamil, cumulation rules, RVO meldingen)
- `VoorlopigeAanslag` — provisional assessment (inspecteur-opgelegd, herzieningsverzoek)
- `DefinitieveAanslag` — final assessment (inspecteur-vastgesteld, bezwaartermijn 6 weken)
- `BezwaarBeroep` — dispute workflow (bezwaar → uitspraak → beroep → hoger beroep → cassatie, termijnen)

The aangifte lifecycle is a declarative `x-openregister-lifecycle` on `VpbAangifte`
with states (concept, ingediend, aanslag-ontvangen, bezwaar, beroep, onherroepelijk).
Fiscal corrections are supplied by the fiscalist (or DGA) using a structured form;
the system applies schijftarieven per belastingjaar, binds commerciële winst to
jaarrekening, enforces one-aangifte-per-jaar constraint, and generates the SBR-XBRL
instance for Digipoort submission signed with eHerkenning EH3.

This change conforms to the shared `nextcloud-app` spec for app structure and
`ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- [`bookkeeping-financial-statements`](../add-shillinq-financial-statements/proposal.md) — commerciële winst van vastgestelde jaarrekening (binding constraint on aangifte submission)
- [`bookkeeping-sbr-xbrl-reporting`](../bookkeeping-sbr-xbrl-reporting/proposal.md) — SBR-XBRL-instance generation, NT-taxonomie, Digipoort koppelvlak
- [`bookkeeping-general-ledger`](../add-shillinq-general-ledger/proposal.md) — grootboekposten waarop fiscale correcties zich afspelen
- [`bookkeeping-tax-calendar`](../bookkeeping-tax-calendar/proposal.md) — aangifte-termijnen, aanslag-ontvangst-datums, bezwaar-termijnen

## Motivation

Vennootschapsbelasting (Vpb) is mandatory for all BV/NV entities in the Netherlands.
The annual aangifte (tax return) is wettelijk vereist within 5 maanden na boekjaar
(extensie tot 11 maanden possible), filed digitally via SBR/Digipoort using the
Nationale Taxonomie (XBRL-format). The process involves:

1. Reconcile commercial accounts (jaarrekening) to tax basis (fiscale winst)
2. Claim applicable tax facilities (innovatiebox, deelnemingsvrijstelling, investeringsaftrek)
3. Apply schijftarieven (2026: 19% on first €245k, 25.8% on excess) to calculate tax due
4. Submit XBRL instance to Belastingdienst via Digipoort
5. Receive definitieve aanslag; if disputed, follow bezwaar/beroep procedure

Today, most MKB rely on external belastingadviseur (EUR 1-3K per year) to manage the
entire flow, often on Excel + email. Shillinq's Vpb-aangifte capability automates the
reconciliation (commercial → fiscal), enforces facility-eligibility tests, calculates
tax due deterministically, and generates the SBR submission ready for Digipoort without
manual XBRL authoring.

Per ADR-031, all fiscal-correction rules, facility-eligibility tests, tariff
application, and bezwaar-termijn tracking are declarative metadata: schema validators,
aggregation queries, and state machines. No PHP tax calculator service is written.

This is one of the T3 regulatory changes; this proposal scopes only the vpb-mkb slice
(reguliere Vpb for BV/NV, not Vpb-plicht for overheidsondernemingen, not Vpb for
non-residents).

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-vpb-mkb`); declares
  13 primary registers + 1 parameterization table (VpbTariefcatalogus) with
  lifecycles + aggregations + state machines; adds 5 manifest navigation entries
  (Belastingplichtigen, Vpb-Aangiftes, Fiscale Correcties, Faciliteitenberekening,
  Bezwaar/Beroep).
- [ ] Project: bookkeeping-sbr-xbrl-reporting — consumes existing SBR-instance-
  generation and Digipoort-koppelvlak (no new source changes required).
- [ ] Project: bookkeeping-tax-calendar — optionally integrates aangifte/aanslag/
  bezwaar dates for calendaring; no mandatory coupling.
- [ ] Project: decidesk — (optional T4) bezwaar/beroep governance approvals for
  high-value disputes (>EUR 100K disputed amount).

## Scope

### In Scope

- One new capability spec (`bookkeeping-vpb-mkb`) — see the `specs/` folder.
- 13 primary registers: `Belastingplichtige`, `VpbAangifte`, `FiscaleCorrectie`,
  `Innovatiebox`, `Deelneming`, `FiscaleEenheid`, `Voorvoegingsverlies`,
  `InvesteringsAftrek`, `VoorlopigeAanslag`, `DefinitieveAanslag`, `BezwaarBeroep`.
- 1 parameterization table: `VpbTariefcatalogus` (schijftarieven per belastingjaar,
  facility percentages, drempelbedragen).
- Fiscal correction workflow: commerciële winst (from jaarrekening) + FiscaleCorrectie
  records (line-by-line NTP-classified adjustments) → fiscale winst per REQ-001 & REQ-002.
- Schijftarief application: 2026 rates (19% / 25.8%) parameterized by belastingjaar
  per REQ-003.
- Innovatiebox: forfaitair (tot €25k voordeel, 3-jaar limitatie) en werkelijke-winst
  (nexus-factor R&D) methods per REQ-004; S&O-verklaring (RVO) verplicht onderbouwing.
- Deelnemingsvrijstelling: Article 13 Wet Vpb with three cumulative tests
  (oogmerktoets, onderworpenheidstoets, bezittingentoets) per REQ-005;
  low-tax-portfolio-investment detection.
- Voorvoegingsverlies (loss carryforward): per-verliesjaar regime (9yr pre-2019,
  6yr 2019-2021, unlimited post-2022 with 50%-cap on winsten >€1M) per REQ-006.
- Fiscale eenheid: Article 15 voeging/ontvoeging with >=95% bezit + gelijke boekjaren
  + NL vestiging enforcement; per-dochter voorvoegingsverlies tracking per REQ-007.
- Investeringsaftrek (KIA/EIA/MIA/Vamil): cumulation rules (KIA+EIA OK, KIA+MIA OK,
  EIA+MIA NOT OK), minima/maxima per belastingjaar per REQ-008.
- SBR-XBRL aangifte: NT-taxonomie-conform instance generation, PKIO-Digipoort signing,
  eHerkenning EH3 requirement, Digipoort receipt persistence per REQ-009.
- Bezwaar/beroep workflow: state machine (bezwaar → inspecteur-uitspraak → beroep →
  hoger-beroep → cassatie) with statutory termijnen (6 weken bezwaar, 6 weken
  inspecteur-uitspraak extendable to 12, 6 weken beroep) and escalation alerts per
  REQ-010.
- One-aangifte-per-jaar constraint: REQ-001 blocks creation of duplicate aangifte
  until prior is onherroepelijk or formally heropened.
- Jaarrekening binding: REQ-002 binds commerciële winst FK to specific vastgestelde
  jaarrekening-version; prevents aangifte-ingediend if jaarrekening not AvA-approved.

### Out of Scope

- Vpb-plicht for overheidsondernemingen (separate cap in T4).
- Non-resident Vpb (Vpb voor niet-ingezeten; Artikel 1b Wet Vpb).
- Advanced facility orchestration (decidesk approval for material amendments) — T4.
- Real-time Belastingdienst data-feed (aanslag-ontvangst automation) — T4 via Logius
  connector.
- Transfer pricing (Article 8b Wet Vpb) beyond documentation frameworks — T4.
- Thin capitalization (rentebeperking Article 3 Interest Deduction Limitation) — T4.

## Risks & Trade-offs

| Risk | Mitigation |
|---|---|
| Fiscal corrections supplied manually by fiscalist; correctness depends on operator expertise and NTP-classification accuracy | Spec-level audit trail + NTP-code validation against NT-taxonomie; external accountant review in jaarrekening audit |
| Schijftarieven + facility rules change annually (Belastingplan); Shillinq tariff table must stay current | VpbTariefcatalogus parameterization per belastingjaar; tariff-maintenance process integrated into annual Belastingplan cycle (post-September each year) |
| Innovatiebox claim requires S&O-verklaring (RVO); without it, claim is invalid; blocking claim in UI may create friction | Mandatory S&O-reference field on Innovatiebox record; claim rejected at schema-validation level if missing |
| Voorvoegingsverlies per-year regime (9yr vs 6yr vs unlimited) is complex; verjaring must be tracked accurately else lost deduction | Voorvoegingsverlies table with explicit verliesjaar + verjaartIn (computed) fields; UI shows expiration warning 12 months before verjaring |
| Fiscale eenheid voegingen carry meerjarige voorvoegingsverlies consequences; unvoiced voeging can destroy dormant-loss tax positions | Pre-voeging validation enforces Article 15 conditions + warns on voorvoegingsverlies impact; dochter-specific loss tracking prevents loss of restricted-voeging carryforwards |
| Bezwaar/beroep termijnen are hard statutory deadlines; missing termijn = aanslag onherroepelijk + direct financial loss | Termijn-bewaking calendar events + escalation alerts at T-7d, T-3d, on-day; termijn-passed → red-flag in aanslag record |
| SBR-XBRL generation requires NT-taxonomie compliance; non-compliant instances rejected by Digipoort | Spec integration with bookkeeping-sbr-xbrl-reporting validates against NT-taxonomie XSD pre-submission; Digipoort receipt validates post-submission |
| eHerkenning EH3 certificate on Belastingplichtige required for Digipoort signing; DGA/fiscalist cert NOT sufficient | Belastingplichtige.digipoortCertificaat mandatory FK to credential vault; UI rejects aangifte-ingediend if cert missing or expired |
| Deelnemingsvrijstelling low-tax-portfolio-investment test is jurisprudence-casuïstisch; auto-flag but defer decision to fiscalist | System flags potential low-tax portfolio on deelnemingsvrijstelling claim; fiscalist overrides flag with written motivation; audit trail preserves decision |

## Rollback

Vpb-aangifte is non-reversible once submitted to Belastingdienst (Digipoort receipt
proof of submission). Rollback occurs only if the spec is rejected before any entity
files production aangifte. Once live and submitted, corrections are made via
bezwaar (dispute) or amended aangifte (Herstelkans procedure per Awb Article 4:6).

## Open Questions

1. **Digipoort cert management**: Belastingdienst requires eHerkenning EH3 on
   Belastingplichtige; MKB often lack in-house cert. Should Shillinq support
   Servicegerichte Architectuur (SGA) intermediary certs (fiscalist signs for entity)?
   Recommend SGA support in v1 for MKB without in-house PKIO cert.

2. **Inspecteur aanslag feed**: Belastingdienst may push aanslag notification via
   Logius; should Shillinq listen to that stream for auto-triggering DefinitieveAanslag
   workflow? Recommend manual entry in v1, Logius connector in T4.

3. **Deelnemingsvrijstelling low-tax-portfolio-investment detection**: Jurisprudence
   on "substantief economisch oogmerk" varies (Argenta, Bricolage, Saladin doctrine).
   Should system auto-flag all shareholdings or only known-doubtful structures?
   Recommend flag-all + fiscalist discretion.

4. **Voorvoegingsverlies verjaaring rule change (2022)**: Transition from 9yr
   (pre-2019) to unlimited (post-2022, 50%-capped) is complex for entities spanning
   both regimes. Should verjaar-date calculation automatically apply transition logic?
   Recommend per-verliesjaar regime application with UI warning on hybrid year-end.

5. **Transfer pricing (Article 8b)**: Scope excluded, but Article 8b documentation
   (per Wpfb rules) often gets entangled in Vpb dispute. Should Vpb-aangifte accept
   TP-documentation attachments as metadata for auditor reference? Recommend
   metadata-only (docudesk FK) in v1.

## Dependencies

- **bookkeeping-financial-statements**: Commerciële winst van vastgestelde jaarrekening
  is mandatory input to VpbAangifte; binding constraint per REQ-002.
- **bookkeeping-sbr-xbrl-reporting**: SBR-XBRL-instance generation + Digipoort
  koppelvlak + NT-taxonomie per REQ-009.
- **bookkeeping-general-ledger**: Grootboekposten voor FiscaleCorrectie-mapping +
  GL posting of final Vpb liability.
- **bookkeeping-tax-calendar**: Optional; aangifte-termijn, aanslag-ontvangst-datum,
  bezwaar-termijn calendaring.

## Success Criteria

- Fiscalist can create Vpb-aangifte (concept), enter FiscaleCorrectie line-by-line,
  claim facilities (innovatiebox, deelnemingsvrijstelling, investeringsaftrek) with
  required supporting docs, review calculated verschuldigde Vpb, and submit SBR-XBRL
  to Digipoort without external tax software.
- System enforces one-aangifte-per-jaar, binds commerciële winst to vastgestelde
  jaarrekening, applies schijftarieven deterministically per belastingjaar.
- Facility claims are validated against eligibility tests (S&O-verklaring for
  innovatiebox, three-test cumulative for deelnemingsvrijstelling, cumulation rules
  for investeringsaftrek); invalid claims are rejected at submission.
- Voorvoegingsverlies is tracked per-verliesjaar with expiration warnings; verjaring
  dates are computed correctly per regime (9yr / 6yr / unlimited-50%).
- Bezwaar/beroep workflow supports stateful dispute tracking with termijn-bewaking;
  missed termijnen are prominently flagged.
- SBR-XBRL instance is NT-taxonomie-compliant; submission to Digipoort succeeds on
  first attempt (no validation rejections).
- Audit trail on all fiscal corrections, facility claims, amendments, and approvals
  is visible for external accountant review.
