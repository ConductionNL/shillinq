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

- [ ] **Task 1.1** — Confirm no existing schemas named `Programma`, `Taakveld`, `EconomischeCategorie`, `RgsDecentraalRekening`, `MeerjarenBudget`, `Reserve`, `Voorziening`, `MaterieleVasteActiva`, `Subsidie`, `Begrotingswijziging`, `BeleidsIndicator` exist (scan `lib/Settings/shillinq_register.json`, `openspec/architecture/adr-000-data-model.md`, prior specs)
- [ ] **Task 1.2** — Declare `Taakveld` schema with fields: `code` (string, PK), `naam` (string), `hoofdfunctie` (int 0-8), `hoofdfunctie_naam` (string), `omschrijving_iv3` (text), `overheidslaag` (enum: gemeente|provincie|waterschap), `verplichte_economische_categorieen` (array[ref[EconomischeCategorie]]), `geldig_vanaf` (date), `geldig_tot` (date, nullable). Schema.org: `schema:DefinedTerm`. Register: `bookkeeping-bbv`. Primary spec: `bookkeeping-bbv-compliance`.
- [ ] **Task 1.3** — Declare `EconomischeCategorie` schema with fields: `code` (string, PK), `naam` (string), `niveau` (int 1-3), `parent_code` (string, nullable), `baten_of_lasten` (enum: baten|lasten|balans), `iv3_verplicht` (boolean). Schema.org: `schema:DefinedTerm`. Register: `bookkeeping-bbv`.
- [ ] **Task 1.4** — Declare `RgsDecentraalRekening` schema with fields: `rgs_code` (string), `rgs_decentraal_code` (string, PK), `omschrijving_kort` (string), `omschrijving_lang` (text), `referentienummer` (string), `dc` (enum: D|C), `rgs_niveau` (int 1-5), `taakveld_default` (ref[Taakveld], nullable), `economische_categorie_default` (ref[EconomischeCategorie]), `omslag` (enum: verplicht|toegestaan|niet-toegestaan, nullable). Register: `bookkeeping-bbv-reference` (shared, not per-tenant). Primary spec: `bookkeeping-bbv-compliance`.
- [ ] **Task 1.5** — Extend T1 `Account` schema with 4 new required fields: `rgs_decentraal_rekening` (ref[RgsDecentraalRekening], required for BBV-tenants), `taakveld` (ref[Taakveld], required for exploitatie accounts in BBV-tenants), `economische_categorie` (ref[EconomischeCategorie], required), `bbv_classificatie` (enum: exploitatie|investering|reserve|voorziening|balans-overig, required for BBV-tenants). Mark as optional/nullable for non-BBV tenants via constraint.
- [ ] **Task 1.6** — Declare `Programma` schema with fields: `nummer` (int 0-99, PK per admin), `naam` (string), `omschrijving` (text), `portefeuillehouder` (string), `taakvelden` (array[ref[Taakveld]], required), `doelstellingen` (array[object{wat, wanneer, kpi}]), `beleidsindicatoren` (array[ref[BeleidsIndicator]]), `boekjaar` (int), `versie` (enum: begroting|jaarrekening|burap-1|burap-2|marap|tussenrapportage). Register: `bookkeeping-bbv`. Unique constraint: `(administrationId, nummer, boekjaar)`.
- [ ] **Task 1.7** — Declare `BeleidsIndicator` schema with fields: `code` (string, PK), `naam` (string), `eenheid` (string), `bron` (string), `waarde` (number, nullable), `programma` (ref[Programma]). Register: `bookkeeping-bbv`. Unique: `(administrationId, code, programma)`.
- [ ] **Task 1.8** — Declare `MeerjarenBudget` schema with fields: `programma` (ref[Programma]), `taakveld` (ref[Taakveld]), `economische_categorie` (ref[EconomischeCategorie]), `boekjaar` (int), `bedrag_baten` (decimal 15,2), `bedrag_lasten` (decimal 15,2), `versie` (enum: primitief|na-wijziging|realisatie), `begrotingswijziging` (ref[Begrotingswijziging], nullable), `meerjaren_horizon` (int 0-3), `toelichting` (text, nullable), `stelselwijziging` (boolean, default false). Register: `bookkeeping-bbv`. Unique: `(administrationId, programma, taakveld, economische_categorie, boekjaar, meerjaren_horizon, versie)`.
- [ ] **Task 1.9** — Declare `Reserve` schema with fields: `naam` (string), `soort` (enum: algemeen|bestemming), `doel` (text, required if bestemming), `raadsbesluit_instelling` (string), `plafond` (decimal 15,2, nullable), `bodem` (decimal 15,2, nullable), `looptijd_einde` (date, nullable), `programma` (ref[Programma], nullable), `rentetoerekening` (boolean, default false), `saldo_begin_jaar` (decimal 15,2), `saldo_eind_jaar` (decimal 15,2, nullable). Register: `bookkeeping-bbv`. Unique: `(administrationId, naam)`.
- [ ] **Task 1.10** — Declare `Voorziening` schema with fields: `naam` (string), `bbv_artikel_44_categorie` (enum: a|b|c|d), `onderbouwing_document` (ref[Document]), `actualisatie_frequentie_jaar` (int), `volgende_actualisatie` (date), `taakveld` (ref[Taakveld]), `saldo_begin_jaar` (decimal 15,2), `dotaties_jaar` (decimal 15,2), `vrijvallen_jaar` (decimal 15,2), `saldo_eind_jaar` (decimal 15,2, nullable). Register: `bookkeeping-bbv`. Unique: `(administrationId, naam)`.
- [ ] **Task 1.11** — Declare `MaterieleVasteActiva` schema with fields: `omschrijving` (string), `mva_categorie` (enum: economisch-nut|economisch-nut-heffing|maatschappelijk-nut), `aanschafwaarde` (decimal 15,2), `ingebruikname_datum` (date), `afschrijvingsmethode` (enum: lineair|annuitair), `afschrijvingstermijn_jaar` (int), `restwaarde` (decimal 15,2), `rente_omslag_percentage` (decimal 5,3), `taakveld` (ref[Taakveld]), `kredietbesluit` (string), `componenten_methode` (boolean, default false), `subsidie_van_derden` (decimal 15,2, nullable), `boekwaarde_begin_jaar` (decimal 15,2), `afschrijving_jaar` (decimal 15,2, nullable). Register: `bookkeeping-bbv`. Unique: `(administrationId, omschrijving, ingebruikname_datum)`.
- [ ] **Task 1.12** — Declare `Subsidie` schema with fields: `subsidie_soort` (enum: verstrekt-incidenteel|verstrekt-structureel|ontvangen-rijk|ontvangen-provincie|ontvangen-eu), `regeling_naam` (string), `sisa_indicator` (string, nullable, e.g. "H8"), `verstrekker_of_ontvanger` (string), `beschikking_nummer` (string), `bedrag_verleend` (decimal 15,2), `bedrag_vastgesteld` (decimal 15,2, nullable), `bedrag_gerealiseerd` (decimal 15,2, nullable), `taakveld` (ref[Taakveld]), `economische_categorie` (ref[EconomischeCategorie]), `verleningsdatum` (date), `beëindigingsdatum` (date, nullable). Register: `bookkeeping-bbv`. Unique: `(administrationId, beschikking_nummer)`.
- [ ] **Task 1.13** — Declare `Begrotingswijziging` schema with fields: `nummer` (string, PK per admin), `programma` (ref[Programma]), `taakveld` (ref[Taakveld]), `economische_categorie` (ref[EconomischeCategorie]), `bedrag_oorspronkelijk` (decimal 15,2), `bedrag_wijziging` (decimal 15,2), `bedrag_nieuw` (decimal 15,2), `reden` (text), `raadsbesluit_nummer` (string), `raadsbesluit_datum` (date), `status` (enum: concept|vastgesteld|verwerkt), `effectievedatum` (date). Register: `bookkeeping-bbv`. Unique: `(administrationId, nummer)`.
- [ ] **Task 1.14** — Extend T1 `JournalEntry` schema to add optional field `rechtmatigheid_status` (enum: compliant|afwijking_within_tolerance|afwijking_outside_tolerance), per REQ-BBV-009. Default: `compliant`.
- [ ] **Task 1.15** — Update `openspec/architecture/adr-000-data-model.md` with the 11 new entities + 2 schema extensions. Note `Primary spec: bookkeeping-bbv-compliance` for each.

## Phase 2: Lifecycle Validations & Constraints

- [ ] **Task 2.1** — Extend T1 `JournalEntry.post` lifecycle with a BBV-mapping precondition (per REQ-BBV-002 & REQ-BBV-003). Precondition fires only for administrations with `administrationType ∈ {gemeente, provincie, waterschap}`. For each line item: if account's `bbv_classificatie=exploitatie`, verify `taakveld` and `economische_categorie` are both set and match allowed set for that taakveld. If not, fail with `ValidationError("REQ-BBV-002: taakveld verplicht…")`. Skip for historic postings (`postingDate < installation_date`).
- [ ] **Task 2.2** — Implement `Programma.publish` lifecycle constraint to enforce meerjarenraming sluitend-checks (REQ-BBV-003). Query all `MeerjarenBudget` rows for this programma across T, T+1, T+2, T+3. For each year, compute `saldo = sum(baten) - sum(lasten) + sum(reserve_mutaties)`. If saldo < 0 for any year, fail publication with `BBVConstraintError("REQ-BBV-003: jaar 2028 niet sluitend…")`. Allow override with `raadsbesluit_nummer` + `raadsbesluit_datum` fields.
- [ ] **Task 2.3** — Implement account-posting validation for reserves vs. voorzieningen (REQ-BBV-004). When posting to an account tagged `bbv_classificatie=reserve`, enforce `taakveld=0.10` (Mutaties reserves). When posting to an account tagged `bbv_classificatie=voorziening` with `taakveld_gekoppeld=2.1`, enforce all postings on this account carry `taakveld=2.1` (the gekoppelde taakveld). Fail with `BBVConstraintError` if violated.
- [ ] **Task 2.4** — Implement MVA activation constraint (REQ-BBV-005). When creating `MaterieleVasteActiva` with `mva_categorie=maatschappelijk-nut` and `aanschafwaarde > activeringsgrens` (configurable, typically EUR 50k), verify that no direct P&L posting is made. If user attempts to book directly to exploitatie, fail with `BBVConstraintError("REQ-BBV-005: investering > activeringsgrens…must be activated")`.
- [ ] **Task 2.5** — Implement MVA depreciation start logic (REQ-BBV-005). When `MaterieleVasteActiva.ingebruikname_datum` is set to, say, 2026-09-15, no depreciation accrues in September 2026. Starting October 2026, monthly afsluiting computes depreciation as: `(aanschafwaarde - restwaarde) / afschrijvingstermijn_jaar / 12`. Store computed depreciation in a `depreciation_schedule` register for audit trail and amortization posting automation.
- [ ] **Task 2.6** — Implement paragraaf completeness constraint (REQ-BBV-007). When transitioning `Jaarrekening.status: draft → vastgesteld`, verify all 7 paragrafen are present (one per type in `Paragraaf` register for this `administrationId` and `boekjaar`). If any missing, fail with error listing missing paragrafen and BBV-art. 9 reference.
- [ ] **Task 2.7** — Implement unique constraints in schema declarations (all Task 1.x tasks). Use OR's `x-openregister-constraint.unique` pattern.

## Phase 3: Seed Data & Migration

- [ ] **Task 3.1** — Create seed file `lib/Settings/seeds/bbv-taakvelden-gemeente-2025.json` with all 53 gemeente taakvelden per Iv3 informatievoorschrift 2025. Include SPDX header, `_meta.iv3Version: "2025"`, `_meta.effectiveDate: "2025-01-01"`. Schema: `{code, naam, hoofdfunctie, hoofdfunctie_naam, omschrijving_iv3, overheidslaag, verplichte_economische_categorieen, geldig_vanaf, geldig_tot}`.
- [ ] **Task 3.2** — Create seed file `lib/Settings/seeds/bbv-taakvelden-provincia-2025.json` (14 provinciale taakvelden).
- [ ] **Task 3.3** — Create seed file `lib/Settings/seeds/bbv-taakvelden-waterschap-2025.json` (10-12 waterschap taakvelden).
- [ ] **Task 3.4** — Create seed file `lib/Settings/seeds/rgs-decentraal-2025.json` with ~200 D-code records (RGS main account → default taakveld mapping). Include SPDX header, `_meta.source: "SBR/Logius"`, `_meta.iv3Version: "2025"`. Schema: `{rgs_code, rgs_decentraal_code, omschrijving_kort, omschrijving_lang, referentienummer, dc, rgs_niveau, taakveld_default, economische_categorie_default, omslag}`.
- [ ] **Task 3.5** — Create seed file `lib/Settings/seeds/economische-categorien-2025.json` with ~150 Iv3 cost-type codes (maingroups 1-8). Include SPDX header, `_meta.source: "BZK Iv3-informatievoorschrift"`, `_meta.iv3Version: "2025"`. Schema: `{code, naam, niveau, parent_code, baten_of_lasten, iv3_verplicht}`.
- [ ] **Task 3.6** — Create seed file `lib/Settings/seeds/beleidsindicatoren-bbv-2025.json` with all 39 fixed beleidsindicatoren. Include SPDX header, `_meta.source: "BZK Regeling Vaststelling Beleidsindicatoren 2024"`. Schema: `{code, naam, eenheid, bron}`.
- [ ] **Task 3.7** — Author repair step `lib/Migration/MigrationXX_ImportBbvSeedData.php` to import the 4 seed files. Trigger only for new BBV-tenants (administrations created with `administrationType ∈ {gemeente, provincie, waterschap}` AND `bbv_compliance=true`). Idempotent on re-run; detect prior import via `_meta.imported_at` field.
- [ ] **Task 3.8** — Author repair step `lib/Migration/MigrationXX_InitializeBbvAdministration.php` to bootstrap new BBV-tenants with default `Reserve` (algemene reserve EUR 0) and `Voorziening` (empty set, to be filled by operator). Auto-create taakveld 0.10 "Mutaties reserves" entry.
- [ ] **Task 3.9** — Author backfill workflow `lib/Service/BbvMigration/RgsAccountMapper.php` to assist operator in mapping pre-existing Account records to RGS-decentraal codes. Implement confidence-scoring on `referentienummer` match and UI review screen for operator approval.

## Phase 4: Manifest & Navigation

- [ ] **Task 4.1** — Add navigation entry `Financieel > BBV-compliance` to `src/manifest.json` with visibility predicate `administrationType ∈ {gemeente, provincie, waterschap}`.
- [ ] **Task 4.2** — Add sub-page `Programmaplan` (index + detail) for CRUD on `Programma` records. Index view: sortable table by programmanummer, naam, taakvelden count, doelstellingen count, beleidsindicatoren count. Detail: form editor with nested arrays for doelstellingen + beleidsindicatoren.
- [ ] **Task 4.3** — Add sub-page `Meerjarenraming` (index + detail). Index: table by programma × taakveld × economische_categorie across 4 years (T, T+1, T+2, T+3), showing baten + lasten + saldo. Detail: editor with row-level edits + formula auto-fill (e.g. inflation).
- [ ] **Task 4.4** — Add sub-page `Paragrafen` (index + detail). Index: cards/tiles per paragraaf type (7 total), showing completeness status (0-100%). Detail: template-driven editor per paragraaf type with auto-populated fields from administratie (e.g. weerstandsratio, reserve totals, MVA schedule).
- [ ] **Task 4.5** — Add sub-page `Reserves & Voorzieningen` (index). Index: two tabs (Reserves, Voorzieningen). Reserves: sortable table by soort, naam, plafond, saldo-begin, saldo-eind, looptijd. Voorzieningen: sortable table by naam, kategorie (a/b/c/d), saldo, actualisatie-due.
- [ ] **Task 4.6** — Add sub-page `MVA-register` (index). Index: table by omschrijving, categorie, aanschafwaarde, ingebruikname, afschrijvingstermijn, boekwaarde, next-afschrijving-due. Detail: editor with depreciation schedule sub-grid.
- [ ] **Task 4.7** — Add sub-page `Iv3-aanlevering` (dashboard + export trigger). Dashboard: status of Q1-Q4 + year exports for current boekjaar; scheduled deadlines (1-month post-quarter, 15-July for year); last-successful export date + file hash. Export trigger: button to generate XBRL instance, validate, review, download, or submit to Kredo.
- [ ] **Task 4.8** — Add sub-page `SiSa-bijlage` (preview + export). Preview: table of all `Subsidie` records linked to SiSa scheme codes; column for `sisa_indicator`, `bedrag_vastgesteld`, KPI fields (FTE counts, etc.). Export trigger: button to generate Excel file per BZK SiSa-bijlage template 2025.
- [ ] **Task 4.9** — Validate manifest entries with `composer test tests/validate-manifest.js` — exits 0.

## Phase 5: Testing

- [ ] **Task 5.1** — Unit test: `REQ-BBV-001` — Posting to BBV-tenant without RGS-decentraal mapping fails.
- [ ] **Task 5.2** — Unit test: `REQ-BBV-001` — Non-BBV tenant posts without RGS-decentraal mapping succeeds.
- [ ] **Task 5.3** — Unit test: `REQ-BBV-002` — Auto-defaulting of taakveld + economische_categorie from account defaults.
- [ ] **Task 5.4** — Unit test: `REQ-BBV-002` — Override of taakveld within allowed set succeeds; outside allowed set fails.
- [ ] **Task 5.5** — Unit test: `REQ-BBV-003` — Sluitend meerjarenraming succeeds; non-sluitend fails publication (and blocks without override).
- [ ] **Task 5.6** — Unit test: `REQ-BBV-004` — Reserve posting on taakveld ≠ 0.10 fails; on 0.10 succeeds.
- [ ] **Task 5.7** — Unit test: `REQ-BBV-004` — Voorziening posting on gekoppelde taakveld succeeds; on different taakveld fails.
- [ ] **Task 5.8** — Unit test: `REQ-BBV-005` — MVA with maatschappelijk-nut and amount > threshold blocks direct P&L posting.
- [ ] **Task 5.9** — Unit test: `REQ-BBV-005` — Depreciation starts in month following ingebruikname; no accrual in month of ingebruikname.
- [ ] **Task 5.10** — Unit test: `REQ-BBV-007` — Jaarrekening publication blocked if any paragraaf missing; succeeds when all present.
- [ ] **Task 5.11** — Unit test: `REQ-BBV-009` — Begrotingsoverschrijding logged as rechtmatigheid-afwijking; within tolerance range does not impact verantwoording.
- [ ] **Task 5.12** — Integration test: Repair step imports all 4 seed files idempotently; re-run detects prior import and skips.
- [ ] **Task 5.13** — Browser test (Playwright): Programmaplan CRUD works; can create, edit, delete `Programma` records.
- [ ] **Task 5.14** — Browser test: Meerjarenraming index + detail pages load and render without error; sluitend-check feedback shows.
- [ ] **Task 5.15** — Browser test: Paragrafen editor loads; auto-populated fields (weerstandsratio, etc.) compute correctly.
- [ ] **Task 5.16** — Browser test: Reserves & Voorzieningen index pages filter by type; detail pages allow CRUD.
- [ ] **Task 5.17** — Browser test: MVA-register index loads; depreciation schedule sub-grid renders.
- [ ] **Task 5.18** — Browser test: Iv3-aanlevering dashboard shows status; export trigger generates valid XBRL and passes taxonomy validation.
- [ ] **Task 5.19** — Browser test: SiSa-bijlage preview shows subsidies linked to SiSa codes; missing sisa_indicator triggers warning.
- [ ] **Task 5.20** — Composer test suite passes: `composer test` exits 0 (PHPUnit + Psalm + coding-standard).

## Phase 6: Documentation & i18n

- [ ] **Task 6.1** — Create `docs/user-guide/bookkeeping/bbv-compliance-overview.md` (high-level guide explaining BBV framework, key terms: taakveld, programma, paragraaf, meerjarenraming).
- [ ] **Task 6.2** — Create `docs/user-guide/bookkeeping/bbv-programmaplan.md` (CRUD workflow for Programma records, doelstellingen, beleidsindicatoren).
- [ ] **Task 6.3** — Create `docs/user-guide/bookkeeping/bbv-meerjarenraming.md` (multi-year budgeting, sluitend-validation, inflation handling, stelselwijziging).
- [ ] **Task 6.4** — Create `docs/user-guide/bookkeeping/bbv-paragrafen.md` (7 mandatory sections, template-driven editor, auto-populated fields, completeness checks).
- [ ] **Task 6.5** — Create `docs/user-guide/bookkeeping/bbv-reserves-voorzieningen.md` (distinction, mutatie routes, accounting treatment).
- [ ] **Task 6.6** — Create `docs/user-guide/bookkeeping/bbv-mva-administratie.md` (categories, activation, depreciation, componentenmethode).
- [ ] **Task 6.7** — Create `docs/user-guide/bookkeeping/bbv-rightmatigheid.md` (rightmatigheidsverantwoording, tolerance configuration, M&O findings, reporting).
- [ ] **Task 6.8** — Add Dutch (`nl_NL`) translation strings for: `BBV`, `Taakveld`, `Programma`, `Paragraaf`, `Economische Categorie`, `RGS-decentraal`, `Meerjarenraming`, `Sluitend`, `Weerstandsvermogen`, `Reserve`, `Voorziening`, `Materiële Vaste Activa`, `Rechtmatigheidsverantwoording`, `Iv3-aanlevering`, `SiSa-bijlage`, and all 7 paragraaf types.
- [ ] **Task 6.9** — Add English (`en_US`) translation strings (parallel to Dutch set).
- [ ] **Task 6.10** — Commit screenshots of key UI pages (Programmaplan, Meerjarenraming, Paragrafen, MVA-register, Iv3-dashboard) to `docs/images/bbv-*.png`.
- [ ] **Task 6.11** — Create `docs/journeydoc/bbv-gemeente-controller-journey.md` per ADR-030 (persona: gemeente-controller). Scenario: set up new BBV-tenant, author programme budget, input meerjarenraming, validate sluitend, fill paragrafen, export jaarrekening.

## Verification & Sign-off

- [ ] **Task 7.1** — `openspec validate` must exit clean on the change folder (syntax, schema references, dependency cycles).
- [ ] **Task 7.2** — All 12 Phase 5 tests pass (PHPUnit + Playwright).
- [ ] **Task 7.3** — Municipal-controller-persona peer review (e.g. `/test-persona-noor`): Confirm mapping shape matches Commissie BBV handreiking guidance (Notitie MVA, Notitie Reserves, Notitie Grondbeleid).
- [ ] **Task 7.4** — Architecture reviewer confirms ADR-031 compliance (all validation rules declarative, no service classes); ADR-022 compliance (mapping as register, not enum); ADR-032 compliance (spec-sizing and T3 tier).
- [ ] **Task 7.5** — Accountant review: Rightmatigheidsverantwoording fields + tolerance logic conform Kadernota 2024 guidance. Iv3 + SiSa export formats match official BZK/CBS templates.
- [ ] **Task 7.6** — No source code changes outside `openspec/changes/bookkeeping-bbv-compliance/` (and implementation PR in shillinq repo follows same structure).

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
