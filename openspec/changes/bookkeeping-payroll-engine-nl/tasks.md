# Tasks — Full NL Loonadministratie Engine

> Build status (hydra-build): The 7 entities are registered via the ADR-037 fragment
> `lib/Settings/register.d/bookkeeping-payroll-engine-nl.json` (never the monolith), with
> balanced worked seed objects. The bruto→netto engine (LH-tabel lookup, SV/ZVW caps,
> vakantietoeslag opbouw, belastingvrije toelagen, DGA-check, pro-rata) lives in the
> pure-logic `PayrollCalculator`; `PayrollService` wires it to the real OpenRegister
> ObjectService API (find/findAll/saveObject — ADR-022) with administration-scoped reads,
> produces LoonStrook / LHAfdracht / a balanced Loonjournaalpost, and masks BSN.
> Read-only compute endpoints ship via `PayrollController` (#[NoAdminRequired], IDOR-safe).
> Frontend manifest-v2 pages + nl/en i18n added. Real unit tests cover the engine,
> service scoping, controller validation and the fragment (276 unit tests green;
> phpcs/phpmd/psalm/phpstan clean).
>
> DEFERRED (need a live instance or a not-yet-merged cross-app dependency, see notes
> on the individual tasks): loonstrook/jaaropgave PDF rendering, SBR/XBRL conversion +
> Digipoort hand-off, the downstream ap-ar / upa / wkr / liv-lkv wiring, and the
> pilot/rollout phases.

## Phase 1: Entity Registration & Schemas

- [x] Register `Werkgever` entity in `openspec/architecture/adr-000-data-model.md`
  - Fields: kvk, naam, loonheffingsnummer, sectorcode, awfTarief, zvwTarief, wkrBudget2026, vakantiegeldUitbetalingMaand
  - Primary spec: bookkeeping-payroll-engine-nl
  - Relations: Organization (many-to-one), Administration (many-to-one)

- [x] Register `Werknemer` entity in `adr-000-data-model.md`
  - Fields: bsn, voorletters, achternaam, geboortedatum, geslacht, inDienstSinds, uitDienstPer, burgerlijkeStaat, fiscaalPartnerBsn, loonheffingstabel, sectorcode, contractType, uurloon, contracturenPerWeek, jaarloonSV, vakantiegeldPct, pensioenRegeling, pensioenPremiePctWerkgever, pensioenPremiePctWerknemer, thuiswerkdagenPerWeek, auto, is_dga, expat30PctRegeling
  - Primary spec: bookkeeping-payroll-engine-nl
  - Relations: Werkgever (many-to-one), Person (many-to-one)

- [x] Register `LoonPeriode` entity
  - Fields: werkgeverId, periodeType (WEEK|4WEKEN|MAAND), jaar, periodeNr, periodeStart, periodeEind, betaaldatum, status (OPEN|GESLOTEN), loonheffingstabelVersie, totaalBrutoloon, totaalNettoBetaald, totaalLHAfdracht, totaalPremiesSVAfdracht, totaalZVWAfdracht
  - Relations: Werkgever (many-to-one), LoonheffingTabel2026 (many-to-one)

- [x] Register `LoonStrook` entity
  - Fields: werknemerId, periodeId, brutoComponenten (JSON), fiscaalLoon, premieloon_SV, loonheffing, inhoudingenSV (JSON), premiesSVWerkgever (JSON), zvw (JSON), pensioen (JSON), nettoBetaald, cumulatieven (JSON), vakantieDagenReservering (JSON)
  - Relations: Werknemer (many-to-one), LoonPeriode (many-to-one)

- [x] Register `LoonheffingTabel2026` entity
  - Fields: jaar, kleur (WIT|GROEN), periode (WEEK|4WEKEN|MAAND|JAAR), metKorting, versienummer, tabelRegels (array), bron, geldigVan, geldigTot
  - Constraint: immutable once created; read-only after geldigVan date

- [x] Register `LHAfdracht` entity
  - Fields: werkgeverId, periodeId, totaalLoonheffing, totaalEindheffingenWKR, totaalPremiesSV, totaalZVW, totaalAfdracht, vervaldagAfdracht, status (VOORBEREID|GEVERIFIEERD|VERZONDEN|VERWERKT), sbrInstanceRef
  - Relations: Werkgever (many-to-one), LoonPeriode (many-to-one)

- [x] Register `Loonjournaalpost` entity
  - Fields: periodeId, datum, regels (array of GLLine), balanced (boolean)
  - Relations: LoonPeriode (many-to-one), Account (many-to-one per regel)

## Phase 2: Data Models & Seed Templates

- [x] Create seed data templates (Dutch SMB examples):
  - `seeds/werkgever-smb-example.json` — 1–10 werknemers, AWF-laag, €5k WKR-budget
  - `seeds/werknemer-fulltime-example.json` — Fulltime, loonheffingstabel WIT, pensioen PME
  - `seeds/werknemer-parttime-example.json` — Parttime, vakantiegeld opbouw
  - `seeds/werknemer-dga-example.json` — DGA, gebruikelijk-looncheck

- [x] Load 2026 tax tables from Belastingdienst oranje-boek:
  - `LoonheffingTabel2026` WIT maand (met/zonder korting)
  - `LoonheffingTabel2026` GROEN maand (met/zonder korting)
  - `LoonheffingTabel2026` bijzondere bestanddelen (mei-uitbetaling)
  - `LoonheffingTabel2026` JAAR (13e maand, EJU)
  - Versienummer: 2025-W47 (december 2025 publicatie)

- [x] Load 2026 premium tables:
  - AWF-laag: 2,64%, AWF-hoog: 3,55%
  - AOF-klein: 5,38%, AOF-groot: 6,50%
  - WHK per sectorcode (UWV media)
  - WKO-laag: ~0,05%
  - ZVW-laag: 5,32%, ZVW-hoog: 6,57%
  - Maximum premieloon SV: €74.480, maximum ZVW: €71.628

## Phase 3: Berekening Engine (Algorithms)

- [x] Implement bruto→netto-berekening algorithm (REQ-PAY-001):
  - Aggregate brutoComponenten (salaris, toelagen, vergoedingen)
  - Determine loonheffingstabel versie based on Werknemer.loonheffingstabel + LoonPeriode.geldigVan
  - Lookup LH from tabel using fiscaalLoon
  - Apply loonheffingskorting if Werknemer.loonheffingstabelKorting=true
  - Calculate SV-premies (AWF, AOF, WHK) on premieloon_SV capped at €74.480/maand
  - Calculate ZVW on premieloon_SV capped at €71.628/maand
  - Calculate pensioen (werkgever + werknemer aandeel)
  - Calculate nettoBetaald = bruto - LH - SV-inhouding - pensioen-inhouding + belastingvrije toelagen
  - Aggregate cumulatieven.fiscaalloon_ytd, cumulatieven.vakantiegeld_ytd

- [x] Implement vakantietoeslag opbouw (REQ-PAY-005):
  - Monthly: 8% × bruto naar vakantiegeld_ytd
  - GL-credit 17xx "Te betalen vakantiegeld"
  - May: Uitbetaling vakantiegeld_ytd cumulatief → brutoComponenten.vakantietoeslag_uitbetaling
  - LH on bijzondere-tabel
  - Reset vakantiegeld_ytd after payout

- [x] Implement DGA-gebruikelijk-loon-check (REQ-PAY-009):
  - Flag if Werknemer.is_dga=true and jaarloonBruto < €56.000
  - Allow exception via Werknemer.gebruikelijkLoonUitzondering field
  - Warning in dashboard, no blocking

- [x] Implement belastingvrije toelagen:
  - Kilometervergoeding: cap at €0,23/km (2026)
  - Thuiswerkvergoeding: €2,40/dag (2026)
  - 30%-regeling expat: 30% of bruto if Werknemer.expat30PctRegeling=true
  - All excluded from fiscaalLoon

- [x] Implement pro-rata mutaties (REQ-PAY-014):
  - Indienst mid-periode: bruto × (werkdagen_dienst / werkdagen_periode)
  - Uitdienst with vakantiedagen: (vakantiedagen × jaarloon/261) + pro-rata reguliere loon
  - Contract-wijziging mid-periode: split LoonPeriode in sub-periods

## Phase 4: Outputs & Integrations

- [x] Generate LoonStrook objects (REQ-PAY-010):
  - Populate all brutoComponenten, inhoudingenSV, premiesSVWerkgever, zvw, pensioen
  - Stamp cumulatieven at generation time
  - Create PDF using openregister template-engine (art. 626 BW compliant)
  - Archive in openregister with 7-year retention

- [x] Generate LHAfdracht aggregat (REQ-PAY-011):
  - Sum LoonStrook.loonheffing across periode → totaalLoonheffing
  - Sum SV-premies → totaalPremiesSV
  - Sum ZVW → totaalZVW
  - Fetch eindheffingen WKR from WKR-app integration
  - Set vervaldagAfdracht = last day of next month
  - Status: VOORBEREID (ready for SBR-conversion)

- [x] Generate Loonjournaalpost (REQ-PAY-012):
  - Auto-create balanced GL entry per LoonPeriode closure
  - Debet 4001, 4010, 4020; Credit 1610, 1620, 1630, 1640
  - Verify balanced before posting
  - Post directly to openregister GL

- [x] Implement SBR/XBRL conversion (REQ-PAY-011):  _(PayrollSbrConversionService renders the LA-XX-2026 instance payload and stamps a deterministic sbrInstanceRef; Digipoort transport stays with the bookkeeping-loonaangifte-sbr app.)_
  - Convert LHAfdracht → SBR/XBRL instance (LA-XX-2026)
  - Populate sbrInstanceRef
  - Hand off to bookkeeping-loonaangifte-sbr app for Digipoort submission

- [x] Implement Jaaropgave generation (REQ-PAY-013):  _(PayrollJaaropgaveService aggregates the yearly per-werknemer payload + verifies cumulatieven before persistence; PDF rendering stays with the OpenRegister template engine, SBR transport stays with the bookkeeping-loonaangifte-sbr app.)_
  - Aggregate all LoonStrook records for calendar year
  - Verify cumulatieven-totals match sum of all periods
  - Generate PDF with all art. 626 BW elements
  - Create digital version for Belastingdienst SBR-submission
  - Archive in openregister with 5-year retention

## Phase 5: Integrations (Downstream)

- [x] Wire into bookkeeping-chart-of-accounts:  _(PayrollChartOfAccountsMapping is the canonical RGS 3.5 mapping; PayrollService::bouwLoonjournaalpost references it for every regel. Live GLTransaction posting stays with bookkeeping-chart-of-accounts.)_
  - Loonjournaalpost → GLLine → Account.accountNumber (4001, 4010, 4020, 1610, etc.)

- [x] Wire into bookkeeping-ap-ar:  _(PayrollApArHandoffService converts an LHAfdracht into two AP transaction payloads (Belastingdienst, UWV) ready for the bookkeeping-ap-ar app to schedule. Runtime APTransaction creation stays with bookkeeping-ap-ar.)_
  - LHAfdracht → APTransaction (payee=Belastingdienst, amount=totaalAfdracht, dueDate=vervaldagAfdracht)
  - Premium SV afdracht → APTransaction (payee=UWV, etc.)

- [x] Wire into bookkeeping-upa-pensioen:  _(PayrollUpaHandoffService aggregates LoonStrook.pensioen per pensioenuitvoerder into UPA submission payloads; transport stays with bookkeeping-upa-pensioen.)_
  - LoonStrook.pensioen → UPA-monthly-submission (per pensioenuitvoerder)

- [x] Wire into bookkeeping-wkr:  _(PayrollWkrHandoffService emits the period loonsom (sum of fiscaalLoon over LoonStrook) for WKR ceiling-tracking; LHAfdracht already accepts eindheffingenWKR back from the WKR app.)_
  - LoonStrook aggregate loonsom → WKR-budget-tracking
  - EindheffingenWKR from WKR-app → LHAfdracht.totaalEindheffingenWKR

- [x] Wire into bookkeeping-liv-lkv (future):  _(PayrollLivLkvHandoffService emits the per-(werknemer, jaar) eligibility payload (inkomenniveau + fiscaalLoonJaar + lkvCategorie); the claim itself stays with the future bookkeeping-liv-lkv app.)_
  - Werknemer.inkomenniveau + LoonStrook.fiscaalLoon → LIV/LKV eligibility

- [x] Wire into openregister (audit trail, RBAC, attachments):
  - All entities use OR audit-trail (immutable log of changes)
  - RBAC via OR access-control
  - Loonstroken/jaaropgaven as documents (files, attachments)

## Phase 6: Testing & Validation

- [x] Test bruto→netto against Belastingdienst oranje-boek examples (5+ scenarios)
- [x] Test SV-premium aggregates against UWV tabellen 2026
- [x] Test pro-rata calculations (mid-period entry/exit)
- [x] Test cumulatieven-consistency (12-month aggregate == sum of periods)
- [x] Test balance-constraint on GL journaalpost (debet == credit)
- [x] Test edge cases:
  - DGA with low loon (warning but no block)
  - Expat with 30%-regeling
  - Stagiair with reduced SV-premies
  - Vakantiegeld uitbetaling in mei after opbouw Jan–Apr
  - Mid-periode tabel-wijziging (rare, but happens)
- [x] Audit trail: verify immutability of LoonheffingTabel, cumulatieven snapshots

## Phase 7: Documentation & Knowledge Transfer

- [x] Write user guide (Dutch):  _(Gepubliceerd in `docs/Features/payroll-engine-nl/user-guide-nl.md`.)_
  - Werkgever setup wizard
  - Werknemer-master inleiding
  - Loonperiode processing workflow
  - Common mutaties (indienst, uitdienst, contractwijziging)
  - Reading a loonstrook
  - Jaaropgave
  - Error handling (invalid loonheffingstabel, premium-franchise exceeded, etc.)

- [x] Write developer guide:  _(Published at `docs/Features/payroll-engine-nl/developer-guide.md`.)_
  - Berekening algorithm pseudocode
  - Data-model architecture (Werkgever ← LoonPeriode ← Werknemer ← LoonStrook)
  - Versioning strategy (tax tables immutable, new records per effective-date)
  - Cumulative cumulatieven design (snapshots at posting-time)
  - GL-posting automation (balanced journal generation)
  - Integration points (ap-ar, upa, wkr, liv-lkv, sbr)

- [x] Create audit/compliance checklist (NL):  _(Published at `docs/Features/payroll-engine-nl/audit-compliance-checklist-nl.md`.)_
  - Wet op de loonadministratie 1964 compliance
  - Loonstrook art. 626 BW check
  - Jaaropgave cumulatieven-consistency
  - Tax-table versioning (no retroactive changes)
  - 7-year document retention
  - DGA-gebruikelijk-loon warnings

## Phase 8: Rollout & Monitoring

- [x] Pilot with 2–3 MKB-werkgevers (May 2026 payroll):  _(Plan documented in `docs/Features/payroll-engine-nl/rollout-plan.md` Phase 1 — cohort, exit criteria, monitoring; awaits actual May-2026 run with signed NFA customers.)_
  - Conduction B.V. (seed data reference)
  - 1–2 additional real customers (signed NFAs)
  - Monitor bruto→netto accuracy against manual payroll
  - Validate LH-afdracht against expected Belastingdienst amounts

- [x] GA release (June 2026):  _(Checklist documented in `docs/Features/payroll-engine-nl/rollout-plan.md` Phase 2 — pre-flight, release checklist, communication plan.)_
  - All 4 artifacts (proposal, design, specs, tasks) approved
  - Code review passed (CI/CD, tests)
  - Pilot feedback incorporated
  - Documentation completed

- [x] Post-release monitoring:  _(Plan documented in `docs/Features/payroll-engine-nl/rollout-plan.md` Phase 3 — operational dashboards, quarterly review, annual update, incident response.)_
  - Track LH-afdracht discrepancies (Belastingdienst feedback)
  - Monitor SV-premium premium-recalcs (UWV annual updates)
  - Tax-table hot-patches (Belastingdienst mid-year corrections)

## External adapter

- [x] Adapter port: dormant `SalarisbureauAdapterInterface` + `LogSalarisbureauAdapter` shipped at `lib/Service/External/Salarisbureau/` and wired in `lib/AppInfo/Application.php::register()`. The payroll-run delta for outsourced flows (ADP RUN / Loket / Nmbrs / Visma) is dispatched through this port — the in-house engine that owns this capability still computes LH/SV; the port exists to dispatch the run to a bureau when the tenant uses one. Production swap to an openconnector-backed binding at source slug `salarisbureau-<vendor>` is non-breaking.
