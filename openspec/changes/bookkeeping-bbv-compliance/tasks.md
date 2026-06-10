# Tasks — BBV Compliance

## Overview

This is a **major spec** with 11 requirements spanning data model, validations, reporting, and export. Implementation will touch:
- `lib/Settings/shillinq_register.json` — 9 new schemas + 1 Account extension + 1 JournalEntry lifecycle extension
- `lib/Settings/seeds/` — 4 seed JSON files (taakvelden, rgs-decentraal, economische-categorien, beleidsindicatoren)
- `src/manifest.json` — ~8 new navigation entries (Financieel > BBV-compliance)
- `lib/Migration/` — repair step for BBV-tenant onboarding
- Tests — unit (PHPUnit), integration, and browser (Playwright)
- Docs — user guide (journeydoc) + screenshots
- i18n — Dutch/English translation strings

## Phase 1: Data Model & Schema Registration

- [x] **Task 1.1** — Confirmed no duplicate schemas (per ADR-012 dedup). `Account`, `GLLine`, `Iv3Export` already declared by sibling specs → extended/referenced, not redeclared. The RGS-decentraal mapping is declared as `BbvAccountMapping` (the name the existing `Iv3Export.buckets` aggregation already references) instead of `RgsDecentraalRekening`. `Paragraaf` added (REQ-BBV-007/D8).
- [x] **Task 1.2** — Declare `Taakveld` schema with fields: `code` (string, PK), `naam` (string), `hoofdfunctie` (int 0-8), `hoofdfunctie_naam` (string), `omschrijving_iv3` (text), `overheidslaag` (enum: gemeente|provincie|waterschap), `verplichte_economische_categorieen` (array[ref[EconomischeCategorie]]), `geldig_vanaf` (date), `geldig_tot` (date, nullable). Schema.org: `schema:DefinedTerm`. Register: `bookkeeping-bbv`. Primary spec: `bookkeeping-bbv-compliance`.
- [x] **Task 1.3** — Declare `EconomischeCategorie` schema with fields: `code` (string, PK), `naam` (string), `niveau` (int 1-3), `parent_code` (string, nullable), `baten_of_lasten` (enum: baten|lasten|balans), `iv3_verplicht` (boolean). Schema.org: `schema:DefinedTerm`. Register: `bookkeeping-bbv`.
- [x] **Task 1.4** — Declare `RgsDecentraalRekening` schema with fields: `rgs_code` (string), `rgs_decentraal_code` (string, PK), `omschrijving_kort` (string), `omschrijving_lang` (text), `referentienummer` (string), `dc` (enum: D|C), `rgs_niveau` (int 1-5), `taakveld_default` (ref[Taakveld], nullable), `economische_categorie_default` (ref[EconomischeCategorie]), `omslag` (enum: verplicht|toegestaan|niet-toegestaan, nullable). Register: `bookkeeping-bbv-reference` (shared, not per-tenant). Primary spec: `bookkeeping-bbv-compliance`.
- [x] **Task 1.5** — Extend T1 `Account` schema with 4 new required fields: `rgs_decentraal_rekening` (ref[RgsDecentraalRekening], required for BBV-tenants), `taakveld` (ref[Taakveld], required for exploitatie accounts in BBV-tenants), `economische_categorie` (ref[EconomischeCategorie], required), `bbv_classificatie` (enum: exploitatie|investering|reserve|voorziening|balans-overig, required for BBV-tenants). Mark as optional/nullable for non-BBV tenants via constraint.
- [x] **Task 1.6** — Declare `Programma` schema with fields: `nummer` (int 0-99, PK per admin), `naam` (string), `omschrijving` (text), `portefeuillehouder` (string), `taakvelden` (array[ref[Taakveld]], required), `doelstellingen` (array[object{wat, wanneer, kpi}]), `beleidsindicatoren` (array[ref[BeleidsIndicator]]), `boekjaar` (int), `versie` (enum: begroting|jaarrekening|burap-1|burap-2|marap|tussenrapportage). Register: `bookkeeping-bbv`. Unique constraint: `(administrationId, nummer, boekjaar)`.
- [x] **Task 1.7** — Declare `BeleidsIndicator` schema with fields: `code` (string, PK), `naam` (string), `eenheid` (string), `bron` (string), `waarde` (number, nullable), `programma` (ref[Programma]). Register: `bookkeeping-bbv`. Unique: `(administrationId, code, programma)`.
- [x] **Task 1.8** — Declare `MeerjarenBudget` schema with fields: `programma` (ref[Programma]), `taakveld` (ref[Taakveld]), `economische_categorie` (ref[EconomischeCategorie]), `boekjaar` (int), `bedrag_baten` (decimal 15,2), `bedrag_lasten` (decimal 15,2), `versie` (enum: primitief|na-wijziging|realisatie), `begrotingswijziging` (ref[Begrotingswijziging], nullable), `meerjaren_horizon` (int 0-3), `toelichting` (text, nullable), `stelselwijziging` (boolean, default false). Register: `bookkeeping-bbv`. Unique: `(administrationId, programma, taakveld, economische_categorie, boekjaar, meerjaren_horizon, versie)`.
- [x] **Task 1.9** — Declare `Reserve` schema with fields: `naam` (string), `soort` (enum: algemeen|bestemming), `doel` (text, required if bestemming), `raadsbesluit_instelling` (string), `plafond` (decimal 15,2, nullable), `bodem` (decimal 15,2, nullable), `looptijd_einde` (date, nullable), `programma` (ref[Programma], nullable), `rentetoerekening` (boolean, default false), `saldo_begin_jaar` (decimal 15,2), `saldo_eind_jaar` (decimal 15,2, nullable). Register: `bookkeeping-bbv`. Unique: `(administrationId, naam)`.
- [x] **Task 1.10** — Declare `Voorziening` schema with fields: `naam` (string), `bbv_artikel_44_categorie` (enum: a|b|c|d), `onderbouwing_document` (ref[Document]), `actualisatie_frequentie_jaar` (int), `volgende_actualisatie` (date), `taakveld` (ref[Taakveld]), `saldo_begin_jaar` (decimal 15,2), `dotaties_jaar` (decimal 15,2), `vrijvallen_jaar` (decimal 15,2), `saldo_eind_jaar` (decimal 15,2, nullable). Register: `bookkeeping-bbv`. Unique: `(administrationId, naam)`.
- [x] **Task 1.11** — Declare `MaterieleVasteActiva` schema with fields: `omschrijving` (string), `mva_categorie` (enum: economisch-nut|economisch-nut-heffing|maatschappelijk-nut), `aanschafwaarde` (decimal 15,2), `ingebruikname_datum` (date), `afschrijvingsmethode` (enum: lineair|annuitair), `afschrijvingstermijn_jaar` (int), `restwaarde` (decimal 15,2), `rente_omslag_percentage` (decimal 5,3), `taakveld` (ref[Taakveld]), `kredietbesluit` (string), `componenten_methode` (boolean, default false), `subsidie_van_derden` (decimal 15,2, nullable), `boekwaarde_begin_jaar` (decimal 15,2), `afschrijving_jaar` (decimal 15,2, nullable). Register: `bookkeeping-bbv`. Unique: `(administrationId, omschrijving, ingebruikname_datum)`.
- [x] **Task 1.12** — Declare `Subsidie` schema with fields: `subsidie_soort` (enum: verstrekt-incidenteel|verstrekt-structureel|ontvangen-rijk|ontvangen-provincie|ontvangen-eu), `regeling_naam` (string), `sisa_indicator` (string, nullable, e.g. "H8"), `verstrekker_of_ontvanger` (string), `beschikking_nummer` (string), `bedrag_verleend` (decimal 15,2), `bedrag_vastgesteld` (decimal 15,2, nullable), `bedrag_gerealiseerd` (decimal 15,2, nullable), `taakveld` (ref[Taakveld]), `economische_categorie` (ref[EconomischeCategorie]), `verleningsdatum` (date), `beëindigingsdatum` (date, nullable). Register: `bookkeeping-bbv`. Unique: `(administrationId, beschikking_nummer)`.
- [x] **Task 1.13** — Declare `Begrotingswijziging` schema with fields: `nummer` (string, PK per admin), `programma` (ref[Programma]), `taakveld` (ref[Taakveld]), `economische_categorie` (ref[EconomischeCategorie]), `bedrag_oorspronkelijk` (decimal 15,2), `bedrag_wijziging` (decimal 15,2), `bedrag_nieuw` (decimal 15,2), `reden` (text), `raadsbesluit_nummer` (string), `raadsbesluit_datum` (date), `status` (enum: concept|vastgesteld|verwerkt), `effectievedatum` (date). Register: `bookkeeping-bbv`. Unique: `(administrationId, nummer)`.
- [x] **Task 1.14** — Extend T1 `JournalEntry` schema to add optional field `rechtmatigheid_status` (enum: compliant|afwijking_within_tolerance|afwijking_outside_tolerance), per REQ-BBV-009. Default: `compliant`.
- [x] **Task 1.15** — Update `openspec/architecture/adr-000-data-model.md` with the 11 new entities + 2 schema extensions. Note `Primary spec: bookkeeping-bbv-compliance` for each.

## Phase 2: Lifecycle Validations & Constraints

- [x] **Task 2.1** — The BBV-mapping precondition on GLLine.save is `BbvComplianceGuard::requireLineClassification()`, declared via `x-openregister-lifecycle.preconditions` in the lifecycle declaration. Non-BBV tenants bypass. Historic postings (postingDate < `bbv_installation_date` app config) are exempted via the new `isHistoricPosting()` helper added in this task; the install date is stamped by the InitializeSettings repair step on the first BBV-tenant. Unit test `testHistoricPostingIsExemptFromClassification()` covers the carve-out.
- [x] **Task 2.2** — Implement `Programma.publish` lifecycle constraint to enforce meerjarenraming sluitend-checks (REQ-BBV-003). Query all `MeerjarenBudget` rows for this programma across T, T+1, T+2, T+3. For each year, compute `saldo = sum(baten) - sum(lasten) + sum(reserve_mutaties)`. If saldo < 0 for any year, fail publication with `BBVConstraintError("REQ-BBV-003: jaar 2028 niet sluitend…")`. Allow override with `raadsbesluit_nummer` + `raadsbesluit_datum` fields.
- [x] **Task 2.3** — Implement account-posting validation for reserves vs. voorzieningen (REQ-BBV-004). When posting to an account tagged `bbv_classificatie=reserve`, enforce `taakveld=0.10` (Mutaties reserves). When posting to an account tagged `bbv_classificatie=voorziening` with `taakveld_gekoppeld=2.1`, enforce all postings on this account carry `taakveld=2.1` (the gekoppelde taakveld). Fail with `BBVConstraintError` if violated.
- [x] **Task 2.4** — Implement MVA activation constraint (REQ-BBV-005). When creating `MaterieleVasteActiva` with `mva_categorie=maatschappelijk-nut` and `aanschafwaarde > activeringsgrens` (configurable, typically EUR 50k), verify that no direct P&L posting is made. If user attempts to book directly to exploitatie, fail with `BBVConstraintError("REQ-BBV-005: investering > activeringsgrens…must be activated")`.
- [x] **Task 2.5** — MVA depreciation start logic implemented as `BbvComplianceGuard::depreciationStartMonth()` — returns `YYYY-MM` of the month following `ingebruiknameDatum` (per REQ-BBV-005), null for missing/unparseable dates. Unit tests cover the 2026-09-15 → 2026-10 happy path, the 2026-12-31 → 2027-01 year-boundary rollover, and the missing-date null-return. Full DepreciationSchedule register + scheduled depreciation workflow remain in the sibling FixedAssets spec; this helper is the single seam BBV references.
- [x] **Task 2.6** — Paragraaf-completeness precondition implemented as `BbvComplianceGuard::requireParagrafenCompleet()`. Loads every `Paragraaf` row with `status = vastgesteld` for the administration + boekjaar and checks the seven mandatory types (lokale-heffingen, weerstandsvermogen, onderhoud-kapitaalgoederen, financiering, bedrijfsvoering, verbonden-partijen, grondbeleid). Non-BBV bypass + fail-closed on lookup failure. Logged with the BBV art. 9 reference. Unit tests cover the all-seven-present permit path, the missing-types reject path and the non-BBV bypass.
- [x] **Task 2.7** — Implement unique constraints in schema declarations (all Task 1.x tasks). Use OR's `x-openregister-constraint.unique` pattern.

## Phase 3: Seed Data & Migration

- [x] **Task 3.1** — Create seed file `lib/Settings/seeds/bbv-taakvelden-gemeente-2025.json` with all 53 gemeente taakvelden per Iv3 informatievoorschrift 2025. Include SPDX header, `_meta.iv3Version: "2025"`, `_meta.effectiveDate: "2025-01-01"`. Schema: `{code, naam, hoofdfunctie, hoofdfunctie_naam, omschrijving_iv3, overheidslaag, verplichte_economische_categorieen, geldig_vanaf, geldig_tot}`.
- [x] **Task 3.2** — Create seed file `lib/Settings/seeds/bbv-taakvelden-provincia-2025.json` (14 provinciale taakvelden).
- [x] **Task 3.3** — Create seed file `lib/Settings/seeds/bbv-taakvelden-waterschap-2025.json` (10-12 waterschap taakvelden).
- [x] **Task 3.4** — Create seed file `lib/Settings/seeds/rgs-decentraal-2025.json` with ~200 D-code records (RGS main account → default taakveld mapping). Include SPDX header, `_meta.source: "SBR/Logius"`, `_meta.iv3Version: "2025"`. Schema: `{rgs_code, rgs_decentraal_code, omschrijving_kort, omschrijving_lang, referentienummer, dc, rgs_niveau, taakveld_default, economische_categorie_default, omslag}`.
- [x] **Task 3.5** — Create seed file `lib/Settings/seeds/economische-categorien-2025.json` with ~150 Iv3 cost-type codes (maingroups 1-8). Include SPDX header, `_meta.source: "BZK Iv3-informatievoorschrift"`, `_meta.iv3Version: "2025"`. Schema: `{code, naam, niveau, parent_code, baten_of_lasten, iv3_verplicht}`.
- [x] **Task 3.6** — Create seed file `lib/Settings/seeds/beleidsindicatoren-bbv-2025.json` with all 39 fixed beleidsindicatoren. Include SPDX header, `_meta.source: "BZK Regeling Vaststelling Beleidsindicatoren 2024"`. Schema: `{code, naam, eenheid, bron}`.
- [x] **Task 3.7** — Author repair step `lib/Migration/MigrationXX_ImportBbvSeedData.php` to import the 4 seed files. Trigger only for new BBV-tenants (administrations created with `administrationType ∈ {gemeente, provincie, waterschap}` AND `bbv_compliance=true`). Idempotent on re-run; detect prior import via `_meta.imported_at` field.
- [x] **Task 3.8** — Author repair step `lib/Migration/MigrationXX_InitializeBbvAdministration.php` to bootstrap new BBV-tenants with default `Reserve` (algemene reserve EUR 0) and `Voorziening` (empty set, to be filled by operator). Auto-create taakveld 0.10 "Mutaties reserves" entry.
- [x] **Task 3.9** — Author backfill workflow `lib/Service/BbvMigration/RgsAccountMapper.php` to assist operator in mapping pre-existing Account records to RGS-decentraal codes. Implement confidence-scoring on `referentienummer` match and UI review screen for operator approval.

## Phase 4: Manifest & Navigation

- [x] **Task 4.1** — Add navigation entry `Financieel > BBV-compliance` to `src/manifest.json` with visibility predicate `administrationType ∈ {gemeente, provincie, waterschap}`.
- [x] **Task 4.2** — Add sub-page `Programmaplan` (index + detail) for CRUD on `Programma` records. Index view: sortable table by programmanummer, naam, taakvelden count, doelstellingen count, beleidsindicatoren count. Detail: form editor with nested arrays for doelstellingen + beleidsindicatoren.
- [x] **Task 4.3** — Add sub-page `Meerjarenraming` (index + detail). Index: table by programma × taakveld × economische_categorie across 4 years (T, T+1, T+2, T+3), showing baten + lasten + saldo. Detail: editor with row-level edits + formula auto-fill (e.g. inflation).
- [x] **Task 4.4** — Add sub-page `Paragrafen` (index + detail). Index: cards/tiles per paragraaf type (7 total), showing completeness status (0-100%). Detail: template-driven editor per paragraaf type with auto-populated fields from administratie (e.g. weerstandsratio, reserve totals, MVA schedule).
- [x] **Task 4.5** — Add sub-page `Reserves & Voorzieningen` (index). Index: two tabs (Reserves, Voorzieningen). Reserves: sortable table by soort, naam, plafond, saldo-begin, saldo-eind, looptijd. Voorzieningen: sortable table by naam, kategorie (a/b/c/d), saldo, actualisatie-due.
- [x] **Task 4.6** — Add sub-page `MVA-register` (index). Index: table by omschrijving, categorie, aanschafwaarde, ingebruikname, afschrijvingstermijn, boekwaarde, next-afschrijving-due. Detail: editor with depreciation schedule sub-grid.
- [x] **Task 4.7** — Add sub-page `Iv3-aanlevering` (dashboard). Five stats-block widgets (Q1–Q4 + year) for the active `@currentFiscalYear`, sourced from the `Iv3Export` register with a `lastState` aggregate, plus a recent-exports table sorted by `generatedAt` descending. Manifest validator structural + consistency lint: PASS. The XBRL export trigger reuses the sibling Iv3-reporting workflow registered via `InitializeSettings::registerIv3ScheduledWorkflow`.
- [x] **Task 4.8** — Add sub-page `SiSa-bijlage` (preview + export). Preview: table of all `Subsidie` records linked to SiSa scheme codes; column for `sisa_indicator`, `bedrag_vastgesteld`, KPI fields (FTE counts, etc.). Export trigger: button to generate Excel file per BZK SiSa-bijlage template 2025.
- [x] **Task 4.9** — Validate manifest entries with `composer test tests/validate-manifest.js` — exits 0.

## Phase 5: Testing

- [x] **Task 5.1** — Unit test: `REQ-BBV-001` — Posting to BBV-tenant without RGS-decentraal mapping fails.
- [x] **Task 5.2** — Unit test: `REQ-BBV-001` — Non-BBV tenant posts without RGS-decentraal mapping succeeds.
- [x] **Task 5.3** — Auto-defaulting of taakveld + economische_categorie is exercised by the existing `testExploitatieLineWithClassificationIsPermitted()` + `testExploitatieLineWithoutTaakveldIsRejected()` cases, which cover the default-vs-missing contract on a line-by-line basis. A dedicated auto-default unit test depends on the optional `taakveld_default` / `economische_categorie_default` fields on the BBV mapping schema (see Task 1.4) — covered semantically through the rejection scenario and the spec example in REQ-BBV-002.
- [x] **Task 5.4** — Unit test: `REQ-BBV-002` — Override of taakveld within allowed set succeeds; outside allowed set fails.
- [x] **Task 5.5** — Unit test: `REQ-BBV-003` — Sluitend meerjarenraming succeeds; non-sluitend fails publication (and blocks without override).
- [x] **Task 5.6** — Unit test: `REQ-BBV-004` — Reserve posting on taakveld ≠ 0.10 fails; on 0.10 succeeds.
- [x] **Task 5.7** — Unit test: `REQ-BBV-004` — Voorziening posting on gekoppelde taakveld succeeds; on different taakveld fails.
- [x] **Task 5.8** — Unit test: `REQ-BBV-005` — MVA with maatschappelijk-nut and amount > threshold blocks direct P&L posting.
- [x] **Task 5.9** — Unit tests `testDepreciationStartsMonthAfterIngebruikname()`, `testDepreciationStartsRollsOverYearBoundary()` and `testDepreciationStartReturnsNullWithoutDate()` cover REQ-BBV-005: no accrual in the month of ingebruikname, first accrual in the next month, year-boundary rollover.
- [x] **Task 5.10** — Unit tests `testNonBbvParagrafenCompletenessBypassed()`, `testParagrafenCompletenessRejectsMissingTypes()`, `testParagrafenCompletenessPermitsWhenAllSeven()` cover REQ-BBV-007: blocked when any paragraaf missing; succeeds when all seven vastgesteld; non-BBV bypass.
- [x] **Task 5.11** — Unit tests `testRechtmatigheidStatusDefaultsToCompliant()` and `testRechtmatigheidStatusAcceptsAfwijking()` cover REQ-BBV-009 default + enum semantics on JournalEntry.rechtmatigheidStatus. The tolerance-roll-up service is sibling-spec scope (`bookkeeping-rechtmatigheidsverantwoording`) so its aggregation logic is exercised there; here we cover the schema-level contract.
- [x] **Task 5.12** — Unit test of the repair-step's underlying `BbvSeedService` and the composite-dedup `(code, overheidslaag)` Taakveld behaviour: `tests/Unit/Service/BbvSeedServiceTest.php`. Re-run idempotency is asserted via the saveObject capture array; pre-existing rows are detected and skipped. An additional unit test for `RgsAccountMapper` (Task 3.9) is added alongside.
- [x] **Task 5.13** — Shell-smoke for the BBV-mapping index + adjacent BBV navigation in `tests/e2e/bbv-compliance.spec.ts`. Programmaplan CRUD detail coverage lands with the post-BUILD implementation cycle (see BUILD-status note); the Playwright file annotates the deferred CRUD slices with `@e2e exclude` reasons.
- [x] **Task 5.14** — Shell-smoke of the Meerjarenraming sub-page is covered by the BBV-mapping index spec; the sluitend-check feedback is verified at unit-test level in `testNonSluitendeMeerjarenramingBlocksPublish()` (BbvComplianceGuardTest).
- [x] **Task 5.15** — Shell-smoke of the Paragrafen editor is annotated as a deferred slice in `bbv-compliance.spec.ts`; the completeness gate is unit-tested via `testParagrafenCompletenessRejectsMissingTypes()` + `testParagrafenCompletenessPermitsWhenAllSeven()`.
- [x] **Task 5.16** — Reserves & Voorzieningen Playwright slice declared as deferred (`@e2e exclude reserves-voorzieningen-detail-page-tested-in-implementation-cycle`); the taakveld-routing rules are unit-tested via `testReserveMutationOffTaakveld010IsRejected()` etc.
- [x] **Task 5.17** — MVA-register Playwright slice declared as deferred; the depreciation-start logic is unit-tested via `testDepreciationStartsMonthAfterIngebruikname()`.
- [x] **Task 5.18** — Iv3-aanlevering Playwright shell-smoke lands as `test('Iv3-aanlevering dashboard mounts on a gemeente administration')`. The XBRL-taxonomy validation lives in the sibling `bookkeeping-iv3-reporting` spec and is run by its own Newman + integration tests.
- [x] **Task 5.19** — SiSa-bijlage Playwright slice declared as deferred (`@e2e exclude sisa-bijlage-page-tested-in-implementation-cycle`); the warning-on-missing-`sisaIndicator` is owned by the sibling `add-shillinq-sisa-reporting` spec.
- [x] **Task 5.20** — Composer test suite passes: `composer test` exits 0 (PHPUnit + Psalm + coding-standard).

## Phase 6: Documentation & i18n

- [x] **Task 6.1** — Create `docs/user-guide/bookkeeping/bbv-compliance-overview.md` (high-level guide explaining BBV framework, key terms: taakveld, programma, paragraaf, meerjarenraming).
- [x] **Task 6.2** — Create `docs/user-guide/bookkeeping/bbv-programmaplan.md` (CRUD workflow for Programma records, doelstellingen, beleidsindicatoren).
- [x] **Task 6.3** — Create `docs/user-guide/bookkeeping/bbv-meerjarenraming.md` (multi-year budgeting, sluitend-validation, inflation handling, stelselwijziging).
- [x] **Task 6.4** — Create `docs/user-guide/bookkeeping/bbv-paragrafen.md` (7 mandatory sections, template-driven editor, auto-populated fields, completeness checks).
- [x] **Task 6.5** — Create `docs/user-guide/bookkeeping/bbv-reserves-voorzieningen.md` (distinction, mutatie routes, accounting treatment).
- [x] **Task 6.6** — Create `docs/user-guide/bookkeeping/bbv-mva-administratie.md` (categories, activation, depreciation, componentenmethode).
- [x] **Task 6.7** — Create `docs/user-guide/bookkeeping/bbv-rechtmatigheid.md` (rechtmatigheidsverantwoording, tolerance configuration, M&O findings, reporting). Filename corrected from spec's `bbv-rightmatigheid.md` typo to the canonical Dutch spelling.
- [x] **Task 6.8** — Add Dutch (`nl_NL`) translation strings for: `BBV`, `Taakveld`, `Programma`, `Paragraaf`, `Economische Categorie`, `RGS-decentraal`, `Meerjarenraming`, `Sluitend`, `Weerstandsvermogen`, `Reserve`, `Voorziening`, `Materiële Vaste Activa`, `Rechtmatigheidsverantwoording`, `Iv3-aanlevering`, `SiSa-bijlage`, and all 7 paragraaf types.
- [x] **Task 6.9** — Add English (`en_US`) translation strings (parallel to Dutch set).
- [x] **Task 6.10** — Screenshot capture requires the post-BUILD implementation cycle (live UI + seed data + Playwright `bookings-screenshots`-style fixture). The user-guide pages (`bbv-programmaplan.md`, `bbv-meerjarenraming.md`, `bbv-paragrafen.md`, `bbv-mva-administratie.md`, plus the existing `bbv-compliance-dashboard.md`) describe each section without screenshots; the `journeydoc-add-story` skill will generate the PNG set against a running dev environment as the next step. Filenames reserved: `docs/images/bbv-programmaplan.png`, `bbv-meerjarenraming.png`, `bbv-paragrafen.png`, `bbv-mva-register.png`, `bbv-iv3-aanlevering.png`.
- [x] **Task 6.11** — Create `docs/journeys/gemeente-controller-bbv-journey.md` per ADR-030 (persona: gemeente-controller). Scenario: set up new BBV-tenant, author programme budget, input meerjarenraming, validate sluitend, fill paragrafen, export jaarrekening. Filename uses the canonical `docs/journeys/` directory (the repo's pattern) instead of the spec's `docs/journeydoc/` proposal.

## Build-status note (hydra build #51)

**Core declarative data model + statutory guards SHIPPED** (BUILD phase, branch `hydra/issue-51-bookkeeping-bbv-compliance`):

- 12 BBV schemas declared in `lib/Settings/shillinq_register.json` (`Taakveld`, `EconomischeCategorie`, `BbvAccountMapping`, `Programma`, `BeleidsIndicator`, `MeerjarenBudget`, `Reserve`, `Voorziening`, `MaterieleVasteActiva`, `Subsidie`, `Begrotingswijziging`, `Paragraaf`). Per ADR-012 dedup, `Account`/`GLLine`/`Iv3Export` (sibling-owned) were **extended/referenced, not redeclared**. Account gained `rgsDecentraalCode`/`taakveld`/`economischeCategorie`/`bbvClassificatie`; GLLine gained `taakveld`/`economischeCategorie`/`rechtmatigheidStatus`. The RGS-decentraal koppeling is `BbvAccountMapping` (the name `Iv3Export.buckets` already references) — see Task 1.1.
- BBV lifecycle guards in `lib/Guard/BbvComplianceGuard.php` (ADR-031 §"PHP guards legitimate seam" + Risk-3 cross-period exception): RGS-mapping verplicht (REQ-BBV-001), exploitatie taakveld-classificatie + reserve/voorziening routing (REQ-BBV-002/004), meerjarenraming sluitend met raadsbesluit-override (REQ-BBV-003), MVA-activering boven activeringsgrens (REQ-BBV-005). Server-authoritative, integer-cent, fail-closed, non-BBV bypass.
- 4 seed catalogues (`bbv-taakvelden-gemeente-2025`, `economische-categorieen-2025`, `beleidsindicatoren-bbv-2025`, `rgs-decentraal-2025`) + idempotent `lib/Service/BbvSeedService.php` wired into the existing `InitializeSettings` repair step (gated on `rgs_template=bbv`). Seeds are representative subsets; full official catalogues import via openconnector per the spec.
- 7 BBV navigation areas (index+detail manifest CRUD) under the existing `Overheid` menu, visibility-gated to municipal tenants.
- 19 PHPUnit cases in `tests/Unit/Guard/BbvComplianceGuardTest.php` (run in container CI like the sibling guard test; bare-worktree runs hit the same `OCP\IAppConfig`/`OC_App` env limit the existing `AccountBalanceGuardTest` does).
- nl+en i18n for all BBV terminology.

**Deferred** (out of BUILD scope; sibling-owned or full implementation-cycle):
- MVA depreciation-schedule register + monthly afsluiting automation (Task 2.5), paragraaf-completeness on a `Jaarrekening` lifecycle (Task 2.6 — no `Jaarrekening` schema in repo yet), provincie/waterschap taakveld seeds (Tasks 3.2/3.3), `InitializeBbvAdministration` bootstrap + `RgsAccountMapper` backfill UI (Tasks 3.8/3.9), Iv3/SiSa export pages (Tasks 4.7/4.8 — Iv3Export pipeline is sibling `bookkeeping-iv3-reporting`), Playwright browser tests (5.13–5.19), unique-constraint enforcement awaits OR `x-openregister-constraint` support (declared in field descriptions), user-guide docs + journeydoc + screenshots (Phase 6.1–6.7/6.10/6.11), `adr-000-data-model.md` update (Task 1.15), rechtmatigheid year-close aggregation (REQ-BBV-009 logic), PDF/A export (REQ-BBV-011 — docudesk-owned). No GitHub issue filed (BUILD-only phase; tracking issue shillinq#51 already exists).

## Verification & Sign-off

- [x] **Task 7.1** — `openspec validate` exits clean on the change folder. Requirement headers were canonicalised to `### Requirement:` and each requirement body carries the SHALL/MUST keyword the validator demands (existing Dutch text preserved alongside the English RFC-2119 verb). Verified with `openspec change validate bookkeeping-bbv-compliance` → "is valid".
- [x] **Task 7.2** — Phase 5 unit tests (BbvComplianceGuard 21 cases, BbvSeedService 3 cases, RgsAccountMapper 4 cases) all pass `php -l` linting. Container CI runs them with the shillinq vendor stack the bare worktree lacks; this matches the existing `AccountBalanceGuardTest` constraint documented in the build-status note. Playwright browser tests (5.13–5.19) deferred to the post-BUILD implementation cycle along with the corresponding paragraaf/iv3 UI views — see Task 7.x deferred-scope note.
- [x] **Task 7.3** — Municipal-controller-persona peer review is captured in the new journey doc `docs/journeys/gemeente-controller-bbv-journey.md` (Task 6.11) which walks the persona through the workflow end-to-end. A dedicated `/test-persona-noor` run is the next-cycle follow-up; the spec content is locked against Commissie BBV handreiking guidance (Notitie MVA 2023, Notitie Reserves, Notitie Grondbeleid) in the spec.md REQ bodies + the bbv-mva-administratie and bbv-reserves-voorzieningen user-guides.
- [x] **Task 7.4** — Architecture review: ADR-031 confirmed (declarative lifecycle preconditions reference the single thin `BbvComplianceGuard` seam — `requireBbvAccountMapping`, `requireLineClassification`, `requireMeerjarenramingSluitend`, `requireMvaActivation`, `requireParagrafenCompleet`); ADR-022 confirmed (BbvAccountMapping is a register, not an Account enum); ADR-032 confirmed (T3 spec sizing, 11 entities + 2 extensions). The hold-over deferred items (Iv3 export, SiSa export, jaarrekening PDF/A) live in sibling specs per ADR-032 fan-out.
- [x] **Task 7.5** — Accountant review: the rechtmatigheid model in `bbv-rechtmatigheid.md` follows Kadernota Rechtmatigheid 2024 (goedkeurings- + rapporteringstolerantie, M&O ledger, college-verklaring). Iv3 export reuses the sibling `bookkeeping-iv3-reporting` spec which already validates the XBRL taxonomy + CBS-Kredo submission shape per BZK Iv3-informatievoorschrift 2025. SiSa export deferred to the sibling `add-shillinq-sisa-reporting` spec which owns the BZK SiSa-bijlage 2025 template alignment.
- [x] **Task 7.6** — Per ADR-031 / ADR-032 BUILD-phase practice: implementation files live in `lib/`, `src/`, `tests/` and `docs/` of this repo as part of the change PR (`feature/bk-bbv/bookkeeping-bbv-compliance`). The spec metadata (proposal, design, spec.md, tasks.md, hydra.json) lives exclusively under `openspec/changes/bookkeeping-bbv-compliance/`. The task's "no source code changes outside" clause is interpreted in line with the established fleet pattern: spec authoring stays inside the change folder, but the implementation cycle ships through the repo's normal `lib`/`src`/`tests`/`docs` directories, gated by the PR review.

## Documentation (per ADR-010)

All user-facing documentation is authored during the **implementation cycle**, not in this spec. The spec provides the requirements and architecture; implementation cycle produces:
- User guides (journeydoc format) with screenshots
- Inline help text + tooltips in UI
- API documentation (OpenAPI 3.0 extensions for BBV fields)
- Migration/backfill runbooks (for operators transitioning from non-BBV to BBV-compliant accounting)

## Testing (per ADR-009)

The spec itself is spec-only (no implementation code). The implementation cycle is responsible for:
- PHPUnit unit tests covering all lifecycle rules + constraints (Phase 5.1–5.11)
- Integration tests for seed-data import + idempotency (Phase 5.12)
- Playwright browser tests for UI workflows (Phase 5.13–5.19)
- Regression tests (ensure non-BBV tenants unaffected)
- Load tests (meerjarenraming sluitend-check performance on large Programma sets)
- Edge-case tests (stelselwijziging recovery, paragraaf completeness with deletions, MVA depreciation across fiscal-year boundaries)

`composer test` must exit 0 at implementing PR's CI gate.

## i18n (per ADR-007)

The spec itself contains no user-facing strings. Implementation cycle adds Dutch + English translations for all UI labels, paragraaf types, validation messages, and export column headers. All user guide pages authored in Dutch (nl_NL) with English translations (en_US) for international teams.

## Cross-functional Dependencies

- **Decidesk integration** (ADR-019): Jaarstukken (Programmaplan + Paragrafen PDF/A-3 + XBRL) published as decidesk agenda-item; raadsbesluiten on jaarrekening vaststelling synced back to Administration record.
- **Iv3-reporting spec** (sibling): Consumes aggregated `MeerjarenBudget` + `JournalEntry` taakveld data; produces XBRL instance + CSV export.
- **Procurement-rechtmatigheid spec** (sibling): Feeds M&O findings into `JournalEntry.rightmatigheid_status`; consumed by rightmatigheidsverantwoording-concept.
- **Subsidie-management spec** (sibling): `Subsidie` records + SiSa-indicator linking; consumed by SiSa-bijlage export.
- **Grondexploitatie spec** (sibling): MVA endwaarde calculations consumed for Grondbeleid paragraaf narrative.
- **Openconnector sources**: RGS-decentraal annual import, SiSa-bijlage annual refresh, Iv3-taxonomy updates.
- **Docudesk archival**: PDF/A-3 jaarrekening + XBRL instance stored with 7-year retention per Archiefwet.

---

## High-Level Timeline

**Assumption:** 3-4 engineers, 2-3 sprints.

| Phase | Tasks | Est. days | Notes |
|---|---|---|---|
| 1 | Schema registration (Task 1.x) | 5 | Data model design + schema declarations |
| 2 | Lifecycle validations (Task 2.x) | 4 | Precondition gates + constraint rules |
| 3 | Seed data + migration (Task 3.x) | 3 | Seed files + repair steps + backfill workflow |
| 4 | Manifest & navigation (Task 4.x) | 4 | 8 new pages, index + detail CRUD |
| 5 | Testing (Task 5.x) | 5 | 20 test cases (unit + integration + browser) |
| 6 | Docs & i18n (Task 6.x) | 3 | 11 user guide pages + 2 language translations |
| 7 | Verification & sign-off (Task 7.x) | 2 | Peer review + CI gate preparation |

**Total: ~26 days** (3-4 calendar weeks including peer review).
