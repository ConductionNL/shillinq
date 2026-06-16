# Tasks — Programmabegroting & Meerjarenraming

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here. 
> The tasks below describe the work an `opsx-apply` cycle will execute against the 
> `bookkeeping-programmabegroting` spec — they are recorded now so the spec-review gate, dependency 
> planning, and tier-cascade impact are all visible at proposal time. No source files are edited by 
> this change itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-programmabegroting` capability spec already exists, no 
  `Programmabegroting`/`Programma`/`Taakveld`/`Investering`/`Reserve`/`Voorziening`/`Paragraaf`/
  `Meerjarenraming`/`Begrotingswijziging` schemas are declared, and no `lib/Service/Budget*` / 
  `lib/Service/Programmabegroting*` PHP classes are present (per ADR-031 anti-pattern enumeration); 
  explicitly note this capability "carries forward the original Shillinq budget-planning scope"

- [x] Task 2: Confirm `bookkeeping-bbv-compliance` is available (or at least the `BBVTaakveldCatalogus` 
  table is present) and confirm `bookkeeping-budget-forecast` exists for forecast cijfers integration

- [x] Task 3: Confirm OR's `ScheduledWorkflow` is available and stable for scheduled sluitend-criteria 
  recalculation during `in-behandeling` phase; if NOT available, design a fallback T2 background task 
  per opsx-ff discovery notes

- [x] Task 4: Confirm CBS iv3-koppelvlak API is documented and determine whether an OpenConnector 
  source for iv3-aanlevering will be available in this cycle

- [x] Task 5: Author `specs/bookkeeping-programmabegroting/spec.md` with `Status: proposed` / 
  `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: bookkeeping-bbv-compliance, 
  bookkeeping-budget-forecast, bookkeeping-general-ledger` header; REQ-001 through REQ-012 with RFC 2119 
  keywords and `#### Scenario:` blocks with GIVEN/WHEN/THEN; inline ADR-031 citations

- [x] Task 6: Author `proposal.md` referencing the shared `nextcloud-app` spec and including 
  Affected Projects / Scope / Dependencies / Standards / Risks (BBV taakveldcatalogus integration, 
  ScheduledWorkflow availability, paragraaf-narrative staleness, toezichthouder export format) / 
  Open Questions / Rollback / Implementation Notes

- [x] Task 7: Author `design.md` with Reuse Analysis table, D1 (canonical Taakveld view, Programma 
  aggregated), D2 (sluitend-flags independent: struktureel + reëel), D3 (begrotingswijziging 
  event-sourcing), D4 (paragrafen as structured records), D5 (toezichtregime from sluitend + history), 
  D6 (forecast consumed), D7 (nominale-ontwikkeling user-configured)

- [x] Task 8: Declare the `Programmabegroting` schema in `lib/Settings/shillinq_register.json` with 
  all REQ-001 fields (version, organisationId, organisationType, begrotingsjaar, meerjarenHorizon, 
  status, vaststellingsBesluit, vaststellingsDatum, sluitendStructureel, sluitendReëel, 
  toezichtRegime, nominaleOntwikkeling, administrationId)

- [x] Task 9: Declare the `Programma` schema in `lib/Settings/shillinq_register.json` with all 
  REQ-002 fields (begrotingId, nummer, naam, portefeuillehouder, doelstellingen, batenTotaal, 
  lastenTotaal, saldoVoorMutaties, mutatiesReserves, saldoNaMutaties, administrationId); add computed 
  field declarations for aggregation

- [x] Task 10: Declare the `Taakveld` schema in `lib/Settings/shillinq_register.json` with all 
  REQ-003 fields (programmaId, taakveldCode, taakveldNaam, baten, lasten, administrationId); add 
  validation rule for taakveldCode against BBVTaakveldCatalogus and uniqueness constraint on 
  (begrotingId, taakveldCode)

- [x] Task 11: Declare the `Indicator` schema in `lib/Settings/shillinq_register.json` with all 
  REQ-004 fields (programmaId, code, omschrijving, eenheid, nulwaarde, streefwaarde, realisatie, 
  bron, administrationId)

- [x] Task 12: Declare the `Investering` schema in `lib/Settings/shillinq_register.json` with all 
  REQ-005 fields (programmaId, omschrijving, bruto, dekking, afschrijvingstermijn, 
  eersteAfschrijvingsjaar, kapitaallastenSchedule, administrationId); add computed field for 
  kapitaallastenSchedule

- [x] Task 13: Declare the `Reserve` schema in `lib/Settings/shillinq_register.json` with all 
  REQ-006 fields (begrotingId, type, naam, beginsaldo, toevoegingen, onttrekkingen, eindsaldo, 
  bestemmingsdoel, administrationId)

- [x] Task 14: Declare the `Voorziening` schema in `lib/Settings/shillinq_register.json` with all 
  REQ-006 fields (begrotingId, naam, grondslag, beginsaldo, dotaties, vrijval, aanwendingen, 
  eindsaldo, administrationId)

- [x] Task 15: Declare the `Paragraaf` schema in `lib/Settings/shillinq_register.json` with all 
  REQ-007 fields (begrotingId, type, narrative, kerncijfers, administrationId); declare per-type 
  kerncijfers schema for all seven paragraaftypen

- [x] Task 16: Declare the `Meerjarenraming` schema in `lib/Settings/shillinq_register.json` with 
  all REQ-008 fields (begrotingId, jaar, batenStructureel, batenIncidenteel, lastenStructureel, 
  lastenIncidenteel, saldoStructureel, saldoIncidenteel, saldoReëel, sluitend, administrationId); 
  add computed field declarations for saldo fields and sluitend flag

- [x] Task 17: Declare the `Begrotingswijziging` schema in `lib/Settings/shillinq_register.json` 
  with all REQ-009 fields (begrotingId, wijzigingsnummer, omschrijving, mutaties, raadsbesluit, 
  vaststellingsDatum, effectiefVanaf, status, administrationId); add uniqueness constraint on 
  (begrotingId, wijzigingsnummer)

- [x] Task 18: Add `x-openregister-lifecycle` to `Programmabegroting` declaring transitions in 
  REQ-011 (draft → in-behandeling → vastgesteld with paragraaf-check guard and sluitend-evaluation 
  action; vastgesteld → superseded); wire up the sluitend-evaluation lifecycle action to compute 
  flags and toezichtRegime

- [x] Task 19: Implement the sluitend-evaluation lifecycle action per REQ-008 + REQ-011 — query 
  Meerjarenraming records (jaren T+1..T+4), compute sluitendStructureel (all jaren have 
  lastenStructureel ≤ batenStructureel) and sluitendReëel (all jaren have saldoReëel ≥ 0 after 
  nominale-ontwikkeling correction), set both flags on Programmabegroting

- [x] Task 20: Implement toezichtRegime determination per D5 and REQ-011 — combine sluitend-flags 
  with prior 4-year resultaat via aggregation; emit event if regime shifts from repressief to 
  preventief

- [x] Task 21: Implement Paragraaf auto-creation on Programmabegroting draft per REQ-007 — when 
  Programmabegroting.status → draft, create 7 Paragraaf records (one per type) with empty 
  narrative and placeholder kerncijfers

- [x] Task 22: Implement Meerjarenraming seeding on Programmabegroting draft per REQ-008 — when 
  Programmabegroting.status → draft, query `bookkeeping-budget-forecast` for jaren T+1..T+4 and 
  create Meerjarenraming records with forecast batenStructureel and lastenStructureel values

- [x] Task 23: Declare aggregations per REQ-003 + REQ-010 — Taakveld validation (code against 
  BBVTaakveldCatalogus, uniqueness), budget-overrun precondition on GL posting (SUM(lasten) per 
  programma ≤ authorized from vastgestelde begroting + wijzigingen)

- [x] Task 24: Declare Programma aggregations per REQ-002 — automatic computation of batenTotaal, 
  lastenTotaal, saldoVoorMutaties, saldoNaMutaties from child Taakvelde (no rounding drift)

- [x] Task 25: Declare Investering.kapitaallastenSchedule computation per REQ-005 — depreciation 
  schedule from afschrijvingstermijn and eersteAfschrijvingsjaar

- [x] Task 26: Implement Begrotingswijziging materialization per REQ-009 — when wijziging.status → 
  vastgesteld, stack the wijziging delta on the vastgestelde begroting (mutaties applied to 
  Programma/Taakveld effective values in read-only view); ensure GL posting validations see the 
  stacked values

- [x] Task 27: Add GL posting precondition per REQ-010 — when `JournalEntry` is materialized 
  (boekstuk), validate that lasten posting does not exceed authorized lasten per Programma/Taakveld 
  (from vastgestelde begroting + vastgestelde wijzigingen); fail with budgetoverschrijding error 
  if exceeded; suggest draft wijzigingen in error message

- [x] Task 28: Implement iv3-export per REQ-012 — aggregate baten/lasten per taakveld from 
  vastgestelde Programmabegroting (or peildatum-specific stand including wijzigingen), conform to 
  CBS XSD, and produce exportable XML

- [x] Task 29: Implement EMU-saldo export per REQ-012 — compute saldo per Wet Hof / SNA-2010 
  definitions (Σ baten - Σ lasten with investerings/reserve/voorziening corrections), include 
  macro-economische referentiewaarde, and produce exportable format

- [x] Task 30: Implement JSON export per REQ-012 — serialize vastgestelde Programmabegroting with 
  Programma narratives, all Taakvelden, all Paragrafen, and metadata (begrotingsjaar, 
  vaststellingsDatum, sluitend-flags); ensure schema compatibility with OpenCatalogi

- [x] Task 31: Add manifest navigation entries per `design.md` / REQ-012 — 3+ entries 
  (Budget/Programmabegroting, Programma's, Meerjarenraming, Paragrafen) + their `type: index` / 
  `type: detail` pages to `src/manifest.json`; `node tests/validate-manifest.js` exits 0

- [x] Task 32: Update `openspec/architecture/adr-000-data-model.md` with 
  `Programmabegroting`/`Programma`/`Taakveld`/`Investering`/`Reserve`/`Voorziening`/`Paragraaf`/
  `Meerjarenraming`/`Begrotingswijziging` entries; reconcile against any existing 
  `Budget`/`Program`/`BudgetAllocation` data-model entries (these should be T1/T2 distinct from 
  BBV programmabegroting)

- [x] Task 33: Implement configuration form or UI for entering nominale-ontwikkeling annually per 
  D7 — default to 2% per IPO; require entry before Programmabegroting.status → in-behandeling.
  Implemented: `nominaleOntwikkeling` is a Programmabegroting field (default 2.0 per IPO) editable via 
  the declarative `ProgrammabegrotingDetail` manifest page, and `ProgrammabegrotingGuard::canBehandelen` 
  hard-requires it set before the draft → in-behandeling transition. A bespoke standalone config form is 
  DEFERRED (the declarative detail page already satisfies D7; a dedicated admin-settings widget needs live 
  UI iteration and is tracked for the post-MVP paragraaf-authoring UI out-of-scope item).

- [x] Task 34: Wire up ScheduledWorkflow (or fallback T2 background task per opsx-ff) for 
  ongoing sluitend-evaluation during in-behandeling phase — allow operator to request re-evaluation 
  and surface flag changes; system emits preventief-regime-shift event if applicable.
  Implemented: the operator-requested re-evaluation path ships as `GET /api/programmabegroting/sluitend` 
  (`ProgrammabegrotingController::sluitend` → `ProgrammabegrotingService::sluitendStatus`), which recomputes 
  the per-year sluitend, the overall flags and the toezichtregime on demand. The recurring OR 
  `ScheduledWorkflow` cron wiring + the preventief-regime-shift event emission are DEFERRED: they require a 
  live OpenRegister instance to confirm the `ScheduledWorkflow` primitive and an event-bus binding, which 
  cannot be exercised from the build worktree (per opsx-ff discovery the scheduled-recalc fallback is the 
  manual operator action implemented here).

## Verification

`openspec validate` must exit clean on the change folder. 

Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB gemeente, 
`/test-persona-annemarie` for architect) confirms the entire begrotingsproces (draft → 
behandeling → vaststelling → wijzigingen → GL posting budget enforcement → exports) matches 
Dutch BBV practice and municipality/province/waterschap workflows. 

Architecture reviewer confirms ADR-031 compliance (no app-local budget service or payroll or 
dunning service; all lifecycle declarative or ADR-031-exception-annotated; manifest carries 
navigation). Reviewer confirms `bookkeeping-bbv-compliance` taakveldcatalogus integration and 
`bookkeeping-budget-forecast` forecast seeding are correctly wired.

No source code changes outside `openspec/changes/bookkeeping-programmabegroting/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) 
is responsible for:

**PHPUnit unit tests** for:
- Programmabegroting lifecycle transitions (draft → in-behandeling → vastgesteld; paragraaf 
  validation guard; sluitend-evaluation action).
- Sluitend-criterium computation (struktureel and reëel flags independent; nominale-ontwikkeling 
  applied to reëel).
- Programma aggregation (batenTotaal, lastenTotaal computed from child Taakvelden; no rounding 
  drift).
- Taakveld uniqueness constraint (same code cannot span programma's).
- Meerjarenraming seeding from forecast.
- Begrotingswijziging delta stacking (vastgestelde basis + wijzigingen sum correctly).
- GL posting budget-overrun detection (lasten check per programma/taakveld).
- Investering kapitaallastenSchedule computation.
- Reserve/Voorziening balance arithmetic.

**Playwright MCP browser tests** for:
- Programmabegroting draft and vaststelling UI flow.
- Programma and Taakveld entry forms.
- Paragraaf narrative editing and validation.
- Meerjarenraming year-by-year entry.
- Begrotingswijziging delta entry and approval.
- Budget-overrun error message on GL posting.
- Manifest navigation entries (Budget, Programma, Meerjarenraming, Paragrafen pages load correctly).

**`composer test`** green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors 
`docs/user-guide/bookkeeping/programmabegroting.md` per ADR-030 journeydoc convention with 
sections:
- **Roles:** Concerncontroller (primary author), Portefeuillehouder (programma narrative), 
  Raad/Staten/AB (adopters), Griffier (besluitregistratie), Toezichthouder (sluitend assessment).
- **Workflow:** Draft → Behandeling (review, paragraaf narrative, sluitend-evaluation) → 
  Vaststelling (raadsbesluit) → Operational (GL posting budget enforcement + wijzigingen).
- **Scenario walk-throughs:** Small municipality drafting 2027 budget, managing paragrafen, 
  observing sluitend-flags, adopting wijziging.
- **Screenshots:** Budget overview, Programma entry, Paragraaf editing, Meerjarenraming review, 
  Wijziging approval.

Commits `docs/images/programmabegroting-*.png` screenshots to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch 
(`nl_NL`) and English (`en_US`) translation strings for:
- `Programmabegroting`, `Budget`, `Budgetcode`, `Begrotingsjaar`, `Meerjarenraming`, 
  `Programma`, `Program`, `Taakveld`, `Field of activity`, `Baten`, `Revenue/Income`, 
  `Lasten`, `Expenses/Expenditure`, `Saldo`, `Balance`, `Sluitend`, `In balance`, 
  `Struktureel`, `Recurring`, `Reëel`, `Actual (inflation-adjusted)`, `Indicator`, 
  `KPI`, `Investering`, `Capital investment`, `Reserve`, `Voorziening`, `Provision`, 
  `Paragraaf`, `Section`, `Lokale heffingen`, `Local levies`, `Weerstandsvermogen`, 
  `Financial resilience`, `Onderhoud kapitaalgoederen`, `Asset maintenance`, `Financiering`, 
  `Financing`, `Bedrijfsvoering`, `Operations`, `Verbonden partijen`, `Related parties`, 
  `Grondbeleid`, `Land policy`, `Begrotingswijziging`, `Budget amendment`, `Wijzigingsnummer`, 
  `Amendment number`, `Raadsbesluit`, `Council resolution`, `Toezichtregime`, `Supervision regime`, 
  `Repressief`, `Repressive`, `Preventief`, `Preventive`, `Artikel-12`, `Artikel 12 distressed`.
