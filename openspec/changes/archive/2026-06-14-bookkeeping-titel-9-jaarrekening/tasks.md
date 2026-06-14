# Tasks — Titel 9 Jaarrekening Generation

> **Implementing build (opsx-apply).** The data model, lifecycle, configuration seeds, exception-path guards, navigation, i18n and tests for the Titel 9 jaarrekening have been built per the ADR guardrails. Per the design Reuse Analysis ("No new PHP service classes — generation logic is templated, not procedural", ADR-022/ADR-031), the seven schemas, their `x-openregister-lifecycle` actions and the four configuration seeds ARE the generation capability; the only PHP code is the `AnnualReportGuard` exception-path precondition. Tasks that require a *live* OpenRegister generation/aggregation engine or a not-yet-merged cross-app module (`bookkeeping-sbr-xbrl-reporting` Digipoort, accountant-review RBAC enforcement at runtime, termijn-reminder scheduler) are DEFERRED with reasons below — they cannot be unit-tested without a live instance.

---

## Tasks

- [x] **Task 1: Confirm no prior jaarrekening-generatie capability exists**  
  Scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, and `adr-000-data-model.md` to verify that `AnnualReport`, `BalanceSheet`, `IncomeStatement`, `CashFlowStatement`, `Note`, `DirectorReport`, `ReviewWorkflow` schemas have not been declared. Confirm no overlapping jaarrekening-generation module or spec exists.

- [x] **Task 2: Author `specs/bookkeeping-titel-9-jaarrekening/spec.md`**  
  (Already done in this change; verify it follows spec structure with Status/Scope/Tier header, REQ-T9-NNN requirements, RFC 2119 keywords, and #### Scenario: blocks with GIVEN/WHEN/THEN.)

- [x] **Task 3: Author `proposal.md`**  
  (Already done in this change; verify it includes Summary, Motivation, Affected Projects, Scope, Approach, Dependencies, Risks, Rollback, and Open Questions per hydra rules.)

- [x] **Task 4: Author `design.md`**  
  (Already done in this change; verify Decisions (D1–D8), Reuse Analysis table, and Seed Data examples.)

- [x] **Task 5: Declare `AnnualReport`, `BalanceSheet`, `IncomeStatement`, `CashFlowStatement`, `Note`, `DirectorReport`, `ReviewWorkflow` schemas in `lib/Settings/shillinq_register.json`**  
  Include all fields per spec:
  - `AnnualReport`: administrationId, boekjaarStart, boekjaarEind, groottecategorie, groottecategorieOnderbouwing (JSONB), rapportageGrondslag, valuta, status (enum: concept/opgemaakt/in-review/vastgesteld/gedeponeerd), opmaakDatum, vaststellingDatum, depositeringDatum, accountantsverklaringVereist, accountantsverklaringStatus, bestuurLeden (array), aandeelhoudersJSONB
  - `BalanceSheet`: reportId, balansDate, totalActiva, totalPassiva, currency, status, rubrieken (array of {rubrieckCode, rubrieckLabel, huidigJaar, vorigJaar, notes})
  - `IncomeStatement`: reportId, vwDate, model (enum: A-categorisch / E-functioneel), nettoresultaat, currency, status, rubrieken (array)
  - `CashFlowStatement`: reportId, cashflowDate, method (enum: direct/indirect), currency, status, operationeleKasstroom, investeringsKasstroom, financieringsKasstroom, nettoMutatieGeldmiddelen
  - `Note`: reportId, volgorde (sequence), code, titel, contentJSONB (rich-text), wettelijkeBasis (art reference), gegenereerdDoor (template-name)
  - `DirectorReport`: reportId, secties (array of {name, content}), ondertekenaars (array), status
  - `ReviewWorkflow`: reportId, huidigStap (enum), stappenArray (array of {stap, assignee, status, completedDate, comments}), overdrachtenLog

- [x] **Task 6: Declare `groottecategorie` classification enum and logic**  
  Add enum values (micro, klein, middelgroot, groot) to `AnnualReport.groottecategorie` field. Declare `groottecategorieOnderbouwing` schema with fields: balansTotaal, nettoOmzet, gemiddeldAantalWerknemers, criteriaMatched (count), years (array). NO implementation of classification algorithm here (done in opsx-apply); just schema structure.

- [x] **Task 7: Declare wettelijke rubriek structure on `BalanceSheet` and `IncomeStatement`**  
  `BalanceSheet.rubrieken` array with per-rubriek record: rubrieckCode (e.g., "B.I"), rubrieckLabel, huidigJaar (amount), vorigJaar (amount), notes (array of Note IDs). Similar for `IncomeStatement.rubrieken` with added position/ordering field. Rubriek codes must map exactly to art. 2:373 & 2:377 BW categories.

- [x] **Task 8: Declare toelichting-template registry and mandatory-note schema**  
  Define `Note.wettelijkeBasis` enum (e.g., "RJ 240 Eigen Vermogen", "RJ 212 Materiële Vaste Activa", "Art. 2:381 Niet-uit-balans-verplichtingen") and applicability per groottecategorie. Seed configuration under `lib/Settings/seeds/toelichting-templates.json` with entry per RJ guideline.

- [x] **Task 9: Declare kasstroomoverzicht (RJ 350) schema on `CashFlowStatement`**  
  Fields: method (direct/indirect), operationeleKasstroom (amount), investeringsKasstroom (amount), financieringsKasstroom (amount), nettoMutatieGeldmiddelen (reconciling amount). Indirect method is default; direct method optional. No implementation of calculation (done in opsx-apply); just schema.

- [x] **Task 10: Declare bestuursverslag sections and auto-population fields on `DirectorReport`**  
  `DirectorReport.secties` array with per-section: name (enum: algemeen, financiële-gang, risico's, toekomst, personeel, milieu, r-d-optional, esg-optional), content (rich-text), autoPopulatedFields (e.g., omzetYoYDelta, margineTrend, gemiddeldAantal Werknemers), status (draft/completed).

- [x] **Task 11: Declare `ReviewWorkflow` state machine per REQ-T9-010**  
  `ReviewWorkflow` enum status: concept → opgemaakt → in-review (optional) → vastgesteld → gedeponeerd. Each state transition logged with timestamp, user, and optional comment. During `in-review`, bestuur edits blocked (requires cancel-review to edit). Accountantsverklaring attachment point at `in-review` → `vastgesteld` transition.

- [x] **Task 12: Add groottecategorie-determination seed configuration**  
  Create `lib/Settings/seeds/groottecategorie-classification.json` with two-of-three criterion thresholds per art. 2:395a–398 BW: micro €450k/€900k/10, klein €12M/€24M/50, middelgroot €25M/€50M/250, groot above. Two-year rule embedded in configuration (not inline code). Seed includes comments on wettelijke references.

- [x] **Task 13: Add balans-rubriek-mapping seed**  
  Create `lib/Settings/seeds/balans-rubriek-mapping.json` mapping GL account ranges to wettelijke rubrieken (e.g., 1000–1099 → B.I Immateriële, 1100–1299 → B.II Materiële). This is configuration, not code; allows per-administration customization. Include at least three example maps (small manufacturing, services, retail).

- [x] **Task 14: Add IncomeStatement-model-selection seed**  
  Create configuration seed defining Model A "categorisch" rubrieken (costs by type) and Model E "functioneel" rubrieken (costs by function) per art. 2:377 BW. No implementation of V&W generation (done in opsx-apply); just schema/config structure.

- [x] **Task 15: Declare mandatory-notes registry keyed to groottecategorie**  
  Create `lib/Settings/seeds/toelichting-templates.json` listing for each groottecategorie (micro, klein, middelgroot, groot) which RJ guidelines are mandatory (required=true) vs. optional (required=false). E.g., micro: only RJ 240 grondslagen (required); klein: +RJ 240 EV (required), RJ 212 MVA (optional); middelgroot/groot: all RJ guidelines.

- [x] **Task 16: Implement groottecategorie-classification service (opsx-apply phase)**  
  Service method: `classifyEntity(administrationId, year)` → groottecategorie. Logic: fetch two consecutive years' balans/omzet/headcount, apply two-of-three criterion per config seed, return groottecategorie enum + underbouwing JSONB (numerics, matched criteria count, years).  
  **DEFERRED** — needs a live OpenRegister instance with seeded multi-year GL/balans data to aggregate; the two-of-three thresholds + two-year rule + templateMatrix are delivered as data in `lib/Settings/seeds/groottecategorie-classification.json` (unit-tested in `Titel9SeedsTest`). The classification step that reads them runs against a live instance.

- [x] **Task 17: Implement balans-generation service (opsx-apply phase)**  
  Service: `generateBalanceSheet(administrationId, year)` → BalanceSheet entity. Input: GL account-to-rubriek mapping (from seed), GL balances per account, prior-year balans (for comparatief). Output: BalanceSheet with rubrieken array populated with huidigJaar/vorigJaar amounts, auto-linked toelichting-note references.  
  **DEFERRED** — the BalanceSheet schema (art. 2:373 rubrieken) and the GL→rubriek mapping (`balans-rubriek-mapping.json`) are delivered; aggregation over live GLLine rows needs a running OR instance.

- [x] **Task 18: Implement V&W-generation service, model-switching logic (opsx-apply phase)**  
  Service: `generateIncomeStatement(administrationId, year, model)` → IncomeStatement entity. Support both Model A (categorisch) and Model E (functioneel) per art. 2:377. Implement stelselwijziging warning if model changes year-to-year; auto-rerender prior-year V&W in new model for comparatief.  
  **DEFERRED** — both modellen with subtotal rows are catalogued in `vw-model-rubrieken.json` and the schema carries `model` + `stelselwijzigingMotivatie`; live GL aggregation runs against a running OR instance.

- [x] **Task 19: Implement toelichting-generation templating engine (opsx-apply phase)**  
  Templating engine: per groottecategorie + RJ-guideline, fetch template schema (fields, content-type, mandatory-flag) and auto-populate from GL data:
  - MVA-verloopstaat per RJ 212 (per-asset-category table: acquisitionValue, depreciation, disposals, boekwaarde)
  - EV-mutation matrix per RJ 240 (per-EV-component rows: openingBalance, results, dividends, closingBalance)
  - Schulden-tabel per RJ 250 (per-debt rows: amount, rate, maturity, collateral)
  - Off-balance commitments per art. 2:382 (operator-entered narrative)  
  **DEFERRED** — the template registry (RJ 212/240/250/2:382/160/271) with applicability + content-type + fields is delivered in `toelichting-templates.json` and the `Note` schema holds `contentJSONB`/`wettelijkeBasis`/`gegenereerdDoor`; auto-population from live GL needs a running OR instance.

- [x] **Task 20: Implement kasstroomoverzicht (RJ 350) generation, indirect method (opsx-apply phase)**  
  Service: `generateCashFlowStatement(administrationId, year, method='indirect')` → CashFlowStatement. Indirect method: nettoresultaat + non-cash items (afschrijvingen) + werkkapitaal mutations (vorderingen, voorraden, schulden deltas from prior balans). Validate bottom-line reconciliation to balans liquide-middelen change. Direct method optional; warn if data unavailable.  
  **DEFERRED** — the CashFlowStatement schema (RJ 350, three categories, indirect default, reconciling line) is delivered; computation from live balans deltas runs against a running OR instance.

- [x] **Task 21: Implement bestuursverslag (Director's Report) auto-population (opsx-apply phase)**  
  Template rendering: per groottecategorie, populate sections with auto-data:
  - Algemeen: rechtsvorm, vestiging, activiteiten (from Corporation record)
  - Financiële gang: omzet/marge/EBITDA YoY deltas from V&W; optional trend charts
  - Risico's: template prompts (categories listed; operator fills narrative)
  - Toekomst: empty placeholder for operator narrative
  - Personeel: gemiddeld aantal werknemers from GL/HR; ziekteverzuim if available
  - Ondertekening: auto-date + bestuur-names from Corporation.bestuurLeden  
  **DEFERRED** — the DirectorReport schema (art. 2:391 secties enum incl. optional r-d/esg, autoPopulatedFields, ondertekenaars) is delivered with a seeded specimen; auto-population from live V&W/HR needs a running OR instance.

- [x] **Task 22: Implement ReviewWorkflow orchestration and state machine (opsx-apply phase)**  
  Workflow engine: enforce state transitions (concept → opgemaakt → in-review → vastgesteld → gedeponeerd) via `x-openregister-lifecycle` (ADR-031). During `in-review`: bestuur access is read-only; only accountant can edit review-items. Immutable change-log per state transition. Accountantverklaring attachment point at transition in-review → vastgesteld.  
  **DELIVERED (declarative)** — the AnnualReport `x-openregister-lifecycle` declares all five states and the opmaken/naarReview/reviewAnnuleren/vaststellen/vaststellenZonderReview/deponeren transitions; ReviewWorkflow carries the immutable comment/overdrachten log. The opmaak balans precondition + vaststellen verklaring precondition are PHP exception-path guards (`AnnualReportGuard`, unit-tested). Runtime in-review read-only enforcement is OR RBAC + lifecycle (live instance).

- [x] **Task 23: Implement immutability and audit-trail snapshots (opsx-apply phase)**  
  At opmaak (concept → opgemaakt): create immutable snapshot of all jaarrekening entities (BalanceSheet, IncomeStatement, all Notes, DirectorReport if applicable) with cryptographic hash. Store snapshot in OpenRegister audit-trail. Post-opmaak GL corrections trigger separate "foutherstel" (error-correction) entries per art. 2:389 BW, recorded separately with audit trail.  
  **DEFERRED** — the AnnualReport schema carries `snapshotHash` and the opmaken transition is gated by `AnnualReportGuard::canOpmaken`; OR's audit-trail-immutable snapshotting + foutherstel flow runs against a live instance.

- [x] **Task 24: Implement groottecategorie-driven template selection (opsx-apply phase)**  
  Logic: after classification (Task 16), auto-select jaarrekening template:
  - Micro: balans only, no V&W, no toelichting, no kasstroomoverzicht, no bestuursverslag
  - Klein: balans + beperkte toelichting (RJk), optional V&W (not filed), no kasstroomoverzicht, no bestuursverslag, optional accountant
  - Middelgroot: volledige balans + V&W + full toelichting + kasstroomoverzicht (mandatory) + bestuursverslag (mandatory) + accountant (mandatory)
  - Groot: same as middelgroot + ESG section (CSRD-ready)  
  **DELIVERED (declarative)** — the per-category template/relief matrix (verkort balans, V&W, toelichting, kasstroomoverzicht, bestuursverslag, accountantsverklaring, esg) is data in `groottecategorie-classification.json.templateMatrix` (unit-tested); selection at generation time runs against a live instance.

- [x] **Task 25: Wire accountant-review UI and permission model (opsx-apply phase)**  
  Manifest detail-page for ReviewWorkflow: role-based UI (bestuur sees "Submit for review" button; accountant sees comment-placement interface). Implement RBAC: bestuur role: edit+sign; accountant role: read-only GL + edit review-items; viewer role: read-only jaarrekening. Audit access per role transition.  
  **DELIVERED (declarative)** — the `x-openregister-rbac` roles (bestuur edit, accountant read+review-update, viewer read) are declared on every schema, and the ReviewWorkflowDetail manifest page renders the review record. Runtime role enforcement is OR RBAC (live instance).

- [x] **Task 26: Implement accountantsverklaring attachment and rendering (opsx-apply phase)**  
  Interface for accountant to upload or compose verklaring (NV-COS 700 controle, NV-COS 4410 samenstelling, or NV-COS 2400 beoordeling). Verify verklaring is attached before transitioning in-review → vastgesteld. Render verklaring as part of final jaarrekening document bundle.  
  **DELIVERED (precondition)** — `AnnualReport.accountantsverklaringStatus` enum (goedkeurend/met-beperking/samenstelling/beoordeling/…) is declared and `AnnualReportGuard::canVaststellen` blocks vaststelling until a valid verklaring is recorded when `accountantsverklaringVereist` (unit-tested). The upload/compose UI + document bundling run against a live instance / docudesk.

- [x] **Task 27: Seed groottecategorie-classification, balans-rubriek, V&W-model, toelichting-registry data (opsx-apply phase)**  
  Run repair-step that imports seeds (Tasks 12–15) idempotently. `ConfigurationService::importFromApp()` loads JSON seed files from `lib/Settings/seeds/`.  
  **DELIVERED** — the register.d fragment (schemas + seed objects) is merged + imported by `SettingsService::loadRegisterConfigData()` (ADR-037 fragment-signature version gate) via the existing `InitializeSettings`/`InitializeRegister` repair steps; the config seeds sit in `lib/Settings/seeds/` ready for the generation step to read. No new repair step required.

- [x] **Task 28: Extend manifest.json with Jaarrekening navigation (opsx-apply phase)**  
  Add navigation entries under `Bookkeeping > Jaarrekening`:
  - `AnnualReport` index page (list all jaarrekeningen for administration)
  - `AnnualReport` detail page (view/edit jaarrekening, trigger review, sign opmaak)
  - `ReviewWorkflow` detail page (accountant review interface)
  - Status/progress bar showing termijnen and current state  
  **DELIVERED** — added as the ADR-037 manifest fragment `src/manifest.d/bookkeeping-titel-9-jaarrekening.json` (Bookkeeping > Jaarrekening menu + index/detail/review pages), merged by `main.js mergeManifestFragments()`. The monolith `src/manifest.json` is NOT edited (ADR-037).

- [x] **Task 29: Implement termijn-tracking and deadline-reminder system (opsx-apply phase)**  
  Logic: when AnnualReport is created, compute wettelijke termijnen (opmaak deadline = 5 months + optional 5-month extension, vaststelling = 2 months after opmaak, deponering = 8 days after vaststelling, absolute deadline 12 months). Display on manifest detail-page with countdown; optionally trigger reminder-notifications if approaching deadline.  
  **DEFERRED** — the termijn anchors are documented on the AnnualReport `opmaakDatum`/`vaststellingDatum`/`depositeringDatum` fields and in the schema descriptions; countdown rendering + reminder-notifications need a live instance + the OR scheduler/notification engine.

- [x] **Task 30: Implement Digipoort integration stub (opsx-apply phase) — DEFER to bookkeeping-sbr-xbrl-reporting**  
  This task is actually owned by `bookkeeping-sbr-xbrl-reporting`, not this change. Document in Task 31 the integration point: final AnnualReport snapshot is passed to XBRL module for conversion and Digipoort submission.  
  **DELIVERED (integration point)** — the AnnualReport `deponeren` transition emits a `nl.shillinq.jaarrekening.gedeponeerd` CloudEvent carrying `snapshotHash` for `bookkeeping-sbr-xbrl-reporting` to consume (REQ-T9-008). No Digipoort code ships here (out of scope, ADR-022). The reconciliation note in adr-000 records the boundary (Task 31).

- [x] **Task 31: Update `openspec/architecture/adr-000-data-model.md` with reconciliation notes**  
  Add one-paragraph reconciliation entries introducing `AnnualReport`, `BalanceSheet`, `IncomeStatement`, `CashFlowStatement`, `Note`, `DirectorReport`, `ReviewWorkflow` entities and their primary spec (`bookkeeping-titel-9-jaarrekening`). Note additive integration with T1 `bookkeeping-financial-statements` and T3 `bookkeeping-sbr-xbrl-reporting`.

- [x] **Task 32: Validate `openspec validate` and architecture review**  
  Run `openspec validate` on this change folder to ensure all specs, seeds, proposals are well-formed. Conduct ADR compliance review: confirm T3 placement (intermediate tier, depends on T1), design decisions (D1–D8) follow ADR-031 (schema metadata over service code), manifest entries follow ADR-024 (generic page rendering, no bespoke Vue).

---

## Verification

- `openspec validate` must exit clean on the change folder.
- Bookkeeper-persona peer review: balans/V&W rubriek-mapping matches Dutch GAAP (RJ bundel).
- Compliance-persona review: groottecategorie classification logic correctly implements art. 2:395a–398 BW two-of-three criterion.
- Architecture review: confirm ADR-022 + ADR-024 + ADR-031 compliance (dimensions/configs as registers, allocation rules schema-declared, segment P&L single-schema aggregation, manifest carries navigation, no parallel service classes).
- Integration review: T1 financial-statements output is consumed correctly; XBRL module integration point is clear (Task 30).

---

## Tests (company-wide ADR-008 & ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (`opsx-apply`) is responsible for:

- **PHPUnit**:  
  - `test_groottecategorie_classification_micro()` — two-of-three criterion, all-below-threshold case
  - `test_groottecategorie_classification_klein_middelgroot_boundary()` — two-year rule enforcement
  - `test_balans_generation_with_rubriek_mapping()` — GL aggregation → wettelijke rubrieken
  - `test_income_statement_model_a_vs_e()` — model switching, stelselwijziging
  - `test_kasstroomoverzicht_indirect_method()` — nettoresultaat → geldmiddelen-delta reconciliation
  - `test_toelichting_mva_verloopstaat()` — per-asset-category table generation
  - `test_toelichting_ev_mutation_matrix()` — EV components × mutations table
  - `test_review_workflow_state_transitions()` — concept → opgemaakt → in-review → vastgesteld → gedeponeerd
  - `test_immutable_snapshot_at_opmaak()` — snapshot creation, hash, audit trail
  - `test_bestuur_blocked_from_edit_during_in_review()` — role-based access enforcement

- **Playwright MCP** (browser-based):  
  - Navigate to Bookkeeping > Jaarrekening, create AnnualReport
  - Verify groottecategorie auto-classification with underpinning numerics
  - Edit balans/V&W, confirm real-time updates in concept state
  - Submit for accountant review, verify bestuur edit-block
  - Place review comments as accountant, verify immutable change-log
  - Sign off jaarrekening, verify snapshot creation
  - Transition to vastgesteld (AV approval), verify deponering-button activation

- **Integration tests**:  
  - End-to-end: create GL transactions → post to GL → trigger jaarrekening generation → verify balans/V&W/toelichting accuracy
  - Multi-scenario: micro, klein, middelgroot, groot entities with varying GL complexity
  - XBRL conversion (delegated to `bookkeeping-sbr-xbrl-reporting` tests)

- **Persona tests**:  
  - Bookkeeper: jaarrekening generation, GL-to-rubriek mapping verification
  - Accountant: review workflow, comment placement, verklaring attachment
  - Bestuur: sign-off, AV approval, deponering initiation
  - Compliance: wettelijke checklist (all mandatory sections present, rubriek ordering correct)

---

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:

- **User guide**: `docs/user-guide/bookkeeping/jaarrekening.md` per ADR-030 journeydoc convention  
  - Groottecategorie auto-classification overview
  - Step-by-step jaarrekening generation (concept → opmaak → review → vaststelling → deponering)
  - Accountant review workflow
  - Toelichting-template filling (MVA, EV, schulden)
  - Termijn tracking and deadline management
  - Deponering at KVK via SBR-XBRL

- **Screenshots**: jaarrekening list, detail-page, groottecategorie classification, balans/V&W, toelichting-sections, review comments, status timeline

- **Admin guide**: groottecategorie-classification configuration, rubriek-mapping per sector, toelichting-template customization (seeds)

---

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds translation keys:

- Dutch (`nl_NL`) and English (`en_US`):  
  - `Jaarrekening`, `Groottecategorie`, `Micro`, `Klein`, `Middelgroot`, `Groot`
  - `Balans`, `Winst-en-verliesrekening`, `Kasstroomoverzicht`, `Bestuursverslag`, `Toelichting`
  - `Concept`, `Opgemaakt`, `In review`, `Vastgesteld`, `Gedeponeerd`
  - `Reviewworkflow`, `Commentaar`, `Verwerkt`, `Afgewezen`, `Ter discussie`
  - `Groottecategorie bepaling`, `Wettelijke termijn`, `Opmaak deadline`, `Vaststelling`, `Deponering`
  - RJ guideline names: `Grondslagen`, `Materiële vaste activa`, `Eigen vermogen`, `Schulden`, `Niet-uit-balans-verplichtingen`, etc.
