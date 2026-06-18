---
status: done
---

# Spec: dba-compliance-marker

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** AP/AR (optional: factuurfrequentie-monitoring), OpenRegister lifecycle/calculations/aggregations (ADR-031)

## Purpose

This specification defines the requirements for dba compliance marker in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude unbuilt UI: DBA compliance intake/monitoring pages not yet implemented


### REQ-DBA-000: Compliance-modus instelling per ondernemer

Shillinq SHALL allow each ZZP-ondernemer or MKB-opdrachtgever to choose a compliance mode
at initial configuration: **soft mode** (waarschuwingen, geen blokkades), **hard mode**
(blokkades bij HOOG-risico), or **intermediair-mode** (extra strenge toets voor tussenkomst).
Once set, the mode applies to all future opdrachtnen under that administration.

#### Scenario: Soft mode
- **GIVEN** new ondernemer configures "soft mode"
- **WHEN** a HOOG-risico-opdracht is created
- **THEN** a warning MUST appear, the opdracht MUST be saveable, and audit-log MUST record the risk-level
- **AND** subsequent monitoring flags MUST appear in dashboard but NOT block factuur transmission

#### Scenario: Hard mode  
- **GIVEN** ondernemer configures "hard mode" 
- **WHEN** a HOOG-risico-opdracht is created
- **THEN** the first factuur MUST be blocked with "DBA HOOG-risico; require management override" message
- **AND** override requires a reason-text that is recorded in audit-trail

### REQ-DBA-001: Intake verplicht before first factuur, with eenmalig skip-rule

The system SHALL satisfy this requirement: Intake verplicht before first factuur, with eenmalig skip-rule.

When a new opdracht is registered in shillinq, the DBA intake is deferred until the
operator attempts to send the first factuur. At that point, the intake MUST be enforced;
no factuur MAY be transmitted without completed intake.

For opdrachtnen marked "eenmalig, totaal < €5000", a verkorte intake (3 vragen instead
of 20) SHALL be offered, and risk-score SHALL be marked `VERKORT_LAGE_DREMPEL`.

#### Scenario: Intake blocks first factuur
- **GIVEN** ondernemer creates opdracht "Backend development Acme"
- **WHEN** operator attempts to send first factuur
- **THEN** system MUST show DBA intake form with REQUIRED fields
- **AND** factuur MAY NOT be saved until intake is voltooid

#### Scenario: Abbreviated intake for small eenmalig
- **GIVEN** opdracht marked "Eenmalig, totaal €3.500"
- **WHEN** intake starts
- **THEN** system MUST offer 3-vraag short form (instead of full 20)
- **AND** risk-score MUST be marked `VERKORT_LAGE_DREMPEL`
- **AND** full assessment is not triggered

### REQ-DBA-002: Modelovereenkomst register with Belastingdienst-approved templates

Shillinq SHALL maintain a register of DBA modelovereenkomsten with version history
and validity tracking. Each modelovereenkomst SHALL carry:
- Official publication URL (Belastingdienst or internal)
- Approval date + expiry date
- Essential bepalingen (clauses MUST be in the actual contract)
- Version number + actuelle-versie flag

Belastingdienst-goedgekeurde templates (tussenkomstvrij v3 – 2024, leverancier-zelfstandig
v2, etc.) SHALL be seeded. Operators MAY upload custom models.

#### Scenario: Modelovereenkomst koppelen aan opdracht
- **GIVEN** intake is completed
- **WHEN** operator selects modelovereenkomst "Tussenkomst-vrij — algemeen (Belastingdienst v3 – 2024)"
- **THEN** system MUST display essential bepalingen in checklist form
- **AND** system MUST ask "Does your actual contract include ALL these clauses?" with 
  checkboxes
- **AND** proceeding MUST record which bepalingen operator confirmed

#### Scenario: Verlopen modelovereenkomst signaleren  
- **GIVEN** an opdracht was created using model "modov-bd-2021-x" which expired in 2024
- **WHEN** compliance-scan runs
- **THEN** a flag MUST be generated: "MODELOVEREENKOMST_VERLOPEN"
- **AND** user MUST receive advisory to select current modelovereenkomst

### REQ-DBA-003: Risk-score on three pillars + Deliveroo criteria (0–100, four bands)

Shillinq SHALL compute a single risk-score (0–100) from the three Wet-DBA pillars
(gezagsverhouding, persoonlijke arbeid, financieel risico, 0–20 each) plus Deliveroo-arrest
criteria (0–40 points), with four risk bands:
- **LAAG** (0–24)
- **LAAG_MIDDEN** (25–49)
- **MIDDEN_HOOG** (50–74)
- **HOOG** (75–100)

The score SHALL be computed via `x-openregister-calculations` as a summing formula;
if the calculation engine cannot express the logic, a single-method PHP helper
`DBAScoreCalculator::computeTotal(DBAIntake $intake): int` MAY be shipped per ADR-031.

#### Scenario: High gezagsverhouding score
- **GIVEN** intake indicates daily instructions + mandatory office presence
- **WHEN** risk-score is calculated
- **THEN** gezagsverhouding subtotal MUST be >15 of 20
- **AND** total score MUST fall into HOOG bracket (75+)

#### Scenario: Low score for specialist with own clients
- **GIVEN** intake indicates specialist work + multiple clients + own marketing
- **WHEN** score is calculated
- **THEN** Deliveroo-criteria subtotal MUST be <5 of 20
- **AND** total score MUST fall into LAAG bracket (<25)

### REQ-DBA-004: Periodieke monitoring on factuurpatronen

Shillinq SHALL run a daily monitoring job that inspects all active DBAOpdrachten
and generates flags when factuurpatterns match high-risk signatures.

The system MUST detect:
- **Vaste maandfactuur**: factuur on same date (±2 days) with same amount (±2%) for 6+ months
- **Geen variatie**: factuur bedrag variance < 0.04 (coefficient of variation)

#### Scenario: Vaste maandfactuur gedetecteerd
- **GIVEN** 6 months of facturen on the 1st of each month, €4.000 ± 2%
- **WHEN** daily monitoring runs
- **THEN** flag MUST be generated: `FACTUURFREQUENTIE_LIJKT_OP_LOON`
- **AND** suggestion MUST be: "Vary invoice amount based on actual hours; invoice per deliverable"
- **AND** flag status MUST be MIDDEN, with note: "Maandfacturatie met vast bedrag oogt als loon"

#### Scenario: No flag with variatie
- **GIVEN** facturen between €2.100 and €6.800 per month, dates vary 15–35 days apart
- **WHEN** monitoring runs
- **THEN** no `FACTUURFREQUENTIE_LIJKT_OP_LOON` flag MUST be generated

### REQ-DBA-005: Concentratie- en exclusiviteit-monitoring

Shillinq SHALL compute portfolio-risk aggregation monthly (or on-demand) and flag
when omzetconcentratie > 70% on a single klant (12-month rolling), or when langjarige
(>2 jaar) relaties with >50% omzet are detected.

#### Scenario: Concentratie-waarschuwing
- **GIVEN** one klant delivers 73% of 12-month revenue
- **WHEN** portfolio-aggregatie is computed
- **THEN** concentratie.status MUST be "WAARSCHUWING"
- **AND** dashboard MUST show banner: "One client represents 73% of revenue (threshold: 70%)"

#### Scenario: Langjarige hoofdrelatie
- **GIVEN** klant X exists 2.5 years, delivers 55% revenue
- **WHEN** aggregatie runs
- **THEN** flag MUST be generated: `LANGJARIGE_HOOFDRELATIE`
- **AND** flag.details MUST include: "Duur: 2.5 jaar, Omzetaandeel: 55%"

### REQ-DBA-006: Multiple engagement signaal voor zelfde concern

The system SHALL satisfy this requirement: Multiple engagement signaal voor zelfde concern.

When an ondernemer creates multiple opdrachten for juridisch gerelateerde entities
(same concern via KvK uiteindelijk-belanghebbende), Shillinq SHALL flag this as high risk.

#### Scenario: Multiple-entity zelfde concern  
- **GIVEN** klant "Acme NL BV" and "Acme België BVBA" share the same UBO (ultimate beneficial owner)
- **AND** three active opdrachtnen across these entities
- **WHEN** concern-check runs
- **THEN** flag MUST be generated: `MULTIPLE_ENGAGEMENT_ZELFDE_CONCERN`
- **AND** flag.details MUST list the three opdrachtnen + their risk-scores

### REQ-DBA-007: Evidence-dossier per opdracht

Shillinq SHALL automatically build an evidence-dossier (`DBAEvidenceDossier`) per opdracht
containing:
- Getekende modelovereenkomst (PDF, date)
- Eerste factuur + laatste factuur (PDF refs, dates)
- Urenstaat per kwartaal (if applicable)
- Optional: e-mail communication archive (opt-in per AVG art. 6)

Each stuk SHALL carry:
- Type enum (`GETEKENDE_OVEREENKOMST`, `FACTUUR_EERSTE`, `URENSTAAT_KWARTAAL`, `EMAIL_ARCHIVE`, etc.)
- File reference (openregister file-api URI or docudesk FK)
- SHA-256 hash (immutable proof)
- Date added to dossier

Compleetheids-score SHALL be 0–1; missing urenstaten lower the score.

#### Scenario: Compleetheids-score
- **GIVEN** opdracht has overeenkomst + facturen, NO urenstaten
- **WHEN** compleetheid is calculated
- **THEN** compleetheidsScore MUST be ~0.6 (60%)
- **AND** UI MUST show: "Missing: hourly tracking sheets (urenstaten per kwartaal)"

### REQ-DBA-008: Audit-rapport per opdracht for Belastingdienst

On request, Shillinq SHALL generate an audit-ready PDF per opdracht containing:
- Intake answers
- Gekozen modelovereenkomst + essentiële bepalingen checklist
- Risk-score progression (if available)
- Generated flags + actie-suggesties
- Evidence-dossier inventory + SHA-256 hashes

The PDF MUST be suitable for submission to Belastingdienst in case of control/correction.

#### Scenario: Belastingdienstcontrole rapport export
- **GIVEN** Belastingdienst requests dossier for opdracht 0042
- **WHEN** operator clicks "Audit-rapport exporteren"
- **THEN** PDF-A3 MUST be generated with intake + score + flags + evidence inventory
- **AND** SHA-256 hash of the PDF MUST be recorded in audit-trail

### REQ-DBA-009: Periodieke herbeoordeling

For opdrachtnen running longer than 12 months, Shillinq SHALL trigger a yearly
herbeoordeling request on the intake's anniversary.

#### Scenario: Jaarlijkse herbeoordeling  
- **GIVEN** opdracht loopt 13 months
- **WHEN** periodic trigger fires on intake-anniversary
- **THEN** system MUST send notification: "Bevestig of update DBA intake voor <opdrachtNaam>"
- **AND** if no response within 30 days, flag MUST be generated: `HERBEOORDELING_OVERDUE`

### REQ-DBA-010: Opdrachtgever-perspektief (inhuur-flow)

The system SHALL satisfy this requirement: Opdrachtgever-perspektief (inhuur-flow).

When shillinq is used by an MKB-opdrachtgever to hire a ZZP'er, a mirror DBA-inhuur-intake
SHALL be enforced before PO/inkoopfactuur is created.

#### Scenario: Inhuur-intake bij PO
- **GIVEN** MKB-opdrachtgever creates PO for ZZP-leverancier
- **WHEN** PO is drafted
- **THEN** DBA-inhuur-intake MUST appear (mirror of supplier-side)
- **AND** at HOOG-risico, PO MUST be blocked for approval without management-override

### REQ-DBA-011: Belastingdienst-correctieverplichting workflow

When an ondernemer receives a Belastingdienst correctie-brief, Shillinq SHALL enable
creation of a correctie-dossier with all relevant opdracht-evidence + boekingen.

#### Scenario: Correctie-dossier opbouwen
- **GIVEN** Belastingdienst sends correction-notice for opdracht 0042
- **WHEN** operator chooses "Correctie-dossier starten"
- **THEN** system MUST create workmap with all opdracht-related bookings
- **AND** herclassificatie-scenario (loon i.p.v. winst) MUST be calculable (optional: via `bookkeeping-payroll-engine-nl`)

### REQ-DBA-012: Privacy and AVG compliance

E-mail and communication archives included as evidence MUST be processed AVG-compliant:
opt-in for archival, 7-year retention per AWR art. 52, subject access rights.

#### Scenario: AVG-opt-in for e-mail archiving
- **GIVEN** operator wants e-mail communication in evidence
- **WHEN** archiving feature is enabled
- **THEN** explicit opt-in for processing wederpartij persoonsgegevens MUST be shown
- **AND** retention period MUST be displayed: "7 years from engagement end-date"

### REQ-DBA-013: Webmodule-Beoordeling-Arbeidsrelatie (WBA) integratie

If the Belastingdienst WBA-webmodule result is available, Shillinq SHALL allow upload
and storage of the WBA-assessment as a formal beoordelingsresultaat per opdracht.

#### Scenario: WBA "indicatie buiten dienstbetrekking"
- **GIVEN** ondernemer has completed WBA for opdracht
- **WHEN** WBA result "indicatie buiten dienstbetrekking" is uploaded
- **THEN** result MUST be stored as formal `wbaBeoordelingResultaat` on DBAOpdracht
- **AND** validity period (1 year per WBA-policy) MUST be tracked

### REQ-DBA-014: VBAR (Vervangbaarheid) bewijslast

The vervangbaarheid criterion is legally weighted heavily. Shillinq SHALL explicitly
ask for evidence of actual (not just contractual) substitutions and store proofs in
evidence-dossier.

#### Scenario: Vervangbaarheid contractueel but never exercised
- **GIVEN** intake claims "vervangbaar volgens contract" but no actual substitution in 18 months
- **WHEN** risk-score is calculated
- **THEN** `vervangingFeitelijkScore` MUST be 0
- **AND** flag MUST be generated: `VERVANGBAARHEID_THEORETISCH`

### REQ-DBA-015: Branchekader interpretation

Shillinq SHALL recognize branch-specific DBA frameworks (ICT-kader, Zorg-kader, Bouw-kader,
Onderwijs-kader) and generate branch-specific flags when applicable.

#### Scenario: ICT-kader: integratie in productieteam
- **GIVEN** ondernemer is ICT-specialist, opdracht is on-site at klant
- **AND** daily participation in scrum/stand-up with fixed employees
- **WHEN** ICT-kader-check runs
- **THEN** flag MUST be generated: `ICT_INTEGRATIE_IN_TEAM` with scrum-participation details

### REQ-DBA-016: VBAR uurtarief-grens monitoring

For every outgoing factuur, Shillinq SHALL compute effective hourly rate and warn
(or block, depending on mode) if rate falls below VBAR-threshold (EUR 33, peil 2024,
indexed annually).

#### Scenario: Onder VBAR-grens
- **GIVEN** factuur 40 uur × EUR 28/uur
- **WHEN** factuur is drafted
- **THEN** warning MUST appear: "Effectief uurtarief EUR 28 onder VBAR-rechtsvermoeden-grens EUR 33"
- **AND** suggestion: "Increase hourly rate or provide written justification"
- **AND** in hard-mode, factuur MUST be blocked until override

#### Scenario: Boven grens with all-in vergoeding
- **GIVEN** fixed-price factuur EUR 12.000 for 280 hours (effective EUR 42,86/hour)
- **WHEN** factuur is drafted
- **THEN** no VBAR-flag MUST be generated
- **AND** uurtarief MUST be stored in evidence for portfolio-aggregatie

### REQ-DBA-017: Tussenkomst-driehoek modelleren (optional)

For opdrachtnen via intermediary (broker, staffing agency), Shillinq SHALL model
the three-party relationship (ZZP–intermediair–eindklant) with separate DBA-assessments
per relationship.

#### Scenario: Detachering via Yacht naar Belastingdienst
- **GIVEN** ondernemer delivers via Yacht to Belastingdienst-eindklant
- **WHEN** intake is started with `intermediairMode: true`
- **THEN** system MUST request documentation of both relationships
- **AND** separate risk-scores MUST be computed for (a) ZZP–Yacht and (b) Yacht–Belastingdienst
- **AND** Waadi + Wka compliance MUST be flagged as applicable

### REQ-DBA-018: Beëindiging-procedure with evidence-cap

When an opdracht is marked beëindigd, Shillinq SHALL close the evidence-trail,
generate an end-report, and start the 7-year retention-period clock per AWR art. 52.

#### Scenario: Opdracht beëindigd
- **GIVEN** opdracht 0042 is marked ended on 30 September 2026
- **WHEN** beëindigingsprocedure runs
- **THEN** end-report MUST be generated with summary (intake, score-progression, flags, actions taken)
- **AND** retention-period clock MUST start: delete-eligible on 30 September 2033
- **AND** evidence-dossier MUST be archived (read-only after termination)

