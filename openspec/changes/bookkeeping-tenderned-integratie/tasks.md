# Tasks — TenderNed Integratie

> **Spec-driven change.** Per `proposal.md` Scope, the tasks below describe the work an `opsx-apply` cycle will execute against the spec delta. This change does NOT include implementation code; only the spec and seed data are delivered here. PHP code, Vue components, and manifest entries are implementation-cycle work (post-merge). The tasks are recorded now so spec review, dependency planning, and tier-cascade impact are visible at proposal time.

## 0. Deduplication & Dependency Check

- [ ] Task 0.1: Confirm no TenderNed integration or `TenderNedAanbesteding` schema already exists — scan `lib/Settings/shillinq_register.json`, existing OpenSpec specs, and cross-app dependencies (openconnector, mydash); verify openconnector's TenderNed source is stable (no pending API breaking changes)
- [ ] Task 0.2: Confirm openconnector's TenderNed source and polling job are production-ready — check openconnector's release notes, test polling with 5+ live TenderNed dossiers, verify CloudEvent schema matches this spec's expectations

## 1. Spec foundation (this change)

- [x] Task 1.1: Author `specs/bookkeeping-tenderned-integratie/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: cross-cutting integration` / `Depends on: obligation-financial-administration (T2)` header; include all 8 REQ-NNN requirements with GIVEN/WHEN/THEN acceptance criteria
- [x] Task 1.2: Author `proposal.md` with Affected Projects, Scope (In/Out), Constraints, Rollback Plan, Open Questions, and Risks sections; include ADR references (ADR-022, ADR-024, ADR-031)
- [x] Task 1.3: Author `design.md` with Goals, Non-Goals, Decisions (D1–D7), Reuse Analysis, Seed Data, Validation Rules, Open Questions (Q1–Q4), Integration Points, Security, Rollback; include data-model diagrams or tables for clarity
- [x] Task 1.4: Author `tasks.md` (this document) with deduplication checks, spec tasks, register/seed tasks, manifest tasks, and verification steps

## 2. Register declarations — `lib/Settings/shillinq_register.json`

- [ ] Task 2.1: Declare the `TenderNedAanbesteding` schema with all fields per spec (aanbestedingId, tenderNedUrl, titel, beschrijving, cpvCodes, aanbestedendeDienst, gunningsDatum, contractWaarde, looptijdStart, looptijdEind, gegundeLeverancier, status, verplichtingId); set aanbestedingId as unique key; add `x-openregister-relations` FK to Verplichting; add `x-openregister-lifecycle` state machine: `open → gegund → in-uitvoering → afgerond / beëindigd` per spec's status enum
- [ ] Task 2.2: Declare the `OpdrachtUitvoering` schema with all fields per spec (verplichtingId, mijlpaalId, opleveringsDatum, opleveringsType, goedgekeurd, goedkeurder, bewijsstukken); add `x-openregister-lifecycle` rule: `goedgekeurd` can only transition `false → true` if bewijsstukken.length > 0 (enforce per REQ-004); add FK relations to Verplichting and milestone
- [ ] Task 2.3: Extend the `Verplichting` schema (existing T2 entity) to add three fields: `bron` (enum: manual/tenderned/inkooporder, default: manual), `bronReferentie` (string, nullable, conditional-required if bron=tenderned), and `mijlpalen` (array of Mijlpaal objects with datum, omschrijving, percentage, status, factuurnummer). Use `x-openregister-conditional-required` for the bronReferentie conditional. Ensure backward compatibility: existing obligations default to `bron: manual`
- [ ] Task 2.4: Declare the `Mijlpaal` object schema (nested in Verplichting) with fields: mijlpaalId, datum, omschrijving, percentage (0–100), status (enum: planned/in-progress/completed/cancelled), factuurnummer (nullable). No FK; this is a value object, not a separate register.

## 3. Seed data — `lib/Settings/seeds/`

- [ ] Task 3.1: Ship `lib/Settings/seeds/milestone-templates.json` with templates for three opdrachttype variants:
  - `levering-in-fases` (phased delivery): 4 quarterly milestones (Q1–Q4, 25% each, days 90/180/270/360)
  - `dienstverlening-doorlopend` (recurring service): 12 monthly milestones (months 1–12, 8.33% each, days 30/60/90/…/360)
  - `other` (fallback): 2 milestones (50% at midpoint, 50% at end)
  Include `_meta` block with source, variant, description. Use Dutch labels. SPDX header: EUPL-1.2 + Copyright Conduction B.V.
- [ ] Task 3.2: Ship `lib/Settings/seeds/sample-tenderned-aanbiedingen.json` with 3–5 sample TenderNed tender records (for integration tests, not production auto-import):
  - Sample 1: Gemeente Utrecht, schoonmaak, €50,000, 1 year, dienstverlening-doorlopend
  - Sample 2: Provincie Brabant, IT services, €250,000, 2 years, levering-in-fases
  - Sample 3: MKB supplier test data (leverancier view)
  These are loaded by test fixtures; NOT auto-imported into live databases
- [ ] Task 3.3: Extend the repair step (`lib/Migration/TenderNedIntegrationRepairStep.php`) to:
  - Idempotently load milestone templates from seeds (check if already loaded)
  - NOT auto-import real TenderNed tenders (defer to REQ-001 manual trigger or REQ-002 polling)
  - Allow operator to select a default milestone template during onboarding
  - Record migration execution in audit-trail (system user, timestamp, action: init-tenderned-integration)

## 4. Manifest navigation — `src/manifest.json`

- [ ] Task 4.1: Add Procurement navigation > TenderNed Aanbestedingen:
  - Menu entry: `{ title: "TenderNed Aanbestedingen", path: "/procurement/tenderned", icon: "trending_up" }`
  - Index page: `type: index` binding to `TenderNedAanbesteding` register (table: aanbestedingId, titel, gunningsDatum, contractWaarde, status, gegundeLeverancier)
  - Detail page: `type: detail` for individual dossier (summary, linked Verplichting, status timeline)
  - Filter/sort by: status, gunningsDatum, contractWaarde
  - Per REQ-001 requirements; test manifest validation via `npm test src/manifest.json`
- [ ] Task 4.2: Add Procurement navigation > Mijn Contracten (vendor-only view):
  - Visibility: Only shown if organization.role == "vendor" (inschrijver)
  - Menu entry: `{ title: "Mijn Contracten", path: "/procurement/my-contracts", icon: "card_giftcard" }`
  - Index page: lists all Verplichtingen where `bron: tenderned` and `gegundeLeverancier.kvkNumber == organization.kvkNumber` (RBAC-filtered at query layer)
  - Detail page: shows contract value, milestones, payment forecast, and linked bewijsstukken
  - Per REQ-008 requirements (cashflow forecast)
- [ ] Task 4.3: Extend Obligation detail page (existing T2 navigation) to surface TenderNed fields:
  - If `bron: tenderned`, display collapsible panel: "TenderNed Dossier" with fields: aanbestedingId (clickable to TenderNed), bronReferentie, gegundeLeverancier, contractWaarde, looptijdStart/End
  - Show Mijlpalen table (datum, omschrijving, percentage, status, factuurnummer)
  - If role == aanbestedende dienst: show "Complete Milestone" button (triggers REQ-004 bewijsstuk validation, REQ-006 status-sync)
  - Per REQ-004 and REQ-006 workflows

## 5. CloudEvent integration — `lib/Events/`

- [ ] Task 5.1: Declare CloudEvent listeners in `lib/Events/TenderNedPollingListener.php`:
  - Listen for `tenderned.award.detected` event emitted by openconnector
  - Parse event payload: aanbestedingId, status, gegundeLeverancier, contractWaarde, gunningsDatum
  - Trigger REQ-002 auto-promotion logic (idempotent Verplichting creation/activation)
  - Log CloudEvent processing in audit-trail (action: tenderned-award-detected, success/fail, dossierReference)
- [ ] Task 5.2: Declare CloudEvent emitters in `lib/Events/ObligationEventPublisher.php`:
  - On Verplichting activation (status: concept → active), emit `obligation.activated` event with payload: contractWaarde, period (FY), kostenplaats, tenderNedDossierUrl
  - Per REQ-007 budget-widget SLA; mydash subscribes to this event
- [ ] Task 5.3: Declare CloudEvent for milestone completion:
  - On OpdrachtUitvoering completion (status: in-progress → completed), emit `milestone.completed` event with payload: verplichtingId, mijlpaalId, factuurNummeringEligible (boolean)
  - Consumed by accounting module for invoice-creation triggers

## 6. Status-sync to TenderNed — `lib/Integrations/TenderNedStatusSync.php`

- [ ] Task 6.1: Author integration service that calls openconnector's TenderNed source API:
  - Method: `syncStatusToTenderNed(string $aanbestedingId, string $newStatus): bool`
  - Route: openconnector's TenderNed source (via event emitter, not direct HTTP)
  - Only callable if organization.role == "aanbestedende dienst" (enforced at controller level)
  - REQ-006: Triggered when final milestone is marked complete
  - Graceful degradation: if sync fails, log warning but don't fail the milestone-completion
  - Audit-trail entry: action: status-sync-initiated, target: tenderned, status: afgerond, success: true/false

## 7. Audit-trail & RBAC enforcement

- [ ] Task 7.1: Define RBAC roles for TenderNed operations (in `lib/Security/Roles.php` or manifest):
  - `tenderned:import` — allows REQ-001 manual import (contractmanager role)
  - `tenderned:view` — allows viewing TenderNed records (all users with procurement access)
  - `tenderned:activate` — allows promoting concept → active (contractmanager role)
  - `tenderned:sync-status` — allows REQ-006 status-sync back to TenderNed (only aanbestedende dienst / inkoper role)
  - `verplichting:edit-milestones` — allows editing milestone plans before confirmation (contractmanager role)
- [ ] Task 7.2: Enforce RBAC on all TenderNed endpoints (register CRUD via manifest):
  - Read (TenderNedAanbesteding): all users with `tenderned:view`
  - Create (manual import, REQ-001): users with `tenderned:import`
  - Update (activate, REQ-002): users with `tenderned:activate`
  - Delete: not supported (audit-trail must be immutable)
  - Status-sync (REQ-006): only users with `tenderned:sync-status` role
- [ ] Task 7.3: Verify OR's audit-trail captures all mutations:
  - Confirm `x-openregister-audit-trail` is enabled on TenderNedAanbesteding, Verplichting, OpdrachtUitvoering
  - Test that all state transitions (REQ-005) are logged with: timestamp, user, action, oldValue, newValue, dossierReference
  - Write a query test: auditor retrieves full chain for a sample obligation in <10 seconds

## 8. Validation & Guard Rules

- [ ] Task 8.1: Declare bewijsstuk-enforcement rule (REQ-004) via `x-openregister-lifecycle.requires`:
  - OpdrachtUitvoering.goedgekeurd can only transition `false → true` if `bewijsstukken.length > 0`
  - If rule violation, return HTTP 422 with Dutch error message: "Voeg minimaal één bewijsstuk toe voordat u de oplevering als voltooid markeert."
  - Test with 3 scenarios: no files (rejection), 1 file (success), 1 file deleted then attempt (rejection)
- [ ] Task 8.2: Declare milestone-percentage validation (spec design notes):
  - Sum of all mijlpalen[*].percentage MUST equal 100% (or less if explicitly marked as partial-contract)
  - If sum != 100%, warn contractmanager but allow save (validation: warning, not error)
  - Test with templates: 4×25%, 12×8.33%, 2×50% (all pass)
- [ ] Task 8.3: Declare date-range validation:
  - Each mijlpaal.datum MUST be between Verplichting.looptijdStart and looptijdEind
  - If out of range, validation error, prevent save
  - Test edge cases: milestone on day 0 (start date), milestone on day-of-end (end date), after end date (rejected)

## 9. API Endpoints (if custom beyond register CRUD)

- [ ] Task 9.1: Endpoint POST `/api/v1/procurement/tenderned/import` (REQ-001 manual import):
  - Body: `{ aanbestedingId: string }`
  - Response: created Verplichting (status: concept) with full details
  - Error handling: invalid aanbestedingId (HTTP 400), TenderNed API unreachable (HTTP 503), already imported (HTTP 409 with existing Verplichting URI)
  - Docs: REQ-001 acceptance criteria
- [ ] Task 9.2: Endpoint PATCH `/api/v1/procurement/verplichting/{id}/activate` (REQ-002 + REQ-003 workflow):
  - Body: `{ kostenplaats: string, grootboekrekening: string, opdrachttype?: string }`
  - Side effects: status: concept → active, generate milestone plan if opdrachttype provided, emit CloudEvent
  - Response: updated Verplichting with mijlpalen populated
  - Auth: `tenderned:activate` role
- [ ] Task 9.3: Endpoint PATCH `/api/v1/procurement/opdrachtuitvoering/{id}/complete` (REQ-004):
  - Body: (none; implicit: status: in-progress → completed)
  - Validation: bewijsstukken.length > 0 (per REQ-004)
  - Side effects: if this is the final milestone, emit status-sync request (REQ-006)
  - Response: updated OpdrachtUitvoering
  - Auth: `verplichting:edit-milestones` role
- [ ] Task 9.4: Endpoint GET `/api/v1/procurement/cashflow-forecast` (REQ-008 vendor dashboard):
  - Query params: `?organization=<kvkNumber>&period=<YYYY-MM-DD_to_YYYY-MM-DD>`
  - Response: array of revenue-forecast entries (one per Verplichting, distributed across milestones)
  - Filter: only Verplichtingen where gegundeLeverancier.kvkNumber matches organization and bron=tenderned
  - Auth: `tenderned:view` role

## 10. Testing Strategy (Company ADR-009)

- [ ] Task 10.1: PHPUnit tests for new schemas and business logic:
  - `tests/Unit/Schemas/TenderNedAanbestedingTest.php` — schema validation, enum constraints, FK relations
  - `tests/Unit/Schemas/OpdrachtUitvoeringTest.php` — bewijsstuk-enforcement rule, status-transition logic
  - `tests/Unit/Events/TenderNedPollingListenerTest.php` — CloudEvent parsing, REQ-002 auto-promotion logic, idempotency
  - `tests/Unit/Integrations/TenderNedStatusSyncTest.php` — REQ-006 status-sync to TenderNed, auth checks, error handling
  - Coverage: >85% on new business logic

- [ ] Task 10.2: Integration tests (openconnector + shillinq):
  - `tests/Integration/TenderNedPollingTest.php` — mock openconnector CloudEvents, verify Verplichting creation/activation
  - `tests/Integration/MilestoneGenerationTest.php` — REQ-003 milestone-plan generation for all opdrachttype templates
  - `tests/Integration/BewijsstukEnforcementTest.php` — REQ-004 validation (bewijsstuk required before completion)
  - Tests use sample data from `lib/Settings/seeds/sample-tenderned-aanbiedingen.json`

- [ ] Task 10.3: API endpoint tests (Newman/Postman or PHPUnit):
  - POST /api/v1/procurement/tenderned/import — REQ-001 manual import happy path + error cases
  - PATCH /api/v1/procurement/verplichting/{id}/activate — REQ-002 + REQ-003 enrichment workflow
  - PATCH /api/v1/procurement/opdrachtuitvoering/{id}/complete — REQ-004 validation + REQ-006 sync
  - GET /api/v1/procurement/cashflow-forecast — REQ-008 vendor forecast aggregation
  - All endpoints tested with auth (role-based access, bearer token)

- [ ] Task 10.4: UI/browser tests (Playwright or equivalent per ADR-009):
  - Scenario 1 test: Import TenderNed dossier, enrich, activate, see budget-widget update (60s SLA)
  - Scenario 2 test: Auto-promotion on polling (mock polling job, verify notification + obligation creation)
  - Scenario 3 test: Vendor views cashflow forecast for their won contracts
  - Scenario 4 test: Upload bewijsstuk, mark milestone complete, see status-sync event (if final milestone)
  - Scenario 5 test: ENSIA auditor queries audit-trail, traces obligation back to TenderNed dossier

- [ ] Task 10.5: Performance tests:
  - Milestone-generation speed: REQ-003 SLA of 3s for 10-year contract (120 milestones)
  - Budget-widget update SLA: REQ-007 60-second SLA (measure CloudEvent emit → widget re-render, with 10 concurrent imports)
  - Audit-trail query speed: REQ-005 <10 seconds to retrieve full obligation chain
  - Load test: 500+ active obligations in a gemeente instance, verify no register-query regressions

- [ ] Task 10.6: Compliance & audit tests:
  - Verify RBAC: `tenderned:import` can import, `tenderned:sync-status` can sync, `vendor` cannot sync
  - Verify audit-trail immutability: attempt to edit/delete an audit entry (should fail or not be exposed)
  - Verify data isolation: vendor sees only own contracts (kvkNumber filtering)
  - Verify status-sync auth: only aanbestedende dienst can sync status back to TenderNed

- [ ] Task 10.7: All tests pass: `composer test` exits 0 with no warnings/skipped tests

## 11. Documentation (Company ADR-010)

- [ ] Task 11.1: Feature documentation — `docs/user-guide/procurement/tenderned-integratie/`:
  - `index.md` — overview, manual import flow, auto-detection, roles
  - `import-dossier.md` — step-by-step guide for REQ-001 (contractmanager)
  - `milestone-planning.md` — REQ-003 template selection, editing, confirmation
  - `proof-of-delivery.md` — REQ-004 uploading bewijsstukken, field requirements
  - `vendor-cashflow.md` — REQ-008 forecast for MKB suppliers
  - `audit-trail.md` — REQ-005 ENSIA auditor guide, tracing lineage

- [ ] Task 11.2: API documentation (OpenAPI 3.0):
  - Document all endpoints: POST /tenderned/import, PATCH /verplichting/{id}/activate, etc.
  - Include request/response schemas, error codes, auth requirements
  - Use OpenAPI tooling to generate swagger UI in `/docs/api/`

- [ ] Task 11.3: Screenshots & diagrams:
  - Screenshot 1: TenderNed import form (REQ-001)
  - Screenshot 2: Obligation detail with milestone plan (REQ-003)
  - Screenshot 3: Bewijsstuk upload dialog (REQ-004)
  - Screenshot 4: Vendor cashflow-forecast dashboard (REQ-008)
  - Diagram: CloudEvent flow (openconnector → shillinq → mydash, REQ-007)
  - Commit all screenshots to `docs/images/procurement/`

## 12. i18n (Company ADR-007)

- [ ] Task 12.1: Dutch (`nl_NL`) translation strings — required terms:
  - UI labels: `Aanbestedingen`, `TenderNed Dossier`, `Mijn Contracten`, `Mijlpalen`, `Oplevering`, `Bewijsstuk`, `Goedgekeurd`, `Gegunde Leverancier`, `Contractwaarde`
  - Error messages: `Voeg minimaal één bewijsstuk toe voordat u de oplevering als voltooid markeert`, `Ongeldige TenderNed ID`, `Status-sync naar TenderNed niet verzonden (herprob over 5 min)`
  - Button labels: `Importeer Dossier`, `Activeer Verplichting`, `Markeer als Voltooid`, `Sync naar TenderNed`
  - Notification: `TenderNed award detected: [titel], €[waarde], awarded to [leverancier]`

- [ ] Task 12.2: English (`en_US`) translation strings (all above terms)

- [ ] Task 12.3: Verify i18n strings in code (use `$this->trans()` or translation JSON keys, not hardcoded strings)
  - Scan new files for translatable strings; add keys to `translations/` directory
  - Test locale switching: UI renders correctly in both NL and EN

## 13. ADR updates

- [ ] Task 13.1: Update `openspec/architecture/adr-000-data-model.md`:
  - Add reconciliation note to `Verplichting` section: "Extended in bookkeeping-tenderned-integratie with bron, bronReferentie, mijlpalen fields. Backward compatible: existing obligations default to bron=manual."
  - Add new entries for `TenderNedAanbesteding`, `OpdrachtUitvoering`, `Mijlpaal` (nested object)
  - Verify 225-entity count is updated (was 225, now ≥228 with new entries)

- [ ] Task 13.2: Verify ADR-022, ADR-024, ADR-031 alignment:
  - ADR-022 (apps consume OR abstractions): this change uses audit-trail-immutable, file-attachments, RBAC — verify no app-local reimplementation ✓
  - ADR-024 (manifest shape): navigation entries and detail pages declared in manifest.json ✓
  - ADR-031 (declarative business logic): bewijsstuk enforcement and state machines via `x-openregister-lifecycle` ✓

## 14. Verification & Sign-Off

- [ ] All Section 1–13 tasks checked off
- [ ] `openspec validate` command exits clean on the change folder (syntax, schema references, dependency graph)
- [ ] Spec review by procurement domain expert (e.g., contractmanager persona, test-persona-janwillem)
- [ ] Spec review by compliance/ENSIA representative (audit-trail, data isolation, status-sync)
- [ ] Architecture review: ADR-022 + ADR-024 + ADR-031 alignment confirmed
- [ ] Cross-app dependency review: openconnector (TenderNed source ready), mydash (event listener ready), docudesk (file-attachment API stable)
- [ ] Security review: RBAC roles defined, data isolation enforced, no sensitive credentials in code
- [ ] All tests pass (`composer test` exits 0)
- [ ] Documentation complete and proofread (Dutch spelling, UI consistency)
- [ ] Rollback plan tested (disable polling, revert schemas, restore Verplichting, no data loss)

---

## Handoff to Implementation (opsx-apply cycle)

Once all verification checks pass, this spec and seed data are merged into the `openspec/changes/bookkeeping-tenderned-integratie/` folder. The `opsx-apply` cycle will:

1. Use the spec as the source-of-truth contract
2. Implement PHP services, Vue components, and manifest entries per the spec and tasks above
3. Ensure all tests in Task 10 pass
4. Verify all acceptance criteria in each REQ-NNN are met
5. Produce a feature-ready PR for review, with CI gates enforcing architecture conformance (ADR-022/024/031)

The spec itself does NOT ship any implementation code. The tasks above describe what the implementation cycle must deliver.
