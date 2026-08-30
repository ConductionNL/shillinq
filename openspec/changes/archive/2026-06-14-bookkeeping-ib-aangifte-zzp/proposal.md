# Proposal: bookkeeping-ib-aangifte-zzp

## Summary

Introduce the **IB Aangifte (Income Tax Return) Assembly for ZZP** capability as a T3 regulatory + compliance feature for Shillinq. This change enables ZZP'ers (self-employed freelancers) and small entrepreneurs to automatically assemble, validate, and submit their annual income tax return (P-formulier) directly to the Dutch Belastingdienst via SBR/XBRL or through a fiscal intermediary (Becon-route).

The spec declares:
- **IBAangifte** — main entity representing the annual tax return with status, validation, filing channel
- **IBWinstOpgave** — profit & loss detail with fiscal-commercial adjustments (art. 3.15, goodwill limits, representation allowances)
- **IBOndernemersaftrek** — self-employed deductions (zelfstandigenaftrek, starter's deduction, MKB exemption, KIA/EIA/MIA investment allowances)
- **IBHeffingskortingenAlgemeen** — tax credits (algemene heffingskorting, arbeidskorting, IACK for single parents)
- **IBLijfrenteAOV** — annual and reservation pension savings room per art. 3.127 IB
- **IBBijtellingAuto** — private-use car taxation per art. 3.20 IB (22% standard bijtelling, EV-tiered rates)
- **IBBox3Vermogen** — box-3 (savings & investments) income tax with transitional law calculation
- **IBAuditTrail** — full drill-down traceability from return rubric to underlying journal entries (7-year retention per AWR art. 52)

The assembly flow is **automated**: the system aggregates GL accounts, applies fiscal adjustments per Wet IB 2001 art. 3–5, calculates relief entitlements (auto-detection via urencriterium tracker + investeringsaftrek spec), pre-fills the P-formulier, and generates a valid XBRL instance per Dutch Taxonomy (NT17 for 2025 onwards) for Digipoort submission.

**Depends on:**
- [`zzp-urencriterium-tracker`](../zzp-urencriterium-tracker/proposal.md) — validates self-employed hours threshold (≥1225) for deduction eligibility
- [`bookkeeping-investeringsaftrek`](../bookkeeping-investeringsaftrek/proposal.md) — KIA/EIA/MIA/VAMIL allowance calculation
- [`bookkeeping-ap-ar`](../bookkeeping-ap-ar/proposal.md) — revenue and cost source for profit aggregation
- [`bookkeeping-fixed-assets`](../bookkeeping-fixed-assets/proposal.md) — depreciation schedules, asset disposals
- [`bookkeeping-chart-of-accounts`](../bookkeeping-chart-of-accounts/proposal.md) — RGS-compliant GL structure for T1 mapping

## Motivation

Dutch ZZP'ers and eenmanszaken face a compliance bottleneck: the annual IB-aangifte (P-formulier) is a complex, regulation-dense form with 200+ rubrics covering winst uit onderneming (box 1), box 3 (savings), and numerous relief rules. Today, most ZZP'ers either:
1. **Do it themselves** — risking missed deductions (zelfstandigenaftrek EUR 2.470, MKB-exemption, startersaftrek) and errors that trigger Belastingdienst queries or penalties
2. **Hire a fiscalist** — EUR 500–2000/year in advisory fees, often with weeks-long backlogs near March deadlines
3. **Use paid tax-prep SaaS** — limited to P-formulier pre-fill and validation, not full audit-trail traceability or optimization suggestions

Per ADR-031 (spec-level declarative business logic), Shillinq can embed the entire IB assembly as metadata: entities for each P-formulier section, declarative fiscal-adjustment rules, and automated XBRL generation. This moves ZZP'ers from compliance-anxiety to confidence: every field is pre-filled from auditable GL data, every deduction is validated against law (urencriterium, MKB eligibility, startersaftrek timing), and the XBRL is legal-grade for Digipoort submission.

The spec also includes a **digitale belastingadviseur** (LLM+rule-based suggestion engine) that proactively surfaces optimization opportunities: "You have EUR 8.200 unused lijfrente room; a 31-Dec deposit yields EUR 3.034 tax savings" or "FOR-saldo EUR 14.300 can trigger optimal-rate release in 2027."

## Affected Projects

- [x] **shillinq** — adds 1 capability spec (`bookkeeping-ib-aangifte-zzp`); declares 8 new entities in OpenRegister schema; adds 4 manifest navigation entries (IB Aangiften, Ondernemersaftrekken, Lijfrente Beheer, Box 3 Vermogen); implements XBRL serializer conforming to NT17; implements Digipoort SOAP integration (LH-Becon route)
- [ ] **openregister** — no source changes; consumes `x-openregister-lifecycle` and `x-openregister-aggregations` for GL-to-rubriek mapping, audit-trail linking, sensitivity calculation
- [ ] **zzp-urencriterium-tracker** — no changes; provides urencriterium-rapportage API for zelfstandigenaftrek validation (REQ-IB-002)
- [ ] **bookkeeping-investeringsaftrek** — no changes; provides KIA/EIA/MIA aggregates (REQ-IB-004)
- [ ] **openconnector** — optional: SOAP/XML binding for Digipoort FRC/AGV webservice (future T4 hardening)
- [ ] **pipelinq** — optional: messaging layer for fiscalist approval workflows (Becon-route, REQ-IB-008)
- [ ] **hrmq** — optional: household & partner data for heffingskortingen calculation & gezinssituatie heuristics (REQ-IB-006, REQ-IB-010)

## Scope

### In Scope

- **8 new entities** per OpenRegister ADR-000: IBAangifte, IBWinstOpgave, IBOndernemersaftrek, IBHeffingskortingenAlgemeen, IBLijfrenteAOV, IBBijtellingAuto, IBBox3Vermogen, IBAuditTrail
- **Fiscal-commercial P&L reconciliation** per Wet IB art. 3.6–3.79a: auto-detect and apply corrections for representatiebeperking (art. 3.15), goodwill non-deductibility, auto bijtelling, home-office rules (art. 3.16–3.17)
- **Zelfstandigenaftrek** (2026: EUR 2.470 per year, afbouwpad tot EUR 1.200 in 2027 per Belastingplan 2024) — conditional on ≥1225 hours per urencriterium tracker
- **Startersaftrek** (EUR 2.123 × 3 years within first 5 years per art. 3.78)
- **MKB-winsvrijstelling** (2026: 12,7% per Belastingplan 2024; scheduled changes per Belastingplan 2025/2026)
- **Investeringsaftrek** (KIA, EIA, MIA, VAMIL per bookkeeping-investeringsaftrek spec)
- **Lijfrente jaarruimte & reserveringsruimte** (art. 3.127) with 10-year carryforward, AOV-premies (fully deductible), calc per formulae with franchise updates
- **Heffingskortingen**: algemene heffingskorting (with income-dependent phase-out), arbeidskorting (phase-out from EUR 43.071 in 2026), IACK (single parent + child <12 + working partner), ouderenkorting, jonggehandicaptenkorting
- **Bijtelling auto** per art. 3.20 (22% standard; 17% EV cap for zero-emission first EUR 30K per staffel)
- **Box 3 vermogen** per overbruggingswet (three categories: bank/spaartegoeden, overige bezittingen, schulden; werkelijk rendement or forfait per taxpayer choice)
- **Fiscale partner & verdeling** — optimalisatie-algorithms for joint-filing partner income distribution
- **XBRL instance generation** per Dutch Taxonomy NT17 (belastingjaar 2025+), valid for Digipoort FRC/AGV submission
- **Becon-route (fiscal intermediary)** — PKIoverheid-services-certificaat signing, Digipoort SOAP envelope, audit log
- **Self-service DigiD-route** — digital signature via burgerservicenummer
- **Correctieaangifte & suppletie** — diff-tracking, reclassification workflows
- **Audit trail & herleidbaarheid** per AWR art. 52 (7-year retention) — every P-formulier rubric links to underlying GL journaalposten (including inter-company allocations)
- **Pre-fill optimization suggestions** — rule-based + LLM augmented (digitale belastingadviseur)
- **M/W/C formulier routing** — auto-detection of migratie (M), loondienst + ondernemingg (W), or buitenlandse bron (C) based on profiel attributes

### Out of Scope

- **PHP actuarial calculation services** — per ADR-031, no custom tax-law-computation code; rules expressed as metadata + declarative formulae
- **Real-time Digipoort integration hardening** (certificate validation, OCSP) — T4 security audit
- **Multi-year carryforwards across fiscal years** (DBA-compliance, vermogensrendement history) — T4 phase
- **DNB insurance-company reporting** (verzekerdelyst via SBR-XBRL) — T4
- **Pension uitvoerder (pensioenfonds) reporting** — T4
- **Payroll integration** (loon-input from boekhouding-payroll for box 1 loondienst portion in W-formulier) — separate T2 spec
- **Multi-currency FX revaluation** (box 3 foreign assets) — T4 treasury integration

## Risks & Trade-offs

| Risk | Mitigation |
|---|---|
| Fiscal rules change annually (Belastingplan); coded parameters must be updated each year (zelfstandigenaftrek 2027 = EUR 1.200; MKB rate in 2025 = 12,03%, 2026 = 12,7%, 2027 TBD) | Metadata-driven: all tariffs, thresholds, franchises stored in configuration entity `IBTaxParameterYear` with per-fiscal-year versioning; deployment does not require code change |
| Urencriterium tracker unavailable or reports incomplete hours; zelfstandigenaftrek incorrectly denied or allowed | Spec-level validation (REQ-IB-002): system blocks aangifte finalization if uren-evidence missing; clear warning UX + direct link to uren-tracker repair |
| Representatiebeperking (art. 3.15) or other fiscal adjustments vary by entity type, sector, turnover; hard-coded rules may miss edge cases | Audit-trail logging of every fiscal adjustment with grondslag (law article + amount + reason); accountant can review & override in UI; override recorded as amendment |
| Lijfrente room calculation (10-year history, per-age franchise) is complex; error in one year cascades to future years | Per-valuation audit trail; system does NOT delete or retroactively recalculate prior-year room; amendment via correctieaangifte only |
| Digipoort certificate (eHerkenning or DigiD) may be unavailable; no submission possible despite valid aangifte | Proactive warning at 4-week lead time (REQ-IB-008); option to hold in "GEVALIDEERD" state, print XBRL for manual Digipoort upload, or delegate to fiscalist (Becon-route) |
| XBRL NT17 validation is strict; missing/invalid rubriek can block submission | Spec-level validation gates (REQ-IB-007): no "Indienen" action allowed unless XBRL parses + passes NT17 schema validation; error message names exact rubriek that failed |
| ZZP'er mistakenly selects W-formulier (loondienst + ondernemen) instead of P; returns inaccurate income split | Auto-detection heuristic (REQ-IB-012 in design) based on profiel flags + income distribution; UI decision tree presented; final choice logged for audit |

## Rollback

IB-aangiften, once ingediend (status = INGEDIEND + Digipoort ontvangstbevestiging recorded), are **non-reversible** per Belastingdienst protocol. Rollback of this spec is only viable if:
- No entity has filed a 2025 (or later) IB-aangifte via Digipoort, OR
- All filed aangiften are superseded by correctieaangiften (which the system must track)

Post-filing corrections are journalized as new `IBAangifte` records with status CORRECTIE, not deletions.

## Open Questions

1. **Lijfrente room carryforward**: Should the system auto-calculate prior-year unclaimed room (requires 10-year GL history)? Or assume ZZP'er provides manual opening balance? → Recommend v1: manual entry per opening-balance data form; v2 (T4): auto-aggregate from prior-year aangifte records.
2. **Representatiebeperking threshold**: Art. 3.15 caps deduction at 5% winst. Some sectors (hospitality, media) have case-law exceptions. Should those be coded or left for accountant override? → Recommend: base logic per statute; override + justification in UI; audit trail.
3. **FOR (Oudedagsreserve) dotatie cease**: Since 2023, new FOR dotations are not permitted (only drawdown of existing saldo). How to flag "legacy FOR" entities? → Recommend: `for-saldo-type` enum (legacy/none) in `IBOndernemersaftrek`; UI warning if legacy + attempt to dotate.
4. **Multi-user Becon-route**: When a fiscalist manages 100+ clients, is approval per-cliënt aangifte or bulk-approval on portfolio? → Out of scope (Hydra/workflow integration, T4).

## Dependencies

- **zzp-urencriterium-tracker**: Provides urencriterium-rapportage API (≥1225 hours) for zelfstandigenaftrek eligibility check (REQ-IB-002). Without this, zelfstandigenaftrek is blocked.
- **bookkeeping-investeringsaftrek**: Provides KIA/EIA/MIA/VAMIL calculation summaries (amount per year, staffel detail) for inclusion in IBOndernemersaftrek (REQ-IB-004). Soft dependency (spec works without; KIA just shows 0).
- **bookkeeping-chart-of-accounts**: Depends on RGS-compliant Account codes for GL-to-rubriek mapping (REQ-IB-001). Hard dependency.
- **bookkeeping-ap-ar**: Depends on AP/AR transaction detail for omzet & kostprijs aggregation (IBWinstOpgave).
- **bookkeeping-fixed-assets**: Depends on depreciation schedules + asset disposals for afschrijving detail.
- **bookkeeping-voorzieningen-claims**: Optional: provides provision (voorziening) detail if ondernemer has worker's-comp or litigation reserves.

## Success Criteria

- ZZP'er completes boekjaar 2025 in Shillinq; clicks "IB-aangifte starten 2025" and within 10 seconds sees a fully pre-filled, concept IBAangifte with winstOpgave, ondernemersaftrek (zelfstandigenaftrek conditional on urencriterium), MKB-exemption, lijfrente room, heffingskortingen, and box-3 all calculated.
- Audit trail shows every value herleidbaar to GL journaalpost(en) or external reference (uren-tracker, investeringsaftrek record, Digipoort ontvangst).
- System auto-detects and applies fiscal adjustments (representatiebeperking, auto bijtelling, home-office rules) with grondslag logged.
- ZZP'er or fiscalist can download a valid XBRL instance per NT17 that passes Digipoort validator (or submit directly via SBR/Digipoort API).
- Fiscalist can approve and digitally sign aangifte (Becon-route with PKIoverheid-services cert) and see Digipoort ontvangstbevestiging-ID in the system.
- System generates optimization suggestions (lijfrente room usage, FOR drawdown timing, MKB-exemption impact) and surfaces them in a "Fiscale Adviezen" panel.
- Correctieaangifte workflow allows ZZP'er to file a supplément (e.g., forgotten lijfrentepremie) and system calculates delta (teruggave or aanvullende betaling).
- 7-year audit retention: all aangiften, GL snapshots, uren-tracker evidence, and correspondence archived in openregister per AWR art. 52.
