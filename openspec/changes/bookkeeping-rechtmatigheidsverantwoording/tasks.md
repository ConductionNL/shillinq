# Tasks — Rechtmatigheidsverantwoording

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here. The tasks below describe the work an `opsx-apply` cycle will execute against the `bookkeeping-rechtmatigheidsverantwoording` spec — they are recorded now so the spec-review gate, dependency planning, and tier-cascade impact are all visible at proposal time. No source files are edited by this change itself.

## Tasks

### Schema & Configuration Tasks

- [ ] Task 1: Confirm no `bookkeeping-rechtmatigheidsverantwoording` capability spec already exists, no `rechtmatigheidstoets` / `rechtmatigheidsbevinding` / `rechtmatigheidsparagraaf` / `tolerantiegrens` schemas are declared, and no `lib/Service/Rechtmatigheid*` PHP classes are present (per ADR-031 anti-pattern enumeration); explicitly note this change "implements wettelijke verplichting BBV artikel 17a sinds 2023" and "gating functionality voor jaarrekening export"

- [ ] Task 2: Author `specs/bookkeeping-rechtmatigheidsverantwoording/spec.md` (DONE: already in spec.md) with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: [list]` header; `REQ-RV-001` through `REQ-RV-010` requirements using RFC 2119 keywords; `#### Scenario:` blocks with GIVEN/WHEN/THEN for each requirement

- [ ] Task 3: Author `proposal.md` (DONE: already in proposal.md) referencing the shared `nextcloud-app` spec and including Affected Projects / Scope (in + out) / Risks + Mitigations / Rollback / Open Questions about college-signing, PO-escalation, correctieboeking-semantics, de-minimis-evidence, kwartaalrapportage-auto-email

- [ ] Task 4: Author `design.md` (DONE: already in design.md) with Goals / Non-Goals / Decisions (D1 through D10) / Reuse Analysis table / Declarative-vs-imperative decision matrix / Seed Data / Risks & Trade-offs / Migration Plan / Dutch entity JSON examples

- [ ] Task 5: Declare the `rechtmatigheidstoets` schema in `lib/Settings/shillinq_register.json` with all REQ-RV-001 fields: id (uuid), journaalpost (FK journaalpost), criterium (enum: begroting|voorwaarden|misbruik_oneigenlijk_gebruik|calculatie|valutering|adressering|volledigheid|aanvaardbaarheid|europees_aanbesteden|staatssteun), uitkomst (enum: voldoet|voldoet_niet|onzeker|niet_van_toepassing), toetsdatum (date), toetser (FK gebruiker), toetstype (enum: automatisch|handmatig|extern), onderbouwing (text, min 50 chars for voldoet_niet), bedrag_betrokken (decimal), bewijsstukken (array of file:uuid), regelverwijzing (text), rechtmatigheidsbevinding (FK, nullable), status (enum: in_behandeling|getoetst)

- [ ] Task 6: Declare the `rechtmatigheidsbevinding` schema in `lib/Settings/shillinq_register.json` with all REQ-RV-002 / REQ-RV-005 fields: id (uuid), bevindingsnummer (string, format RV-YYYY-NNNN auto-generated), soort (enum: fout|onzekerheid), criterium (enum: same as rechtmatigheidstoets), bedrag_fout (decimal), bedrag_onzekerheid (decimal), boekjaar (integer), programma (string, e.g. "5.1"), omschrijving (text), oorzaak (text), maatregel (text), status (enum: open|in_behandeling|opgenomen_in_paragraaf|opgelost), gemeld_aan (array: college|auditcommissie), meldingsdatum (date), verantwoordelijke_portefeuillehouder (FK bestuurder, nullable), correctieboeking_id (FK journaalpost, nullable)

- [ ] Task 7: Declare the `rechtmatigheidsparagraaf` schema in `lib/Settings/shillinq_register.json` with all REQ-RV-005 / REQ-RV-006 fields: id (uuid), boekjaar (integer), totaal_lasten_inclusief_mutaties_reserves (decimal), tolerantiegrens_fout_percentage (decimal), tolerantiegrens_fout_bedrag (decimal), tolerantiegrens_onzekerheid_percentage (decimal), tolerantiegrens_onzekerheid_bedrag (decimal), totaal_geconstateerde_fouten (decimal), totaal_geconstateerde_onzekerheden (decimal), binnen_tolerantie (boolean), verklaring_college (text, > 500 chars), bevindingen (array of FK rechtmatigheidsbevinding), vastgesteld_door_college_op (datetime, nullable), behandeld_in_raad_op (datetime, nullable), status (enum: concept|vastgesteld_college|behandeld_raad|definitief)

- [ ] Task 8: Declare the `tolerantiegrens` schema in `lib/Settings/shillinq_register.json` with all REQ-RV-003 fields: id (uuid), boekjaar (integer), fout_percentage (decimal, default 3.0), onzekerheid_percentage (decimal, default 1.0), vastgesteld_bij_raadsbesluit (string, e.g. "RB-2025-117", nullable), vastgesteld_op (date, nullable), geldig_vanaf (date), geldig_tot (date), berekeningsbasis (enum: totaal_lasten_inclusief_mutaties_reserves)

- [ ] Task 9: Extend the `journaalpost` schema in `lib/Settings/shillinq_register.json` with mandatory nested object `rechtmatigheid`: { status (enum: niet_getoetst|in_behandeling|getoetst|vrijgesteld), toetsen (array of FK rechtmatigheidstoets), samenvattend_oordeel (enum: voldoet|bevat_fout|bevat_onzekerheid|gemengd), laatste_toetsdatum (date, nullable) }. Backward-compatible: existing journaalposten get `rechtmatigheid: { status: 'niet_getoetst', toetsen: [], samenvattend_oordeel: null, laatste_toetsdatum: null }` on first access.

### Automatic Toetsing (REQ-RV-001)

- [ ] Task 10: Implement automatic toets trigger on `journaalpost.create` via `x-openregister-actions` (per ADR-031); creates five synchronous `rechtmatigheidstoets` records (begroting, calculatie, valutering, adressering, volledigheid) with `toetstype = automatisch`. Each toets queries:
  - **begroting:** SUM(journaalposten.bedrag where programma = X AND boekjaar = 2026) + this.bedrag vs. `bookkeeping-bbv-compliance` budget lookup; if overshoot, create bevinding with bedrag_fout = overage.
  - **calculatie:** validate debit_total == credit_total; if imbalanced, uitkomst = voldoet_niet.
  - **valutering:** toetsdatum must be within boekjaar start/end; if outside, uitkomst = voldoet_niet.
  - **adressering:** both debit-account and credit-account FKs are valid + not archived; if missing, block journaalpost (status ≠ geplaatst).
  - **volledigheid:** required fields (bedrag, date, debit-acct, credit-acct, tegenpartij for AP/AR) are populated; if missing, uitkomst = voldoet_niet.
  - On completion: aggregate toetsen results; set `journaalpost.rechtmatigheid.status = getoetst` if all 5 = voldoet; else `in_behandeling`.

- [ ] Task 11: Implement automatic budget-overshoot notification: when begroting toets uitkomst = voldoet_niet, automatically send email to portefeuillehouder of affected programma with link to Bevindingen list and invitation to submit maatregel.

### Manual Toetsing & Workflow (REQ-RV-002, REQ-RV-008)

- [ ] Task 12: Implement manual toets creation (UI form or procest-integration): when user initiates or system auto-triggers a manual toets (europees_aanbesteden, staatssteun, voorwaarden, M&O), create record with `toetstype = handmatig`, `status = in_behandeling`. Validate onderbouwing >= 50 chars for voldoet_niet outcomes.

- [ ] Task 13: Implement procest workflow integration: on manual toets creation (REQ-RV-002), auto-create a procest task:
  - Assignee: Inkoopadviseur (for europees_aanbesteden / staatssteun) or Juridisch Medewerker (for voorwaarden / M&O); configurable per criterium.
  - Due date: heden + 10 werkdagen (configurable per administration).
  - Task template: "Toets [criterium] op journaalpost [amount EUR, date] van leverancier [name]."
  - On procest task completion: sync status to `rechtmatigheidstoets.status = getoetst`; capture uitkomst + onderbouwing from procest task fields.
  - On procest task escalation (overdue): send notificatie to portefeuillehouder + auditcommissie; escalation reason = "Toets [criterium] [bevindingsnummer] > 10 werkdagen openstaand."

- [ ] Task 14: Implement PO-level toetsing (REQ-RV-008): on `verplichtingenadministratie` PO creation, trigger begroting + europees_aanbesteden toetsen (same logic as journaalpost, but source = PO bedrag). Store PO-toets results. When factuur later matches PO ± 10%, inherit toets-results to factuur; if > 10% delta, re-toets with updated onderbouwing = "Factuur [amount] wijkt > 10% af van PO [po_amount]; re-toetsing vereist."

- [ ] Task 15: Implement high-value procurement signaling (REQ-RV-007): on journaalpost.create, if bedrag > EUR 50.000 for leveringen/diensten (or EUR 221.000 clustering threshold for Europees aanbesteden), auto-create handmatige toets `europees_aanbesteden` with procest task assignment. Threshold configurable in `lib/Settings/procurementSettings.json`.

- [ ] Task 16: Implement drempelbedragen clustering detection (REQ-RV-007): on journaalpost.create with leverancier BVD-nummer + boekjaar, query SUM(journaalposten.bedrag where leverancier_bvd = X AND boekjaar = Y) and compare vs. `lib/Settings/drempelbedragen.json` (2024-2025: 221k leveringen, 5.538M werken, 750k sociale diensten). If cluster crosses threshold, auto-create `europees_aanbesteden` toets with onderbouwing = "Clustering detected: [cumulative amount] > [threshold]."

- [ ] Task 17: Implement optional TenderNed integration (REQ-RV-007, optional): if `rechtmatigheidstoets.criterium = europees_aanbesteden` and `journaalpost.cpv_code` is populated, query OpenConnector–TenderNed for aanbestedingspublicaties matching CPV-code + supplier; return match summary for onderbouwing ("TenderNed publicatie [reference]"). If raamovereenkomst FK is provided, skip query (assume RO is already aanbesteed).

### Tolerantiegrens & Aggregation (REQ-RV-003, REQ-RV-005)

- [ ] Task 18: Implement automatic tolerantiegrens seeding: on administration first access or boekjaar open, check if `tolerantiegrens` record exists for boekjaar; if not, auto-create with fout_percentage = 3.0, onzekerheid_percentage = 1.0, status = concept.

- [ ] Task 19: Implement tolerance re-aggregation on raadsbesluit update (REQ-RV-003): when portefeuillehouder updates `tolerantiegrens` record (e.g., vastgesteld_bij_raadsbesluit changed), trigger `ScheduledAggregation` to recompute all rechtmatigheidsparagraaf aggregations for that boekjaar; log old vs. new tolerance % and re-evaluate within_tolerantie flags.

- [ ] Task 20: Implement rechtmatigheidsparagraaf aggregation (REQ-RV-005): create `ScheduledAggregation` task for boekjaar-einde (configurable date, default 31-Dec + 1 week). On trigger:
  - Query all `rechtmatigheidsbevinding` records where boekjaar = [year] AND status != opgelost.
  - SUM bedrag_fout + bedrag_onzekerheid.
  - Lookup `tolerantiegrens` record for boekjaar; compute bedrag thresholds (fout_percentage * totaal_lasten, etc).
  - If both sums within thresholds: `binnen_tolerantie = true`, use standaard college-verklaring text (template).
  - Else: `binnen_tolerantie = false`, use gewijzigde text citing overages; require portefeuillehouder Financiën to author toelichting before status can advance to vastgesteld_college.
  - Create paragraaf record with status = concept, bevindingen[] array, verklaring_college, audit-trail entry.
  - Send notificatie to college secretaris + auditcommissie: "Rechtmatigheidsparagraaf [year] ready for review."

### Audit Trail & Evidence (REQ-RV-004, REQ-RV-007)

- [ ] Task 21: Integrate OpenRegister audit-log (REQ-RV-004): ensure every mutation on `rechtmatigheidstoets`, `rechtmatigheidsbevinding`, `rechtmatigheidsparagraaf`, `tolerantiegrens` is automatically audit-logged by OpenRegister with: old_value, new_value, user_id, timestamp, mutation_reason. Audit-log is immutable and queryable.

- [ ] Task 22: Implement audit export endpoint (REQ-RV-004): create API endpoint `GET /api/rechtmatigheid/audit-export?criterium=[X]&boekjaar=[Y]&format=[csv|xbrl]` that returns:
  - CSV format: (id, journaalpost_id, criterium, uitkomst, toetsdatum, toetser, onderbouwing, bedrag_betrokken, bewijsstukken[], audit_mutations)
  - XBRL format: same data in signed XML envelope (optional DocuDesk signature for PAdES/XAdES).
  - Response is gzip-compressed if > 1MB; export completes within 30 seconds for 100k+ toetsen.
  - Audit-log entry: "Rechtmatigheid audit-export by [user] at [timestamp] for [criterium] [boekjaar]."

- [ ] Task 23: Implement bewijsstukken attachment via OpenRegister files (REQ-RV-002, REQ-RV-004): on `rechtmatigheidstoets`, enable `files-attached-to-object` extension; users can upload invoices, TenderNed PDFs, de-minimis-verklaringen, etc. Files are immutable; deletion is audit-logged.

### Dashboard & Reporting (REQ-RV-009)

- [ ] Task 24: Implement rechtsmatigheid dashboard (REQ-RV-009): create manifest navigation entry `Rechtmatigheidstoetsing > Mijn Programma's` (detail page). Display:
  - Openstaande bevindingen per programma (list: bevindingsnummer, bedrag, soort, oorzaak, status).
  - YTD fouten/onzekerheden SUM vs. tolerantiegrens gauge (progress-bar, % fill).
  - Top 5 risicovolle leveranciers (leverancier name, YTD bedrag, # europees_aanbesteden toetsen in_behandeling, clustering status).
  - Notification: "N europees_aanbesteden toetsen vereisten afronding" (link to Procest tasks or Bevindingen list).
  - Auto-refresh every 5 minutes.

- [ ] Task 25: Implement bevindingen list view: manifest entry `Rechtmatigheid > Bevindingen` (index page). Display:
  - Filterable table: boekjaar, soort (fout|onzekerheid), criterium, bedrag (sortable), status, portefeuillehouder, meldingsdatum.
  - Drill-down: click bevinding to see all linked toetsen + audit-trail mutations.
  - Action: assign to portefeuillehouder, mark as opgenomen_in_paragraaf, link correctieboeking, export to procest task.

- [ ] Task 26: Implement tolerantiegrens admin page: manifest entry `Rechtmatigheid > Toleranties` (admin-only). Display:
  - Table: boekjaar, fout_%, onzekerheid_%, raadsbesluit, vastgesteld_op, status.
  - Action: edit tolerantie (triggers re-aggregation on save), upload raadsbesluit PDF (optional).

- [ ] Task 27: Implement rechtmatigheidsparagraaf detail page: manifest entry `Rechtmatigheid > Rechtmatigheidsparagraaf` (detail per boekjaar). Display:
  - Paragraph heading: "Rechtmatigheidsverantwoording Boekjaar [Y]"
  - Summary: totaal fouten/onzekerheden, toleranties, within_tolerance status.
  - College verklaring (read-only text, editable by portefeuillehouder Financiën if status = concept).
  - Bevindingenlijst (sortable, filterable).
  - Status workflow: concept → vastgesteld_college (college-besluit link, date) → behandeld_raad (raad-behandeling link, date) → definitief (export date).
  - Action: download paragraaf as PDF (via docudesk), approve for jaarrekening export (status: definitief).

- [ ] Task 28: Implement quarterly report export (REQ-RV-009): create endpoint `GET /api/rechtmatigheid/quarterly-report?quarter=[Q]&year=[Y]&format=[pdf]` that generates PDF with:
  - Cover: "Kwartaalrapportage Rechtmatigheid Q[Q] [Y]"
  - Summary per programma: fouten/onzekerheden YTD, tolerance status.
  - All bevindingen > EUR 25.000 (list: bevindingsnummer, soort, bedrag, oorzaak, maatregel, status).
  - Trend chart (4-quarter history): fouten/onzekerheden per quarter.
  - Top risico's (leveranciers / procurementstromen with most bevindingen).
  - Audit trail summary (# toets-mutations, avg resolution-time).
  - Optional: auto-email to auditcommissie (configurable per administration).

### Jaarrekening Export (REQ-RV-006)

- [ ] Task 29: Integrate rechtmatigheidsparagraaf into jaarrekening export (REQ-RV-006): extend `bookkeeping-financial-statements` module to consume finalized `rechtmatigheidsparagraaf` (status = definitief) and include in jaarrekening bundle:
  - XBRL IV3 element: map paragraaf fields to CBS DDM definitions (rechtmatigheid_fouten, rechtmatigheid_onzekerheden, rechtmatigheid_verklaring, etc.).
  - PDF bijlage: render paragraaf + bevindingenlijst via docudesk (template: "Bijlage X: Rechtmatigheidsverantwoording Boekjaar [Y]"), optionally digitally signed by college-ondertekening.
  - If paragraaf status ≠ definitief: export fails with error message "Rechtmatigheidsparagraaf [year] nog niet vastgesteld. Voltooi college-vaststelling alvorens export."

### i18n & Documentation (Company-wide ADR-005 & ADR-010)

- [ ] Task 30: Implement Dutch (nl_NL) and English (en_US) translation strings (i18n, per ADR-005) for:
  - UI labels: "Rechtmatigheidstoetsing", "Bevindingen", "Toleranties", "Rechtmatigheidsparagraaf", "Audit Export", "Toets", "Toetser", "Onderbouwing", "Bewijsstuk(ken)", "Bevindingsnummer", "Programma", "Oorzaak", "Maatregel", "Kwartaalrapportage", "Binnen tolerantie", "Buiten tolerantie", etc.
  - Status enums: "Voldoet", "Voldoet niet", "Onzeker", "Niet van toepassing", "In behandeling", "Getoetst", "Opgenomen in paragraaf", "Opgelost", "Open", "Vastgesteld college", "Behandeld raad", "Definitief".
  - Criterium enums: "Begroting", "Voorwaarden", "Misbruik & oneigenlijk gebruik", "Calculatie", "Valutering", "Adressering", "Volledigheid", "Aanvaardbaarheid", "Europees aanbesteden", "Staatssteun".
  - Soort enums: "Fout", "Onzekerheid".
  - Error messages: "Onderbouwing moet minimaal 50 tekens bevatten.", "Rechtmatigheidsparagraaf nog niet vastgesteld.", "Toets kan niet afgerond worden zonder bewijsstukken.", etc.

- [ ] Task 31: Author user-facing documentation (per ADR-010, journeydoc convention): create `docs/user-guide/bookkeeping/rechtmatigheidsverantwoording.md` with:
  - Overview: BBV artikel 17a requirement, nine criteria, tolerance thresholds.
  - Step-by-step: automatic toetsing on journaalpost creation, manual toetsing workflow (procest task assignment), bevinding tracking, boekjaar-einde aggregation, paragraaf vaststellung by college, jaarrekening export.
  - Screenshots: dashboard, bevindingen list, tolerantiegrens editor, paragraaf detail, quarterly report.
  - FAQ: "What is clustering detection?", "How do I attach evidence (bewijsstukken)?", "Can I change a toets outcome?", "What if my fouten exceed tolerance?", etc.
  - Appendix A: Nine criteria definitions (with Dutch legal references to BBV, BADO, etc.).
  - Appendix B: Drempelbedragen table (2024-2025, with EU regulation references).

### Compliance & Testing (Company-wide ADR-009)

- [ ] Task 32: Implement PHPUnit unit tests (per ADR-009) for:
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

- [ ] Task 33: Implement Playwright MCP browser tests (per ADR-009) for:
  - Dashboard render: openstaande bevindingen, YTD totals, top risico's display within 3 seconds.
  - Bevindingen list: filter, sort, drill-down to detail.
  - Manual toets UI form: onderbouwing min-length validation, bewijsstukken upload, procest task auto-creation verification.
  - Tolerantiegrens admin: edit + save triggers re-aggregation (verify paragraaf totals update).
  - Rechtmatigheidsparagraaf detail: college-verklaring display (standaard vs. gewijzigde), status workflow buttons (vastgesteld_college → raad), download PDF link.
  - Quarterly report export: PDF generation within 30 seconds, content correctness (fouten/onzekerheden sums, trend chart).
  - Audit export endpoint: CSV / XBRL download, verify 100k+ toetsen in < 30 seconds.
  - Manifest navigation: all 5 entries load, correct user role permissions (portefeuillehouder sees only own programma's).
  - i18n: UI labels + messages display correctly in nl_NL and en_US.

- [ ] Task 34: Verify `composer test` and Playwright MCP tests exit 0 at PR CI gate.

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
