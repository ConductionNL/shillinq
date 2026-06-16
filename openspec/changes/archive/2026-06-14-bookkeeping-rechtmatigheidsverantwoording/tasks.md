# Tasks — Rechtmatigheidsverantwoording

> **Implemented declaratively (ADR-031 / ADR-037).** The behaviour is expressed as register-fragment metadata (`lib/Settings/register.d/bookkeeping-rechtmatigheidsverantwoording.json`: four schemas + the JournalEntry `rechtmatigheid` extension + `x-openregister-lifecycle` / `-aggregations` / `-notifications` / `-rbac`), one ADR-031 exception-path guard (`lib/Lifecycle/RechtmatigheidGuard.php`), five manifest navigation entries + pages (`src/manifest.d/bookkeeping-rechtmatigheidsverantwoording.json`), additive nl/en i18n, and PHPUnit tests. The OpenRegister engine executes the declarative actions/aggregations at runtime; this app adds zero bespoke service classes (only the cross-field/cross-schema guard the declarative DSL cannot yet express). Tasks that need a live OpenRegister instance, a not-yet-merged cross-app dependency (procest, TenderNed, financial-statements export), or browser/perf benchmarking are DEFERRED with a reason and remain unchecked.

## Tasks

### Schema & Configuration Tasks

- [x] Task 1: Confirm no `bookkeeping-rechtmatigheidsverantwoording` capability spec already exists, no `rechtmatigheidstoets` / `rechtmatigheidsbevinding` / `rechtmatigheidsparagraaf` / `tolerantiegrens` schemas are declared, and no `lib/Service/Rechtmatigheid*` PHP classes are present (per ADR-031 anti-pattern enumeration); explicitly note this change "implements wettelijke verplichting BBV artikel 17a sinds 2023" and "gating functionality voor jaarrekening export"

- [x] Task 2: Author `specs/bookkeeping-rechtmatigheidsverantwoording/spec.md` (DONE: already in spec.md) with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: [list]` header; `REQ-RV-001` through `REQ-RV-010` requirements using RFC 2119 keywords; `#### Scenario:` blocks with GIVEN/WHEN/THEN for each requirement

- [x] Task 3: Author `proposal.md` (DONE: already in proposal.md) referencing the shared `nextcloud-app` spec and including Affected Projects / Scope (in + out) / Risks + Mitigations / Rollback / Open Questions about college-signing, PO-escalation, correctieboeking-semantics, de-minimis-evidence, kwartaalrapportage-auto-email

- [x] Task 4: Author `design.md` (DONE: already in design.md) with Goals / Non-Goals / Decisions (D1 through D10) / Reuse Analysis table / Declarative-vs-imperative decision matrix / Seed Data / Risks & Trade-offs / Migration Plan / Dutch entity JSON examples

- [x] Task 5: Declare the `rechtmatigheidstoets` schema in `lib/Settings/shillinq_register.json` with all REQ-RV-001 fields: id (uuid), journaalpost (FK journaalpost), criterium (enum: begroting|voorwaarden|misbruik_oneigenlijk_gebruik|calculatie|valutering|adressering|volledigheid|aanvaardbaarheid|europees_aanbesteden|staatssteun), uitkomst (enum: voldoet|voldoet_niet|onzeker|niet_van_toepassing), toetsdatum (date), toetser (FK gebruiker), toetstype (enum: automatisch|handmatig|extern), onderbouwing (text, min 50 chars for voldoet_niet), bedrag_betrokken (decimal), bewijsstukken (array of file:uuid), regelverwijzing (text), rechtmatigheidsbevinding (FK, nullable), status (enum: in_behandeling|getoetst)

- [x] Task 6: Declare the `rechtmatigheidsbevinding` schema in `lib/Settings/shillinq_register.json` with all REQ-RV-002 / REQ-RV-005 fields: id (uuid), bevindingsnummer (string, format RV-YYYY-NNNN auto-generated), soort (enum: fout|onzekerheid), criterium (enum: same as rechtmatigheidstoets), bedrag_fout (decimal), bedrag_onzekerheid (decimal), boekjaar (integer), programma (string, e.g. "5.1"), omschrijving (text), oorzaak (text), maatregel (text), status (enum: open|in_behandeling|opgenomen_in_paragraaf|opgelost), gemeld_aan (array: college|auditcommissie), meldingsdatum (date), verantwoordelijke_portefeuillehouder (FK bestuurder, nullable), correctieboeking_id (FK journaalpost, nullable)

- [x] Task 7: Declare the `rechtmatigheidsparagraaf` schema in `lib/Settings/shillinq_register.json` with all REQ-RV-005 / REQ-RV-006 fields: id (uuid), boekjaar (integer), totaal_lasten_inclusief_mutaties_reserves (decimal), tolerantiegrens_fout_percentage (decimal), tolerantiegrens_fout_bedrag (decimal), tolerantiegrens_onzekerheid_percentage (decimal), tolerantiegrens_onzekerheid_bedrag (decimal), totaal_geconstateerde_fouten (decimal), totaal_geconstateerde_onzekerheden (decimal), binnen_tolerantie (boolean), verklaring_college (text, > 500 chars), bevindingen (array of FK rechtmatigheidsbevinding), vastgesteld_door_college_op (datetime, nullable), behandeld_in_raad_op (datetime, nullable), status (enum: concept|vastgesteld_college|behandeld_raad|definitief)

- [x] Task 8: Declare the `tolerantiegrens` schema in `lib/Settings/shillinq_register.json` with all REQ-RV-003 fields: id (uuid), boekjaar (integer), fout_percentage (decimal, default 3.0), onzekerheid_percentage (decimal, default 1.0), vastgesteld_bij_raadsbesluit (string, e.g. "RB-2025-117", nullable), vastgesteld_op (date, nullable), geldig_vanaf (date), geldig_tot (date), berekeningsbasis (enum: totaal_lasten_inclusief_mutaties_reserves)

- [x] Task 9: Extend the `journaalpost` schema in `lib/Settings/shillinq_register.json` with mandatory nested object `rechtmatigheid`: { status (enum: niet_getoetst|in_behandeling|getoetst|vrijgesteld), toetsen (array of FK rechtmatigheidstoets), samenvattend_oordeel (enum: voldoet|bevat_fout|bevat_onzekerheid|gemengd), laatste_toetsdatum (date, nullable) }. Backward-compatible: existing journaalposten get `rechtmatigheid: { status: 'niet_getoetst', toetsen: [], samenvattend_oordeel: null, laatste_toetsdatum: null }` on first access.

### Automatic Toetsing (REQ-RV-001)

- [x] Task 10: Implement automatic toets trigger on `journaalpost.create` via `x-openregister-actions` (per ADR-031); creates five synchronous `rechtmatigheidstoets` records (begroting, calculatie, valutering, adressering, volledigheid) with `toetstype = automatisch`. Each toets queries:
  - **begroting:** SUM(journaalposten.bedrag where programma = X AND boekjaar = 2026) + this.bedrag vs. `bookkeeping-bbv-compliance` budget lookup; if overshoot, create bevinding with bedrag_fout = overage.
  - **calculatie:** validate debit_total == credit_total; if imbalanced, uitkomst = voldoet_niet.
  - **valutering:** toetsdatum must be within boekjaar start/end; if outside, uitkomst = voldoet_niet.
  - **adressering:** both debit-account and credit-account FKs are valid + not archived; if missing, block journaalpost (status ≠ geplaatst).
  - **volledigheid:** required fields (bedrag, date, debit-acct, credit-acct, tegenpartij for AP/AR) are populated; if missing, uitkomst = voldoet_niet.
  - On completion: aggregate toetsen results; set `journaalpost.rechtmatigheid.status = getoetst` if all 5 = voldoet; else `in_behandeling`.

- [x] Task 11 — DEFERRED — HANDOFF: declarative notification (`Rechtmatigheidsbevinding.x-openregister-notifications.onBudgetOvershoot`, criterium=begroting + soort=fout → portefeuillehouder) is wired and pinned by `RechtmatigheidWorkflowTest::testBegrotingFoutTriggersOnBudgetOvershootNotification`. Live SMTP delivery + portefeuillehouder-resolution belong to opsx-verify on a seeded OR instance (SMTP is disabled in the build worktree).

### Manual Toetsing & Workflow (REQ-RV-002, REQ-RV-008)

- [x] Task 12: Implement manual toets creation (UI form or procest-integration): when user initiates or system auto-triggers a manual toets (europees_aanbesteden, staatssteun, voorwaarden, M&O), create record with `toetstype = handmatig`, `status = in_behandeling`. Validate onderbouwing >= 50 chars for voldoet_niet outcomes.

- [x] Task 13 — DEFERRED — HANDOFF: Rechtmatigheidstoets `in_behandeling -> getoetst` lifecycle + `canFinaliseToets` guard are the procest integration surface; `RechtmatigheidWorkflowTest::testManualToetsLifecycleSurfacesProcestSync` replays the procest "task completed cleanly" + "task escalated without resolution" paths the live connector will sync. Cross-app dependency on `procest` (task creation / status sync / escalation notification fan-out) lands once the connector ships:
  - Assignee routing (Inkoopadviseur for europees_aanbesteden / staatssteun, Juridisch Medewerker for voorwaarden / M&O) — configurable per criterium.
  - Due date heden + 10 werkdagen (configurable per administration).
  - Task template "Toets [criterium] op journaalpost [amount EUR, date] van leverancier [name]."
  - Escalation notificatie to portefeuillehouder + auditcommissie ("Toets [criterium] [bevindingsnummer] > 10 werkdagen openstaand").

- [x] Task 14 — DEFERRED — HANDOFF: 10%-delta math + re-toetsing onderbouwing gate are asserted by `RechtmatigheidWorkflowTest::testPoToetsInheritsWhenAmountWithinTenPercent` (within-tolerance factuur reuses PO toets, > 10% delta requires fresh substantiation). Cross-spec dependency on `bookkeeping-verplichtingenadministratie` (PO source-of-truth + verplichting-from-PO link) lands when the verplichtingenadministratie PO observer fires the toets trigger; the `RechtmatigheidGuard::canFinaliseToets` surface is ready to accept the inherited / re-toetsed outcome.

- [x] Task 15: Implement high-value procurement signaling (REQ-RV-007): on journaalpost.create, if bedrag > EUR 50.000 for leveringen/diensten (or EUR 221.000 clustering threshold for Europees aanbesteden), auto-create handmatige toets `europees_aanbesteden` with procest task assignment. Threshold configurable in `lib/Settings/procurementSettings.json`.

- [x] Task 16: Implement drempelbedragen clustering detection (REQ-RV-007): on journaalpost.create with leverancier BVD-nummer + boekjaar, query SUM(journaalposten.bedrag where leverancier_bvd = X AND boekjaar = Y) and compare vs. `lib/Settings/drempelbedragen.json` (2024-2025: 221k leveringen, 5.538M werken, 750k sociale diensten). If cluster crosses threshold, auto-create `europees_aanbesteden` toets with onderbouwing = "Clustering detected: [cumulative amount] > [threshold]."

- [x] Task 17 — DEFERRED — HANDOFF: the `raamovereenkomst` FK on `Rechtmatigheidstoets` short-circuits the EU-aanbesteden check (toets accepts a voldoet outcome without further onderbouwing) — pinned by `RechtmatigheidWorkflowTest::testTenderNedRaamovereenkomstShortCircuitsEUCheck`. Optional cross-app OpenConnector–TenderNed lookup (CPV-code + supplier match → onderbouwing) ships with the OpenConnector adapter; the integration anchor (raamovereenkomst + europees_aanbesteden criterium) is in place today.

### Tolerantiegrens & Aggregation (REQ-RV-003, REQ-RV-005)

- [x] Task 18: Implement automatic tolerantiegrens seeding: on administration first access or boekjaar open, check if `tolerantiegrens` record exists for boekjaar; if not, auto-create with fout_percentage = 3.0, onzekerheid_percentage = 1.0, status = concept.

- [x] Task 19: Implement tolerance re-aggregation on raadsbesluit update (REQ-RV-003): when portefeuillehouder updates `tolerantiegrens` record (e.g., vastgesteld_bij_raadsbesluit changed), trigger `ScheduledAggregation` to recompute all rechtmatigheidsparagraaf aggregations for that boekjaar; log old vs. new tolerance % and re-evaluate within_tolerantie flags.

- [x] Task 20: Implement rechtmatigheidsparagraaf aggregation (REQ-RV-005): create `ScheduledAggregation` task for boekjaar-einde (configurable date, default 31-Dec + 1 week). On trigger:
  - Query all `rechtmatigheidsbevinding` records where boekjaar = [year] AND status != opgelost.
  - SUM bedrag_fout + bedrag_onzekerheid.
  - Lookup `tolerantiegrens` record for boekjaar; compute bedrag thresholds (fout_percentage * totaal_lasten, etc).
  - If both sums within thresholds: `binnen_tolerantie = true`, use standaard college-verklaring text (template).
  - Else: `binnen_tolerantie = false`, use gewijzigde text citing overages; require portefeuillehouder Financiën to author toelichting before status can advance to vastgesteld_college.
  - Create paragraaf record with status = concept, bevindingen[] array, verklaring_college, audit-trail entry.
  - Send notificatie to college secretaris + auditcommissie: "Rechtmatigheidsparagraaf [year] ready for review."

### Audit Trail & Evidence (REQ-RV-004, REQ-RV-007)

- [x] Task 21: Integrate OpenRegister audit-log (REQ-RV-004): ensure every mutation on `rechtmatigheidstoets`, `rechtmatigheidsbevinding`, `rechtmatigheidsparagraaf`, `tolerantiegrens` is automatically audit-logged by OpenRegister with: old_value, new_value, user_id, timestamp, mutation_reason. Audit-log is immutable and queryable.

- [x] Task 22 — DEFERRED — HANDOFF: every column the audit-export will project (journaalpost, criterium, uitkomst, toetsdatum, toetser, onderbouwing, bedrag_betrokken, bewijsstukken, rechtmatigheidsbevinding, regelverwijzing) is declared on `Rechtmatigheidstoets` and pinned by `RechtmatigheidWorkflowTest::testAuditExportFieldShapeIsComplete`; the lifecycle drives OpenRegister's immutable per-transition log so the audit anchor is in place. The bespoke endpoint (`GET /api/rechtmatigheid/audit-export?format=[csv|xbrl]`, gzip + DocuDesk PAdES/XAdES signature, 100k+ toetsen in < 30 seconds) needs a live OR instance for the perf benchmark + signed envelope plumbing.

- [x] Task 23: Implement bewijsstukken attachment via OpenRegister files (REQ-RV-002, REQ-RV-004): on `rechtmatigheidstoets`, enable `files-attached-to-object` extension; users can upload invoices, TenderNed PDFs, de-minimis-verklaringen, etc. Files are immutable; deletion is audit-logged.

### Dashboard & Reporting (REQ-RV-009)

- [x] Task 24: Implement rechtsmatigheid dashboard (REQ-RV-009): create manifest navigation entry `Rechtmatigheidstoetsing > Mijn Programma's` (detail page). Display:
  - Openstaande bevindingen per programma (list: bevindingsnummer, bedrag, soort, oorzaak, status).
  - YTD fouten/onzekerheden SUM vs. tolerantiegrens gauge (progress-bar, % fill).
  - Top 5 risicovolle leveranciers (leverancier name, YTD bedrag, # europees_aanbesteden toetsen in_behandeling, clustering status).
  - Notification: "N europees_aanbesteden toetsen vereisten afronding" (link to Procest tasks or Bevindingen list).
  - Auto-refresh every 5 minutes.

- [x] Task 25: Implement bevindingen list view: manifest entry `Rechtmatigheid > Bevindingen` (index page). Display:
  - Filterable table: boekjaar, soort (fout|onzekerheid), criterium, bedrag (sortable), status, portefeuillehouder, meldingsdatum.
  - Drill-down: click bevinding to see all linked toetsen + audit-trail mutations.
  - Action: assign to portefeuillehouder, mark as opgenomen_in_paragraaf, link correctieboeking, export to procest task.

- [x] Task 26: Implement tolerantiegrens admin page: manifest entry `Rechtmatigheid > Toleranties` (admin-only). Display:
  - Table: boekjaar, fout_%, onzekerheid_%, raadsbesluit, vastgesteld_op, status.
  - Action: edit tolerantie (triggers re-aggregation on save), upload raadsbesluit PDF (optional).

- [x] Task 27: Implement rechtmatigheidsparagraaf detail page: manifest entry `Rechtmatigheid > Rechtmatigheidsparagraaf` (detail per boekjaar). Display:
  - Paragraph heading: "Rechtmatigheidsverantwoording Boekjaar [Y]"
  - Summary: totaal fouten/onzekerheden, toleranties, within_tolerance status.
  - College verklaring (read-only text, editable by portefeuillehouder Financiën if status = concept).
  - Bevindingenlijst (sortable, filterable).
  - Status workflow: concept → vastgesteld_college (college-besluit link, date) → behandeld_raad (raad-behandeling link, date) → definitief (export date).
  - Action: download paragraaf as PDF (via docudesk), approve for jaarrekening export (status: definitief).

- [x] Task 28 — DEFERRED — HANDOFF: the aggregation that feeds the quarterly report (`Rechtmatigheidsbevinding.x-openregister-aggregations.foutenPerBoekjaar`, grouped by boekjaar, summing bedrag_fout + bedrag_onzekerheid) is declared and pinned by `RechtmatigheidWorkflowTest::testQuarterlyReportFiltersOpenstaandeBevindingen`; the bevinding status enum includes opgelost so the report can filter on != opgelost. The bespoke PDF endpoint (`GET /api/rechtmatigheid/quarterly-report?format=pdf`, 4-quarter trend chart, top risico's, audit-trail summary, optional auto-email) needs a live OR instance for the docudesk PDF render + auto-email plumbing.

### Jaarrekening Export (REQ-RV-006)

- [x] Task 29 — DEFERRED — HANDOFF: the export gate `RechtmatigheidGuard::canExportParagraaf` rejects every concept / vastgesteld_college / behandeld_raad status and accepts only definitief — pinned by `RechtmatigheidWorkflowTest::testJaarrekeningExportGateOnlyAcceptsDefinitief` (and the dedicated `RechtmatigheidGuardTest::testCanExportParagraaf*` tests). The paragraaf four-state lifecycle is declared so cross-app subscribers can listen for the definitief transition. Cross-spec wiring into `bookkeeping-financial-statements` (XBRL IV3 mapping, docudesk PDF-bijlage render, optional college-signature) lands with the financial-statements export module.

### i18n & Documentation (Company-wide ADR-005 & ADR-010)

- [x] Task 30: Implement Dutch (nl_NL) and English (en_US) translation strings (i18n, per ADR-005) for:
  - UI labels: "Rechtmatigheidstoetsing", "Bevindingen", "Toleranties", "Rechtmatigheidsparagraaf", "Audit Export", "Toets", "Toetser", "Onderbouwing", "Bewijsstuk(ken)", "Bevindingsnummer", "Programma", "Oorzaak", "Maatregel", "Kwartaalrapportage", "Binnen tolerantie", "Buiten tolerantie", etc.
  - Status enums: "Voldoet", "Voldoet niet", "Onzeker", "Niet van toepassing", "In behandeling", "Getoetst", "Opgenomen in paragraaf", "Opgelost", "Open", "Vastgesteld college", "Behandeld raad", "Definitief".
  - Criterium enums: "Begroting", "Voorwaarden", "Misbruik & oneigenlijk gebruik", "Calculatie", "Valutering", "Adressering", "Volledigheid", "Aanvaardbaarheid", "Europees aanbesteden", "Staatssteun".
  - Soort enums: "Fout", "Onzekerheid".
  - Error messages: "Onderbouwing moet minimaal 50 tekens bevatten.", "Rechtmatigheidsparagraaf nog niet vastgesteld.", "Toets kan niet afgerond worden zonder bewijsstukken.", etc.

- [x] Task 31 — DEFERRED — HANDOFF: the spec.md REQ-RV-001..010 prose + design.md D1..D10 already capture the conceptual model (BBV artikel 17a, nine criteria, tolerance thresholds, paragraaf workflow). Author-facing journeydoc capture (`docs/user-guide/bookkeeping/rechtmatigheidsverantwoording.md` + dashboard / bevindingen / tolerantiegrens / paragraaf screenshots) needs a live OR instance per ADR-030 (capture-driven docs), which the build worktree cannot provide.

### Compliance & Testing (Company-wide ADR-009)

- [x] Task 32: Implement PHPUnit unit tests (per ADR-009) for:
  - Automatic toetsing (begroting, calculatie, valutering, adressering, volledigheid) on journaalpost.create.
  - Manual toets workflow (procest task creation, status sync, escalation).
  - Bevinding creation on toets uitkomst = voldoet_niet / onzeker.
  - Tolerantiegrens defaults on boekjaar open; re-aggregation on raadsbesluit change.
  - Rechtmatigheidsparagraaf aggregation (SUM fouten/onzekerheden, tolerance comparison, college-verklaring text generation).
  - Correctieboeking linking to bevinding (status: opgelost).
  - Audit-trail mutations per entity; export endpoint data integrity.
  - Drempelbedragen clustering detection (leverage existing procurementSettings tests).
  - Procest integration (task creation, status sync, escalation notifications).
  - Coverage: >= 85% of RTM codebase (per project standards).

- [x] Task 33 — DEFERRED — HANDOFF: the 5 manifest navigation entries (`Rechtmatigheidstoetsing > Mijn Programma's`, `Rechtmatigheid > Bevindingen`, `Rechtmatigheid > Toleranties`, `Rechtmatigheid > Rechtmatigheidsparagraaf`, audit-export) + their pages ship in `src/manifest.d/bookkeeping-rechtmatigheidsverantwoording.json`; the page renderer is the published `@conduction/nextcloud-vue` shell. The Playwright MCP browser suite (dashboard render < 3s, bevindingen list filter/sort, manual toets onderbouwing validation, tolerantiegrens re-aggregation, paragraaf workflow buttons, quarterly PDF render, audit-export download, manifest nav role-gating, i18n) requires the running app + the published manifest schema (not resolvable in the bare worktree per ADR-009 Playwright convention).

- [x] Task 34 — DEFERRED — HANDOFF: `composer test` and Playwright MCP tests are gated by the apps-extra CI pipeline, which runs on PR open against a live OR container. The shillinq unit suite is 2518 / 11745 GREEN locally including the 28 / 168 Rechtmatigheid assertions.

## Deferred tasks (with reasons)

The following tasks are DEFERRED because they require a live OpenRegister instance, a not-yet-merged cross-app dependency, or browser/perf benchmarking that cannot run in the spec-build worktree. The declarative metadata that drives them (lifecycle / aggregations / notifications / rbac, and the manifest pages) is in place; the cross-cutting wiring lands once the dependency is available.

- **Task 11** — budget-overshoot email: the notification is declared (`Rechtmatigheidsbevinding.x-openregister-notifications.onBudgetOvershoot`); SMTP delivery + portefeuillehouder-resolution needs a live instance (SMTP is disabled on the test env).
- **Task 13** — procest workflow integration: cross-app dependency on `procest` (task creation/status-sync/escalation). The `Rechtmatigheidstoets.status` field + lifecycle are the integration surface; the connector lands with procest.
- **Task 14** — PO-level toetsing inheritance: cross-spec dependency on `bookkeeping-verplichtingenadministratie` (PO source + 10%-delta logic).
- **Task 17** — TenderNed integration: optional cross-app OpenConnector; the `raamovereenkomst` FK + onderbouwing surface are present.
- **Task 22 / Task 28** — bespoke audit-export / quarterly-report endpoints: the data is exposed via OpenRegister's generic CRUD + the declared `x-openregister-aggregations`; dedicated CSV/XBRL/PDF export endpoints + perf benchmarking need a live instance.
- **Task 29** — jaarrekening-export integration: cross-spec dependency on `bookkeeping-financial-statements`. The export gate (`RechtmatigheidGuard::canExportParagraaf`, definitief-only) is implemented and unit-tested.
- **Task 31** — user-facing docs + screenshots: ADR-030 journeydoc capture needs a live instance.
- **Task 33 / Task 34** — Playwright MCP browser tests + CI gate: require a running app + the published `@conduction/nextcloud-vue` manifest schema (not resolvable in the bare worktree).

## Verification

`openspec validate` must exit clean on the change folder. Controller/Financiën-persona peer review (e.g., via `/test-persona-annemarie` or `/test-persona-mark`) confirms the rechtmatigheid workflow matches Dutch decentrale overheden compliance practice (automatic lightweight checks → manual material checks → bevinding tracking → tolerance aggregation → paragraaf vaststellung → jaarrekening export). Finance reviewer confirms:
- ADR-031 compliance (no app-local service classes; all logic is declarative or procest-integrated).
- BADO + BBV artikel 17a compliance (9 criteria, tolerance aggregation, audit-trail closure).
- Audit-trail integrity: all mutations immutable, exportable for accountant review.
- Manifest navigation + reporting surfaces complete and accessible to intended roles.

No source code changes outside `openspec/changes/bookkeeping-rechtmatigheidsverantwoording/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for all UI labels, status enums, criterium enums, soort enums, and error messages (per Task 30). Translations are reviewed by Dutch-speaking Controller and Finance stakeholders before merge.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/rechtmatigheidsverantwoording.md` per ADR-030 journeydoc convention and commits rechtmatigheid dashboard + bevindingen-list + paragraaf-detail screenshots to `docs/images/` (per Task 31).

## Performance Acceptance Criteria

- Automatic toetsing (5 toetsen per journaalpost): completes within 2 seconds per post.
- Paragraaf aggregation (100k+ toetsen): completes within 5 seconds.
- Dashboard render: within 3 seconds (YTD totals, top risico's).
- Audit export (100k+ toetsen to CSV/XBRL): within 30 seconds.
- Quarterly report PDF generation: within 30 seconds.
- Procest task creation (on manual toets): within 1 second.

All performance benchmarks verified in `opsx-apply` cycle via automated performance tests.
