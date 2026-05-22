# Tasks — Full NL Loonadministratie Engine

## Phase 1: Entity Registration & Schemas

- [ ] Register `Werkgever` entity in `openspec/architecture/adr-000-data-model.md`
  - Fields: kvk, naam, loonheffingsnummer, sectorcode, awfTarief, zvwTarief, wkrBudget2026, vakantiegeldUitbetalingMaand
  - Primary spec: bookkeeping-payroll-engine-nl
  - Relations: Organization (many-to-one), Administration (many-to-one)

- [ ] Register `Werknemer` entity in `adr-000-data-model.md`
  - Fields: bsn, voorletters, achternaam, geboortedatum, geslacht, inDienstSinds, uitDienstPer, burgerlijkeStaat, fiscaalPartnerBsn, loonheffingstabel, sectorcode, contractType, uurloon, contracturenPerWeek, jaarloonSV, vakantiegeldPct, pensioenRegeling, pensioenPremiePctWerkgever, pensioenPremiePctWerknemer, thuiswerkdagenPerWeek, auto, is_dga, expat30PctRegeling
  - Primary spec: bookkeeping-payroll-engine-nl
  - Relations: Werkgever (many-to-one), Person (many-to-one)

- [ ] Register `LoonPeriode` entity
  - Fields: werkgeverId, periodeType (WEEK|4WEKEN|MAAND), jaar, periodeNr, periodeStart, periodeEind, betaaldatum, status (OPEN|GESLOTEN), loonheffingstabelVersie, totaalBrutoloon, totaalNettoBetaald, totaalLHAfdracht, totaalPremiesSVAfdracht, totaalZVWAfdracht
  - Relations: Werkgever (many-to-one), LoonheffingTabel2026 (many-to-one)

- [ ] Register `LoonStrook` entity
  - Fields: werknemerId, periodeId, brutoComponenten (JSON), fiscaalLoon, premieloon_SV, loonheffing, inhoudingenSV (JSON), premiesSVWerkgever (JSON), zvw (JSON), pensioen (JSON), nettoBetaald, cumulatieven (JSON), vakantieDagenReservering (JSON)
  - Relations: Werknemer (many-to-one), LoonPeriode (many-to-one)

- [ ] Register `LoonheffingTabel2026` entity
  - Fields: jaar, kleur (WIT|GROEN), periode (WEEK|4WEKEN|MAAND|JAAR), metKorting, versienummer, tabelRegels (array), bron, geldigVan, geldigTot
  - Constraint: immutable once created; read-only after geldigVan date

- [ ] Register `LHAfdracht` entity
  - Fields: werkgeverId, periodeId, totaalLoonheffing, totaalEindheffingenWKR, totaalPremiesSV, totaalZVW, totaalAfdracht, vervaldagAfdracht, status (VOORBEREID|GEVERIFIEERD|VERZONDEN|VERWERKT), sbrInstanceRef
  - Relations: Werkgever (many-to-one), LoonPeriode (many-to-one)

- [ ] Register `Loonjournaalpost` entity
  - Fields: periodeId, datum, regels (array of GLLine), balanced (boolean)
  - Relations: LoonPeriode (many-to-one), Account (many-to-one per regel)

## Phase 2: Data Models & Seed Templates

- [ ] Create seed data templates (Dutch SMB examples):
  - `seeds/werkgever-smb-example.json` — 1–10 werknemers, AWF-laag, €5k WKR-budget
  - `seeds/werknemer-fulltime-example.json` — Fulltime, loonheffingstabel WIT, pensioen PME
  - `seeds/werknemer-parttime-example.json` — Parttime, vakantiegeld opbouw
  - `seeds/werknemer-dga-example.json` — DGA, gebruikelijk-looncheck

- [ ] Load 2026 tax tables from Belastingdienst oranje-boek:
  - `LoonheffingTabel2026` WIT maand (met/zonder korting)
  - `LoonheffingTabel2026` GROEN maand (met/zonder korting)
  - `LoonheffingTabel2026` bijzondere bestanddelen (mei-uitbetaling)
  - `LoonheffingTabel2026` JAAR (13e maand, EJU)
  - Versienummer: 2025-W47 (december 2025 publicatie)

- [ ] Load 2026 premium tables:
  - AWF-laag: 2,64%, AWF-hoog: 3,55%
  - AOF-klein: 5,38%, AOF-groot: 6,50%
  - WHK per sectorcode (UWV media)
  - WKO-laag: ~0,05%
  - ZVW-laag: 5,32%, ZVW-hoog: 6,57%
  - Maximum premieloon SV: €74.480, maximum ZVW: €71.628

## Phase 3: Berekening Engine (Algorithms)

- [ ] Implement bruto→netto-berekening algorithm (REQ-PAY-001):
  - Aggregate brutoComponenten (salaris, toelagen, vergoedingen)
  - Determine loonheffingstabel versie based on Werknemer.loonheffingstabel + LoonPeriode.geldigVan
  - Lookup LH from tabel using fiscaalLoon
  - Apply loonheffingskorting if Werknemer.loonheffingstabelKorting=true
  - Calculate SV-premies (AWF, AOF, WHK) on premieloon_SV capped at €74.480/maand
  - Calculate ZVW on premieloon_SV capped at €71.628/maand
  - Calculate pensioen (werkgever + werknemer aandeel)
  - Calculate nettoBetaald = bruto - LH - SV-inhouding - pensioen-inhouding + belastingvrije toelagen
  - Aggregate cumulatieven.fiscaalloon_ytd, cumulatieven.vakantiegeld_ytd

- [ ] Implement vakantietoeslag opbouw (REQ-PAY-005):
  - Monthly: 8% × bruto naar vakantiegeld_ytd
  - GL-credit 17xx "Te betalen vakantiegeld"
  - May: Uitbetaling vakantiegeld_ytd cumulatief → brutoComponenten.vakantietoeslag_uitbetaling
  - LH on bijzondere-tabel
  - Reset vakantiegeld_ytd after payout

- [ ] Implement DGA-gebruikelijk-loon-check (REQ-PAY-009):
  - Flag if Werknemer.is_dga=true and jaarloonBruto < €56.000
  - Allow exception via Werknemer.gebruikelijkLoonUitzondering field
  - Warning in dashboard, no blocking

- [ ] Implement belastingvrije toelagen:
  - Kilometervergoeding: cap at €0,23/km (2026)
  - Thuiswerkvergoeding: €2,40/dag (2026)
  - 30%-regeling expat: 30% of bruto if Werknemer.expat30PctRegeling=true
  - All excluded from fiscaalLoon

- [ ] Implement pro-rata mutaties (REQ-PAY-014):
  - Indienst mid-periode: bruto × (werkdagen_dienst / werkdagen_periode)
  - Uitdienst with vakantiedagen: (vakantiedagen × jaarloon/261) + pro-rata reguliere loon
  - Contract-wijziging mid-periode: split LoonPeriode in sub-periods

## Phase 4: Outputs & Integrations

- [ ] Generate LoonStrook objects (REQ-PAY-010):
  - Populate all brutoComponenten, inhoudingenSV, premiesSVWerkgever, zvw, pensioen
  - Stamp cumulatieven at generation time
  - Create PDF using openregister template-engine (art. 626 BW compliant)
  - Archive in openregister with 7-year retention

- [ ] Generate LHAfdracht aggregat (REQ-PAY-011):
  - Sum LoonStrook.loonheffing across periode → totaalLoonheffing
  - Sum SV-premies → totaalPremiesSV
  - Sum ZVW → totaalZVW
  - Fetch eindheffingen WKR from WKR-app integration
  - Set vervaldagAfdracht = last day of next month
  - Status: VOORBEREID (ready for SBR-conversion)

- [ ] Generate Loonjournaalpost (REQ-PAY-012):
  - Auto-create balanced GL entry per LoonPeriode closure
  - Debet 4001, 4010, 4020; Credit 1610, 1620, 1630, 1640
  - Verify balanced before posting
  - Post directly to openregister GL

- [ ] Implement SBR/XBRL conversion (REQ-PAY-011):
  - Convert LHAfdracht → SBR/XBRL instance (LA-XX-2026)
  - Populate sbrInstanceRef
  - Hand off to bookkeeping-loonaangifte-sbr app for Digipoort submission

- [ ] Implement Jaaropgave generation (REQ-PAY-013):
  - Aggregate all LoonStrook records for calendar year
  - Verify cumulatieven-totals match sum of all periods
  - Generate PDF with all art. 626 BW elements
  - Create digital version for Belastingdienst SBR-submission
  - Archive in openregister with 5-year retention

## Phase 5: Integrations (Downstream)

- [ ] Wire into bookkeeping-chart-of-accounts:
  - Loonjournaalpost → GLLine → Account.accountNumber (4001, 4010, 4020, 1610, etc.)

- [ ] Wire into bookkeeping-ap-ar:
  - LHAfdracht → APTransaction (payee=Belastingdienst, amount=totaalAfdracht, dueDate=vervaldagAfdracht)
  - Premium SV afdracht → APTransaction (payee=UWV, etc.)

- [ ] Wire into bookkeeping-upa-pensioen:
  - LoonStrook.pensioen → UPA-monthly-submission (per pensioenuitvoerder)

- [ ] Wire into bookkeeping-wkr:
  - LoonStrook aggregate loonsom → WKR-budget-tracking
  - EindheffingenWKR from WKR-app → LHAfdracht.totaalEindheffingenWKR

- [ ] Wire into bookkeeping-liv-lkv (future):
  - Werknemer.inkomenniveau + LoonStrook.fiscaalLoon → LIV/LKV eligibility

- [ ] Wire into openregister (audit trail, RBAC, attachments):
  - All entities use OR audit-trail (immutable log of changes)
  - RBAC via OR access-control
  - Loonstroken/jaaropgaven as documents (files, attachments)

## Phase 6: Testing & Validation

- [ ] Test bruto→netto against Belastingdienst oranje-boek examples (5+ scenarios)
- [ ] Test SV-premium aggregates against UWV tabellen 2026
- [ ] Test pro-rata calculations (mid-period entry/exit)
- [ ] Test cumulatieven-consistency (12-month aggregate == sum of periods)
- [ ] Test balance-constraint on GL journaalpost (debet == credit)
- [ ] Test edge cases:
  - DGA with low loon (warning but no block)
  - Expat with 30%-regeling
  - Stagiair with reduced SV-premies
  - Vakantiegeld uitbetaling in mei after opbouw Jan–Apr
  - Mid-periode tabel-wijziging (rare, but happens)
- [ ] Audit trail: verify immutability of LoonheffingTabel, cumulatieven snapshots

## Phase 7: Documentation & Knowledge Transfer

- [ ] Write user guide (Dutch):
  - Werkgever setup wizard
  - Werknemer-master inleiding
  - Loonperiode processing workflow
  - Common mutaties (indienst, uitdienst, contractwijziging)
  - Reading a loonstrook
  - Jaaropgave
  - Error handling (invalid loonheffingstabel, premium-franchise exceeded, etc.)

- [ ] Write developer guide:
  - Berekening algorithm pseudocode
  - Data-model architecture (Werkgever ← LoonPeriode ← Werknemer ← LoonStrook)
  - Versioning strategy (tax tables immutable, new records per effective-date)
  - Cumulative cumulatieven design (snapshots at posting-time)
  - GL-posting automation (balanced journal generation)
  - Integration points (ap-ar, upa, wkr, liv-lkv, sbr)

- [ ] Create audit/compliance checklist (NL):
  - Wet op de loonadministratie 1964 compliance
  - Loonstrook art. 626 BW check
  - Jaaropgave cumulatieven-consistency
  - Tax-table versioning (no retroactive changes)
  - 7-year document retention
  - DGA-gebruikelijk-loon warnings

## Phase 8: Rollout & Monitoring

- [ ] Pilot with 2–3 MKB-werkgevers (May 2026 payroll):
  - Conduction B.V. (seed data reference)
  - 1–2 additional real customers (signed NFAs)
  - Monitor bruto→netto accuracy against manual payroll
  - Validate LH-afdracht against expected Belastingdienst amounts

- [ ] GA release (June 2026):
  - All 4 artifacts (proposal, design, specs, tasks) approved
  - Code review passed (CI/CD, tests)
  - Pilot feedback incorporated
  - Documentation completed

- [ ] Post-release monitoring:
  - Track LH-afdracht discrepancies (Belastingdienst feedback)
  - Monitor SV-premium premium-recalcs (UWV annual updates)
  - Tax-table hot-patches (Belastingdienst mid-year corrections)
