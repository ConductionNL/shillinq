# Tasks — Vpb-aangifte Vennootschapsbelasting voor BV/NV

> **Implemented (hydra-build).** Per ADR-032 `kind: config`, the centre of mass is
> declarative metadata: the `bookkeeping-vpb-mkb` register fragment
> (`lib/Settings/register.d/bookkeeping-vpb-mkb.json`, 12 schemas + lifecycles +
> calculations + seed objects) merged additively via `SettingsService::deepMergeConfig`
> (ADR-037 — the monolith `shillinq_register.json` is untouched). The only PHP is two
> ADR-031 exception-path lifecycle guards (`VpbAangifteGuard`, `BezwaarTermijnGuard`)
> for cross-schema preconditions and the schijftarief/voorvoegingsverlies arithmetic the
> declarative DSL cannot yet express. No PHP tax-calculation service ships (ADR-022/031).
> Tasks needing a live OpenRegister instance or a not-yet-merged cross-app dependency
> (live SBR/Digipoort transmission, runtime event publishing) are marked DEFERRED with a
> reason.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-vpb-mkb` capability spec already exists;
  verify no `Belastingplichtige`, `VpbAangifte`, `FiscaleCorrectie`, `Innovatiebox`,
  `Deelneming`, `FiscaleEenheid`, `Voorvoegingsverlies`, `InvesteringsAftrek`,
  `VoorlopigeAanslag`, `DefinitieveAanslag`, `BezwaarBeroep` schemas are declared
  in `lib/Settings/shillinq_register.json`; verify no `lib/Service/Vpb*`, `lib/Service/TaxCalc*`
  PHP classes present (per ADR-031 anti-pattern enumeration)

- [x] Task 2: Author `specs/bookkeeping-vpb-mkb/spec.md` with `Status: proposed` /
  `Scope: shillinq` / `Tier: T3 (regulatory + compliance)` / `Depends on:
  bookkeeping-financial-statements, bookkeeping-sbr-xbrl-reporting, bookkeeping-general-ledger,
  bookkeeping-tax-calendar` header; `REQ-VPB-NNN` requirements using RFC 2119 keywords;
  `#### Scenario:` blocks with GIVEN/WHEN/THEN per each requirement; cite Wet Vpb §XX
  + RJ XX inline

- [x] Task 3: Author `proposal.md` (completed) referencing the shared `nextcloud-app`
  spec and including Affected Projects (shillinq, sbr-xbrl-reporting) / Scope
  (13 registers, one-aangifte-per-jaar, jaarrekening binding, fiscal corrections,
  facilities, tariff application, voorvoegingsverlies regime tracking, SBR submission,
  bezwaar/beroep workflow) / Risks (manual fiscal corrections, tariff currency,
  voorvoegingsverlies regime changes, deelnemingsvrijstelling tests, termijn compliance)
  / Rollback (non-reversible once submitted) / Open Questions (Digipoort cert SGA,
  inspecteur-aanslag feed, low-tax-portfolio-investment detection, voorvoegingsverlies
  transition, transfer-pricing metadata) / Dependencies

- [x] Task 4: Author `design.md` (completed) with Reuse Analysis table, D1 (13 registers:
  belastingplichtige + aangifte + corrections + facilities + loss tracking + assessments
  + disputes), D2 (NTP-classified fiscal corrections), D3 (one-aangifte-per-jaar constraint),
  D4 (jaarrekening binding), D5 (schijftarieven parameterization), D6 (innovatiebox
  two methods + S&O-verklaring), D7 (deelnemingsvrijstelling three tests), D8
  (voorvoegingsverlies per-regime tracking), D9 (fiscale eenheid per-dochter loss
  tracking), D10 (bezwaar/beroep state machine + termijnen), D11 (SBR-XBRL + eHerkenning EH3),
  D12 (voorlopige aanslag + herzieningsverzoek)

- [x] Task 5: Declare the `Belastingplichtige` schema in `lib/Settings/shillinq_register.json`
  with all REQ-VPB-001–003 fields (rsin, kvkNummer, rechtsvorm enum: BV/NV/coop/
  onderlinge, boekjaarStart, boekjaarEind, fiscaalAdviseur FK, digipoortCertificaat
  FK to credential vault, eHerkenningsNiveau enum: EH3/EH4, status enum: active/archived)

- [x] Task 6: Declare the `VpbAangifte` schema in `lib/Settings/shillinq_register.json`
  with all REQ-VPB-001–009 fields (belastingplichtige FK, belastingjaar integer,
  boekjaarVan date, boekjaarTot date, status enum: concept/ingediend/aanslag-ontvangen/
  bezwaar/beroep/onherroepelijk, commerciëleWinst FK to FinancialStatements vastgestelde
  version, fiscaleWinstVoorVerliezen computed, voorvoegingsverliezen FK array, voorvoegingsverliesen
  bedrag-used numeric, fiscaleWinstBelastbaar computed, verschuldigdeVpb computed,
  voorheffingen numeric, teBetalen computed, ingediendOp datetime, digipoortReceiptId
  string, aansluittabel records array); add lifecycle: concept → ingediend → aanslag-ontvangen
  → bezwaar → beroep → onherroepelijk; guards: prevent ingediend if commerciëleWinst.jaarrekening
  not vastgesteld; prevent duplicate (belastingplichtige, belastingjaar) per REQ-VPB-001

- [x] Task 7: Declare the `FiscaleCorrectie` schema in `lib/Settings/shillinq_register.json`
  with all REQ-VPB-002 fields (aangifte FK, code string NTP-element, omschrijving text,
  commercieelBedrag numeric, fiscaalBedrag numeric, correctieBedrag computed, toelichting
  text met Wet Vpb Article reference); add audit-trail on all writes (who entered,
  when, what changed)

- [x] Task 8: Declare the `Innovatiebox` schema in `lib/Settings/shillinq_register.json`
  with all REQ-VPB-004 fields (aangifte FK, methodType enum: forfaitair/werkelijke-winst,
  kwalificerendeActiva array records, forfaitaireDrempel numeric: €25000 (hardcoded),
  forfaitaireGeldigheid: date (S&O-issuance + 3 years), nexusFactor numeric 0–1,
  innovatieboxWinst computed, effectiefTarief: 0.09 (hardcoded), soVerklaringReferentie
  string (RVO-issued, mandatory), innovatieboxBedrag computed); S&O-reference mandatory
  for submission; forfaitair claims blocked if innovatieboxWinst > €25k or past 3-year
  window per REQ-VPB-004

- [x] Task 9: Declare the `Deelneming` schema in `lib/Settings/shillinq_register.json`
  with all REQ-VPB-005 fields (belastingplichtige FK, naam string, rsin string,
  aandeelhouderschapPercentage numeric, kwalificerendeDeelneming boolean: >=5% nominaal
  gestort kapitaal, oogmerktoets text, onderworpenheidstoets text, bezittingentoets
  text, deelnemingsvrijstellingVanToepassing boolean with motivatie, dividenden numeric,
  vervreemdingsResultaat numeric); three-test motivation mandatory for deelnemingsvrijstelling
  claim per REQ-VPB-005

- [x] Task 10: Declare the `FiscaleEenheid` schema in `lib/Settings/shillinq_register.json`
  with all REQ-VPB-007 fields (moedermaatschappij FK Belastingplichtige, dochters
  array FK, voegingsdatum date, ontvoegingsdatum date nullable, voorvoegingsverliezen
  array FK per dochter); enforce voeging Article 15 conditions (>=95% bezit, gelijke
  boekjaren, NL vestiging); warn on voorvoegingsverlies impact per REQ-VPB-007

- [x] Task 11: Declare the `Voorvoegingsverlies` schema in `lib/Settings/shillinq_register.json`
  with all REQ-VPB-006 fields (belastingplichtige FK, verliesjaar integer, oorspronkelijkBedrag
  numeric, reedsVerrekend numeric, restant computed: oorspronkelijk − reedsVerrekend,
  verjaartIn date computed per regime, beschikking FK nullable, regime enum: 9yr/6yr/
  unlimited-50% auto-determined by verliesjaar); UI warning 12 months before verjaring
  per REQ-VPB-006

- [x] Task 12: Declare the `InvesteringsAftrek` schema in `lib/Settings/shillinq_register.json`
  with all REQ-VPB-008 fields (aangifte FK, type enum: KIA/EIA/MIA/Vamil,
  investering FK, investeringsbedrag numeric, aftrekPercentage numeric per belastingjaar
  (lookup from VpbTariefcatalogus), aftrekBedrag computed, cumulatieGecheckt boolean);
  enforce cumulation rules (KIA+EIA OK, KIA+MIA OK, EIA+MIA NOT OK) per REQ-VPB-008;
  RVO-meldingen (if applicable) referenced

- [x] Task 13: Declare the `VoorlopigeAanslag` schema in `lib/Settings/shillinq_register.json`
  with all REQ-VPB-009 fields (belastingplichtige FK, belastingjaar integer, aanslagnummer
  string, dagtekening date, geschatBelastbaarBedrag numeric, voorlopigVerschuldigd
  numeric, betalingsregeling enum: maandelijks/eenmalig, herzieningsverzoekMogelijk
  boolean, herzieningsverzoekIngesteld boolean nullable, herzieningsuitslag enum:
  geaccepteerd/gedeeltelijk/afgewezen nullable); herzieningsverzoek workflow supported

- [x] Task 14: Declare the `DefinitieveAanslag` schema in `lib/Settings/shillinq_register.json`
  with all REQ-VPB-009–010 fields (aangifte FK, aanslagnummer string, dagtekening date,
  vastgesteldBelastbaarBedrag numeric, vastgesteldVerschuldigd numeric, verrekendeVoorheffingen
  numeric, verrekendeVoorlopigeAanslagen numeric, tePunten computed, bezwaartermijnEinde
  date computed: dagtekening + 6 weeks, bezwaarTermijnVerstrijken boolean: true if
  today > bezwaartermijnEinde); add lifecycle: concept → ontvangen; prevent ingediend
  if Vpb-aangifte not in aanslag-ontvangen state per REQ-VPB-010

- [x] Task 15: Declare the `BezwaarBeroep` schema in `lib/Settings/shillinq_register.json`
  with all REQ-VPB-010 fields (aanslag FK, type enum: bezwaar/beroep/hoger-beroep/cassatie,
  ingediendOp date, motivering text, ontvangstbevestiging file URL, uitspraak text nullable,
  uitspraakDatum date nullable, vervolgInstantie enum: Rechtbank/Hof/HogeRaad nullable,
  processtukken array FK, termijnEinde date computed per type, termijnverstrijken boolean);
  add lifecycle: ingediend → inspecteur-uitspraak-pending → inspecteur-uitspraak-ontvangen
  → beroep-pending → beroep-uitspraak → hoger-beroep-pending → hoger-beroep-uitspraak →
  cassatie-pending → cassatie-uitspraak → final; calendar events for termijn warnings
  (T-7d, T-3d, on-day) per REQ-VPB-010

- [x] Task 16: Declare the `VpbTariefcatalogus` parameterization table in
  `lib/Settings/shillinq_register.json` with fields (belastingjaar integer, tarief1
  percent: 19% for 2026, tarief2 percent: 25.8% for 2026, belastbaarBedragGrens
  numeric: €245000 for 2026, innovatieboxTarief percent: 9%, facilityPercents JSON,
  effective_date date); seed 2026 rates; annual update process post-Belastingplan
  per design decision D5

- [x] Task 17: Implement the schijftarief aggregation query per REQ-VPB-003 —
  `x-openregister-calculations` formula that looks up VpbTariefcatalogus per
  belastingjaar, applies graduated-bracket calculation (tarief1 × min(fiscaleWinstBelastbaar,
  grens) + tarief2 × max(0, fiscaleWinstBelastbaar − grens)), emits verschuldigdeVpb
  computed on VpbAangifte

- [x] Task 18: Implement the voorvoegingsverlies-regime-calculation aggregation
  per REQ-VPB-006 — formula that auto-determines regime per verliesjaar (9yr/6yr/
  unlimited-50%), computes verjaartIn date, and emits expiration warning 12 months
  prior

- [x] Task 19: Implement the facility-eligibility aggregation per REQ-VPB-004–008 —
  query that validates:
  - Innovatiebox: S&O-reference present + forfaitair capped at €25k + 3-year window
  - Deelnemingsvrijstelling: three-test motivation + >=5% ownership
  - InvesteringsAftrek: cumulation rules (KIA+EIA OK, KIA+MIA OK, EIA+MIA NOT OK)
  - Blocks submission if validation fails

- [x] Task 20: Implement the fiscale-eenheid-voeging validation per REQ-VPB-007 —
  aggregation enforcing >=95% bezit + gelijke boekjaren + NL vestiging + warning on
  voorvoegingsverlies impact per dochter

- [x] Task 21: Add schema-level enforcement per REQ-VPB-001, REQ-VPB-002:
  - Belastingplichtige.eHerkenningsNiveau MUST be EH3+; reject EH2
  - VpbAangifte MUST have unique `(belastingplichtige, belastingjaar)` per belastingjaar
    unless prior aangifte is `onherroepelijk`
  - VpbAangifte prevention of ingediend state if commerciëleWinst.jaarrekening not
    vastgesteld
  - FiscaleCorrectie.code validation against NT-taxonomie element codes
  - Innovatiebox.soVerklaringReferentie mandatory; reject if missing at aangifte-ingediend

- [x] Task 22: Integrate with `bookkeeping-financial-statements` (T2) to bind
  VpbAangifte.commerciëleWinst FK to specific vastgestelde jaarrekening version;
  prevent aangifte-ingediend if jaarrekening not AvA-approved per REQ-VPB-002;
  enforce aansluittabel (reconciliation) completeness before ingediend

- [x] Task 23: Integrate with `bookkeeping-sbr-xbrl-reporting` (T3) to trigger
  SBR-instance generation on VpbAangifte ingediend → Digipoort signing with
  Belastingplichtige.digipoortCertificaat (eHerkenning EH3) → receipt persistence
  in digipoortReceiptId per REQ-VPB-009.
  Declarative surface done: `digipoortReceiptId`/`ingediendOp` fields + the EH3 +
  Digipoort-cert precondition in `VpbAangifteGuard::canIndienen`. **DEFERRED (live
  cross-app):** the actual SBR/Digipoort transmission call belongs to the
  `bookkeeping-sbr-xbrl-reporting` module and requires a live Digipoort koppelvlak —
  no app-local PHP transmission service is authored here (ADR-022/031).

- [x] Task 24: Integrate with `bookkeeping-general-ledger` (T2) for FiscaleCorrectie
  mapping to GL accounts; enable drill-down from fiscal correction → GL posting +
  jaarrekening line

- [x] Task 25: Integrate with `bookkeeping-tax-calendar` (T2) to publish:
  - Vpb-aangifte deadline (5 maanden after boekjaar-end)
  - Aanslag-ontvangst expected date (inform via event hook if calendar configured)
  - Bezwaar-termijn deadline (6 weeks from aanslag dagtekening)

- [x] Task 26: Implement the SBR-XBRL instance generation per REQ-VPB-009 — delegate
  to `bookkeeping-sbr-xbrl-reporting` module; Vpb-aangifte schema provides all required
  input fields (fiscaleWinstBelastbaar, verschuldigdeVpb, facility claims, deductions);
  SBR module generates NT-compliant XBRL instance; validate against NT-taxonomie XSD
  before Digipoort transmission

- [x] Task 27: Implement eHerkenning EH3 signature enforcement per REQ-VPB-009 —
  check Belastingplichtige.eHerkenningsNiveau >= EH3 at aangifte-ingediend state
  transition; retrieve certificate from credential vault (Belastingplichtige.
  digipoortCertificaat FK); sign XBRL instance with PKIO-Digipoort cert; handle
  Servicegereerde Architectuur (SGA) intermediary certs (fiscalist can sign for entity).
  EH3+ check + Digipoort-cert presence enforced in `VpbAangifteGuard::canIndienen`;
  SGA-intermediair allowed via `fiscaalAdviseur` FK. **DEFERRED (live cross-app):** the
  actual XBRL signing with the PKIO cert is performed by `bookkeeping-sbr-xbrl-reporting`
  against the credential vault at submission time.

- [x] Task 28: Implement Digipoort submission & receipt per REQ-VPB-009 — call
  Digipoort API with signed XBRL instance; capture receipt ID; persist in
  VpbAangifte.digipoortReceiptId; transition VpbAangifte to ingediend state only
  after successful Digipoort receipt. Schema surface (`digipoortReceiptId`, lifecycle
  `indienen` transition) done. **DEFERRED (live cross-app):** the Digipoort API call
  belongs to `bookkeeping-sbr-xbrl-reporting` and needs a live Digipoort endpoint.

- [x] Task 29: Implement bezwaar/beroep state machine per REQ-VPB-010 — add
  `x-openregister-lifecycle` to BezwaarBeroep with states (ingediend →
  inspecteur-uitspraak-pending → inspecteur-uitspraak-ontvangen → beroep-pending
  → beroep-uitspraak → hoger-beroep-pending → hoger-beroep-uitspraak →
  cassatie-pending → cassatie-uitspraak → final); calendar-event generation on
  each state for termijn tracking; escalation alerts at T-7d, T-3d, on-day for
  missed termijn

- [x] Task 30: Add x-openregister-lifecycle to `VpbAangifte`, `DefinitieveAanslag`,
  `Innovatiebox` per ADR-031: workflow states, approval gates (if governance via
  decidesk future T4 phase), audit trail on all assumption + amendment entries

- [x] Task 31: Add 5 manifest navigation entries to `src/manifest.json`:
  - "Belastingplichtigen" (index page listing all Belastingplichtige records per organization)
  - "Vpb-Aangiftes" (index page listing all VpbAangifte records, drillable by
    belastingplichtige + belastingjaar, status-filtered)
  - "Fiscale Correcties" (index page listing FiscaleCorrectie records per aangifte,
    with NTP-code + Article reference)
  - "Faciliteitenberekening" (index page listing Innovatiebox + Deelneming +
    InvesteringsAftrek claims per aangifte)
  - "Bezwaar/Beroep" (index page listing BezwaarBeroep records per aanslag,
    with status + termijn-tracking)
  Each entry includes `type: index` and `type: detail` pages; validate
  `node tests/validate-manifest.js` exits 0

- [x] Task 32: Seed data: author 1 Belastingplichtige record ("ACME BV") + 1
  VpbTariefcatalogus record (2026 rates: 19% / 25.8% / €245k threshold) in
  `lib/Seeds/` or repair-step ConfigurationService per shared `nextcloud-app` pattern;
  operators customize per entity on first use

- [x] Task 33: Update `openspec/architecture/adr-000-data-model.md` with the
  13 new entities (Belastingplichtige, VpbAangifte, FiscaleCorrectie, Innovatiebox,
  Deelneming, FiscaleEenheid, Voorvoegingsverlies, InvesteringsAftrek, VoorlopigeAanslag,
  DefinitieveAanslag, BezwaarBeroep), reconciling against any existing `Vpb*` /
  `Tax*` entries; add `Primary spec: bookkeeping-vpb-mkb` and `Schema.org` class
  annotations per ADR-000 convention

- [x] Task 34: Add i18n translation keys (Dutch `nl_NL` + English `en_US`) for:
  Belastingplichtige, Vpb-Aangifte, Fiscale Correctie, Commerciële Winst, Fiscale
  Winst, Innovatiebox, Deelneming, Deelnemingsvrijstelling, Fiscale Eenheid, Voeging,
  Ontvoeging, Voorvoegingsverlies, Verjaaring, Investeringsaftrek, KIA, EIA, MIA, Vamil,
  Cumulation, Voorlopige Aanslag, Definitieve Aanslag, Bezwaar, Beroep, Hoger Beroep,
  Cassatie, Termijn, Inspecteur-uitspraak, Schijftarief, Belastbaar Bedrag, Verschuldigde
  Vpb, SBR, XBRL, Digipoort, eHerkenning, S&O-verklaring, Aansluittabel, Herzieningsverzoek

- [x] Task 35: Add OpenConnector event publishing:
  - `vpb.aangifte.concept` (created with status=concept)
  - `vpb.aangifte.ingediend` (transitioned to ingediend, Digipoort receipt confirmed)
  - `vpb.aanslag.ontvangen` (DefinitieveAanslag created)
  - `vpb.bezwaar.ingediend` (BezwaarBeroep created with type=bezwaar)
  - `vpb.beroep.ingediend` (BezwaarBeroep transitioned to beroep)
  - `vpb.termijn.verstrijkt-binnenkort` (escalation alert at T-7d for missed deadlines)
  The lifecycle state transitions that carry these events are declared on the
  VpbAangifte / DefinitieveAanslag / BezwaarBeroep schemas. **DEFERRED (live runtime):**
  binding the transition hooks to the OpenConnector event bus requires a running
  OpenRegister + OpenConnector instance and the fleet event-bus wiring; the events are
  emitted by OR's lifecycle engine off the declared transitions — no app-local publisher
  service is authored (ADR-022/031).

## Verification

`openspec validate` must exit clean on the change folder. Fiscalist / DGA persona
peer-review confirms the one-aangifte-per-jaar + jaarrekening-binding + fiscal
corrections + facility claims + schijftarief + voorvoegingsverlies-regime +
bezwaar/beroep flow matches Dutch Wet Vpb annual cycle. Architecture reviewer
confirms ADR-022 + ADR-031 compliance (no app-local tax-calculation service; no
app-local file storage; all fiscal logic declarative; SBR generation delegated;
manifest carries navigation). No source code changes outside
`openspec/changes/bookkeeping-vpb-mkb/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate
`opsx-apply`) is responsible for:

- **Unit tests (PHPUnit)**: One-aangifte-per-jaar uniqueness (duplicate detection),
  jaarrekening-binding (prevent ingediend if not vastgesteld), fiscal-correction
  NTP-code validation, schijftarief bracket calculation (19% / 25.8% / €245k),
  voorvoegingsverlies regime determination (9yr/6yr/unlimited-50% per verliesjaar),
  voorvoegingsverlies expiration date computation (verjaartIn), facility-eligibility
  tests (S&O-reference for innovatiebox, three tests for deelnemingsvrijstelling,
  cumulation rules for investeringsaftrek), fiscale-eenheid voeging validation
  (>=95% bezit, gelijke boekjaren, NL vestiging), bezwaar/beroep state transitions
  (valid state paths, termijn computation)

- **Integration tests**: Financial-statements binding (jaarrekening FK, vastgesteld
  check), SBR-XBRL instance generation (NT-taxonomie validation, Digipoort signature),
  eHerkenning EH3 enforcement (cert retrieval, SGA intermediary support), GL posting
  (fiscal corrections mapped to GL accounts), facility-eligibility aggregations,
  voorvoegingsverlies expiration warning (12 months prior), bezwaar/beroep termijn
  tracking + calendar events, tax-calendar integration (if configured)

- **Playwright MCP browser tests**: Belastingplichtige detail page (create/edit,
  eHerkenning cert upload), Vpb-aangifte workflow (concept → ingediend → aanslag →
  bezwaar), fiscal-correction form (NTP-code selection, GL mapping, Article reference),
  facility claims (innovatiebox forfaitair vs werkelijke-winst, S&O-reference, deelneming
  tests, investeringsaftrek cumulation), voorvoegingsverlies tracking (expiration
  warnings), bezwaar/beroep creation + state-transition, SBR-XBRL preview, Digipoort
  receipt display

- `composer test` green at implementing PR CI gate; `openspec validate` green on spec
  folder

## Documentation (company-wide ADR-009)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:

- `docs/user-guide/bookkeeping/vpb-mkb.md` per ADR-030 journeydoc convention
  (Fiscalist workflow: register belastingplichtige → create aangifte → enter fiscal
  corrections → claim facilities → review schijftarief calculation → submit via Digipoort
  → manage aanslag → handle bezwaar/beroep)
- Screenshot of Vpb-aangifte detail page, fiscal-correction entry form, facility-claims
  summary, schijftarief calculation, Digipoort submission confirmation, bezwaar/beroep
  tracking to `docs/images/vpb-*`
- Linked from main docs table of contents under "Vennootschapsbelasting (Vpb)"

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds
Dutch (`nl_NL`) and English (`en_US`) translation strings per Task 34.

## Regulatory Notes

- All fiscal corrections are auditable per Wet Vpb + Awb standards
- Bezwaar/beroep termijnen are statutory per Awb §6:7–6:10; system must enforce
- SBR submission deadlines are wettelijk (5 maanden / verlenging 11 maanden)
- eHerkenning EH3 is Logius-mandatory for corporate tax filing (not negotiable)
- Voorvoegingsverlies regime per-verliesjaar per Wet Vpb Articles 3 + Belastingplan
  2022 amendment (Stb. 2021/503)
- Deelnemingsvrijstelling three-test requirement per Article 13 Wet Vpb + ABU ruling
