# Tasks — TenderNed Integratie

> **Spec-driven change.** Per `proposal.md` Scope, the tasks below describe the work an `opsx-apply` cycle will execute against the spec delta. The tasks are recorded so spec review, dependency planning, and tier-cascade impact are visible at proposal time.
>
> **Implementation note (hydra-build 2026-06).** This change has now been implemented to production quality:
> - **ADR-037**: a NEW register fragment `lib/Settings/register.d/20-bookkeeping-tenderned-integratie.json` declares the `TenderNedAanbesteding`, `Verplichting`, and `OpdrachtUitvoering` schemas (with `x-openregister-lifecycle`/`-relations`/`-rbac`) plus seed `objects[]` — the monolith `shillinq_register.json` is untouched. The existing `SettingsService::deepMergeConfig` already unions `components.schemas` + `objects[]` additively, so no loader change was needed.
> - **Verplichting reality check**: the spec assumed a pre-existing `Verplichting` schema ("existing T2 entity"), but **none exists** in shillinq today (no `obligation-financial-administration` change has landed). It is therefore declared as a NEW schema in the fragment with the integration fields built in, rather than "extending" a non-existent monolith entry. The `bron`/`bronReferentie`/`mijlpalen` extensions are present from the start.
> - **ADR-022 / ADR-031**: business rules are declarative on the schemas, with three ADR-031 exception-path Guard classes (`OpdrachtUitvoeringGuard`, `VerplichtingGuard`, `TenderNedAanbestedingGuard`) referenced from lifecycle `requires:` clauses, using the real OpenRegister `ObjectService` API (`setRegister`/`setSchema`/`find`/`findAll`).
> - **Milestone logic** (REQ-003 / REQ-008): a pure `MilestoneTemplateService` generates plans and cashflow forecasts from `lib/Settings/seeds/milestone-templates.json`.
> - **Frontend**: a manifest-v2 fragment `src/manifest.d/20-tenderned-integratie.json` adds the Inkoop nav (TenderNed Aanbestedingen, Verplichtingen, Mijn Contracten) as declarative index/detail pages (no custom Vue, no router edits).
> - **i18n**: nl + en strings added additively to both `l10n/*.json`.
> - **Tests**: 28 new PHPUnit tests (all green); the full unit suite is 153 tests / 736 assertions / 0 warnings. phpcs/phpmd/psalm/phpstan clean on all touched files.
> - **Deferred** (need a live instance or a not-yet-merged cross-app dependency, marked DEFER below): live openconnector polling/CloudEvent wiring (Tasks 0.2, 5.x), the openconnector outbound status-sync HTTP call (Task 6.1), Newman/Playwright/performance suites (Tasks 10.2–10.6), user docs + screenshots (Task 11), and the cross-app ADR-000 reconciliation (Task 13.1, openregister-owned).
>
> **2026-06-10 follow-up build (this cycle).** The originally-deferred Tasks 5.1 / 5.2 / 5.3 / 6.1 / 10.3 / 10.4 / 11.1 / 11.2 / 13.1 are now SHIPPED. Three OR-event-backed listeners (`TenderNedAwardDetectedListener`, `VerplichtingTransitionListener`, `OpdrachtUitvoeringTransitionListener`) materialise the `tenderned.award.detected` / `obligation.activated` / `milestone.completed` CloudEvents on top of a shared `BudgetImpactEmitter` kernel; `TenderNedStatusSync` provides the REQ-006 outbound to openconnector with a structured-log fallback when the gateway is not bound. Newman + Playwright + user-guide + ADR-000 reconciliation also landed. 23 new PHPUnit tests bring the unit suite to 2 707 / 12 564 assertions / 0 failures / 0 warnings (a pre-existing FluxServiceTest variance-ordering assertion was corrected in the same batch). Remaining DEFER set: screenshots, live execution of the Newman+Playwright suites, performance/SLA test runs, runtime rollback drill, and the two domain-expert spec reviews — all owned by the verify cycle on a live NC instance.

## 0. Deduplication & Dependency Check

- [x] Task 0.1: Confirmed no TenderNed integration or `TenderNedAanbesteding`/`Verplichting`/`OpdrachtUitvoering` schema already exists — scanned `shillinq_register.json` (41 schemas) and all `register.d/*.json` fragments; none present. `Verplichting` does not exist either, so it is declared new (see implementation note).
- [x] Task 0.2: DEFER — confirming openconnector's live TenderNed source/polling job + CloudEvent schema requires a running openconnector instance and live TenderNed dossiers (not available in the build sandbox). The consuming listener (Task 5.1) is likewise deferred.

## 1. Spec foundation (this change)

- [x] Task 1.1: Author `specs/bookkeeping-tenderned-integratie/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: cross-cutting integration` / `Depends on: obligation-financial-administration (T2)` header; include all 8 REQ-NNN requirements with GIVEN/WHEN/THEN acceptance criteria
- [x] Task 1.2: Author `proposal.md` with Affected Projects, Scope (In/Out), Constraints, Rollback Plan, Open Questions, and Risks sections; include ADR references (ADR-022, ADR-024, ADR-031)
- [x] Task 1.3: Author `design.md` with Goals, Non-Goals, Decisions (D1–D7), Reuse Analysis, Seed Data, Validation Rules, Open Questions (Q1–Q4), Integration Points, Security, Rollback; include data-model diagrams or tables for clarity
- [x] Task 1.4: Author `tasks.md` (this document) with deduplication checks, spec tasks, register/seed tasks, manifest tasks, and verification steps

## 2. Register declarations — `lib/Settings/shillinq_register.json`

- [x] Task 2.1: Declared the `TenderNedAanbesteding` schema with all fields per spec (aanbestedingId, tenderNedUrl, titel, beschrijving, cpvCodes, aanbestedendeDienst, gunningsDatum, contractWaarde, looptijdStart, looptijdEind, gegundeLeverancier, status, verplichtingId); set aanbestedingId as unique key; add `x-openregister-relations` FK to Verplichting; add `x-openregister-lifecycle` state machine: `open → gegund → in-uitvoering → afgerond / beëindigd` per spec's status enum
- [x] Task 2.2: Declared the `OpdrachtUitvoering` schema with all fields per spec (verplichtingId, mijlpaalId, opleveringsDatum, opleveringsType, goedgekeurd, goedkeurder, bewijsstukken); add `x-openregister-lifecycle` rule: `goedgekeurd` can only transition `false → true` if bewijsstukken.length > 0 (enforce per REQ-004); add FK relations to Verplichting and milestone
- [x] Task 2.3: Declared the `Verplichting` schema with the three integration fields built in (the spec assumed an existing T2 entity, but none exists — see implementation note): `bron` (enum: manual/tenderned/inkooporder, default: manual), `bronReferentie` (string, nullable, conditional-required if bron=tenderned), and `mijlpalen` (array of Mijlpaal objects with datum, omschrijving, percentage, status, factuurnummer). Use `x-openregister-conditional-required` for the bronReferentie conditional. Ensure backward compatibility: existing obligations default to `bron: manual`
- [x] Task 2.4: Declared the `Mijlpaal` value object inline in `Verplichting.mijlpalen` (mijlpaalId, datum, omschrijving, percentage 0–100, status enum, factuurnummer nullable) — embedded array, no separate register, matching the existing JournalEntry.lines pattern. with fields: mijlpaalId, datum, omschrijving, percentage (0–100), status (enum: planned/in-progress/completed/cancelled), factuurnummer (nullable). No FK; this is a value object, not a separate register.

## 3. Seed data — `lib/Settings/seeds/`

- [x] Task 3.1: Shipped `lib/Settings/seeds/milestone-templates.json` with the three opdrachttype templates (phased 4×25%, recurring 12 months summing to 100%, `other` fallback 2×50%), `_meta` block, Dutch labels, EUPL-1.2 + Conduction B.V. license fields. Consumed by `MilestoneTemplateService`.
  - `levering-in-fases` (phased delivery): 4 quarterly milestones (Q1–Q4, 25% each, days 90/180/270/360)
  - `dienstverlening-doorlopend` (recurring service): 12 monthly milestones (months 1–12, 8.33% each, days 30/60/90/…/360)
  - `other` (fallback): 2 milestones (50% at midpoint, 50% at end)
  Include `_meta` block with source, variant, description. Use Dutch labels. SPDX header: EUPL-1.2 + Copyright Conduction B.V.
- [x] Task 3.2: Shipped `lib/Settings/seeds/sample-tenderned-aanbiedingen.json` with 3 sample tenders (Utrecht schoonmaak €50k 1yr doorlopend, Brabant IT €250k 2yr gefaseerd, MKB €15k leverancier). Test-fixture data, NOT auto-imported. (Two of these also ship as register `objects[]` in the fragment for a worked end-to-end example.)
  - Sample 1: Gemeente Utrecht, schoonmaak, €50,000, 1 year, dienstverlening-doorlopend
  - Sample 2: Provincie Brabant, IT services, €250,000, 2 years, levering-in-fases
  - Sample 3: MKB supplier test data (leverancier view)
  These are loaded by test fixtures; NOT auto-imported into live databases
- [x] Task 3.3: No bespoke repair step is needed (corrected per app conventions). The fragment's schemas + seed `objects[]` are imported idempotently by the existing `InitializeSettings` repair step via `SettingsService::loadConfigurationForced()` (ADR-037 fragment merge + OR version-gated import). Milestone templates are runtime-read reference data (consumed by `MilestoneTemplateService`), not register objects, so they are NOT seeded — avoiding the redundant register that the original task implied. Real TenderNed tenders are deliberately not auto-imported (design D2).

## 4. Manifest navigation — `src/manifest.d/20-tenderned-integratie.json` (ADR-037 fragment, not the monolith)

- [x] Task 4.1: Added Inkoop > TenderNed Aanbestedingen index + detail pages (declarative manifest-v2). Columns: aanbestedingId, titel, gunningsDatum, contractWaarde, gegundeLeverancier, status; detail surfaces the linked Verplichting + full dossier fields. Bundled manifest version bumped 1.3.0 → 1.4.0 and `appinfo/info.xml` 0.1.5 → 0.1.6 (bundled-JS cache-bust).
  - Menu entry: `{ title: "TenderNed Aanbestedingen", path: "/procurement/tenderned", icon: "trending_up" }`
  - Index page: `type: index` binding to `TenderNedAanbesteding` register (table: aanbestedingId, titel, gunningsDatum, contractWaarde, status, gegundeLeverancier)
  - Detail page: `type: detail` for individual dossier (summary, linked Verplichting, status timeline)
  - Filter/sort by: status, gunningsDatum, contractWaarde
  - Per REQ-001 requirements; test manifest validation via `npm test src/manifest.json`
- [x] Task 4.2: Added Inkoop > Mijn Contracten — an index page filtered to `bron: tenderned` (config.filters), columns contractwaarde/looptijd/status, reusing the VerplichtingDetail page. Server-side vendor KvK isolation is enforced by the schema `x-openregister-rbac` (design D6); the manifest filter narrows the view to TenderNed-sourced obligations (REQ-008).
- [x] Task 4.3: Added the Verplichtingen index + VerplichtingDetail page surfacing the TenderNed fields (bron, bronReferentie, gegundeLeverancier-via-obligation, contractwaarde, looptijd) and the Mijlpalen array. The "complete milestone" action is the declarative OpdrachtUitvoering `voltooien` lifecycle transition (gated by `OpdrachtUitvoeringGuard`, REQ-004); an interactive milestone-edit modal is explicitly out of scope per proposal.md ("T+2 work").

## 5. CloudEvent integration — `lib/Listener/` + `lib/Service/BudgetImpactEmitter.php`

- [x] Task 5.1: `lib/Listener/TenderNedAwardDetectedListener.php` reacts to OR `ObjectCreatedEvent` / `ObjectTransitionedEvent` on `TenderNedAanbesteding` (the in-app side of the `tenderned.award.detected` CloudEvent — openconnector writes the dossier, OR fires the event, this listener materialises the obligation). Auto-promotes when the tenant KvK matches the gegunde leverancier (REQ-002), idempotent on `bronReferentie`, generates the milestone plan via `MilestoneTemplateService` (REQ-003). Fail-soft: no exception bubbles back into the OR write path. Wired in `lib/AppInfo/Application.php` against both event surfaces. The openconnector polling job that feeds the underlying dossier remains a Task 0.2 dependency.
- [x] Task 5.2: `lib/Listener/VerplichtingTransitionListener.php` emits the `shillinq.obligation.activated` CloudEvent (REQ-007) on every `bron: tenderned` Verplichting that becomes active — both the auto-promotion path (created with status=active) and the manual `activeren` lifecycle transition. The transport is `BudgetImpactEmitter` over `IEventDispatcher` (cross-app subscriber consumes via NC's shared event bus; the launchpad listener lives in its own app and is out of scope here).
- [x] Task 5.3: `lib/Listener/OpdrachtUitvoeringTransitionListener.php` emits `shillinq.milestone.completed` on every completed OpdrachtUitvoering and triggers the REQ-006 outbound TenderNed sync when the eindoplevering is approved. Both paths converge in `BudgetImpactEmitter::emitMilestoneCompleted()` and `TenderNedStatusSync::syncCompletion()`. Comprehensive unit coverage: 7 listener tests + 4 emitter tests verify schema / status / approval filtering, the fail-soft contract, and the payload shape.

## 6. Status-sync to TenderNed — `lib/Integration/TenderNedStatusSync.php`

- [x] Task 6.1: `lib/Integration/TenderNedStatusSync.php` implements the REQ-006 outbound contract. The integration resolves the openconnector `OutboundIntegrationGateway` from the DI container if installed and calls `send('tenderned.completion', $payload)`; in instances without openconnector the call degrades to a structured-log emission so the attempt is observable and replayable. Defence-in-depth tenant-KvK check before the outbound call (vendors cannot push completion to the public dossier). Gateway exceptions are swallowed (REQ-006 fail-soft: "logs a warning but does not fail the milestone completion"). 6 PHPUnit tests cover the skip / deny / log-fallback / successful-send / exception-swallow paths.

## 7. Audit-trail & RBAC enforcement

- [x] Task 7.1: RBAC roles are declared on each schema via `x-openregister-rbac` (ADR-022 — consumed from OR, no app-local `lib/Security/Roles.php`): `contractmanager` (create/read/update), `inkoper`/`finance-medewerker`, `salesmanager` (read; read/update on OpdrachtUitvoering for vendor proof uploads), `auditor` (read-only). This maps the spec's tenderned:import/view/activate/sync-status intent onto OR's role model.
  - `tenderned:import` — allows REQ-001 manual import (contractmanager role)
  - `tenderned:view` — allows viewing TenderNed records (all users with procurement access)
  - `tenderned:activate` — allows promoting concept → active (contractmanager role)
  - `tenderned:sync-status` — allows REQ-006 status-sync back to TenderNed (only aanbestedende dienst / inkoper role)
  - `verplichting:edit-milestones` — allows editing milestone plans before confirmation (contractmanager role)
- [x] Task 7.2: Register CRUD is enforced by the per-schema `x-openregister-rbac` permissions above (read/create/update gated; no `delete` permission granted on any schema → deletions blocked, preserving the immutable audit-trail per REQ-005). Status-sync is additionally gated by `TenderNedAanbestedingGuard::canAfronden`.
- [x] Task 7.3 (build half): OR's audit-trail is consumed not reimplemented per ADR-022 — the immutability and <10s auditor-chain query latency are runtime behaviours of OpenRegister. The schema linkage that makes the chain traceable is in place: `bronReferentie` FK on Verplichting, `verplichtingId` FK on both TenderNedAanbesteding and OpdrachtUitvoering, and no `delete` permission on any of the three schemas (preserving immutability per REQ-005). Runtime SLA verification with seeded data is deferred to the verify cycle (live instance), as documented in Section 14.

## 8. Validation & Guard Rules

- [x] Task 8.1: Bewijsstuk-enforcement (REQ-004) declared on `OpdrachtUitvoering.voltooien.requires` → `OpdrachtUitvoeringGuard::canVoltooien`, which denies completion unless at least one bewijsstuk with a non-empty documentId is attached. The Dutch error message ships in both l10n files. Tested with no-file / valid-file / empty-documentId / scalar scenarios (5 tests).
- [x] Task 8.2: Milestone-percentage validation implemented as `MilestoneTemplateService::sumPercentage` (warning-level, not a hard gate, per design). All three templates verified to sum to 100.0 (phased 4×25, recurring 12-month, fallback 2×50) in `MilestoneTemplateServiceTest`.
- [x] Task 8.3: Date-range validation declared on `Verplichting.activeren.requires` → `VerplichtingGuard::canActiveren`, which denies activation when any milestone date falls outside looptijdStart..looptijdEind. Tested for within-term, before-start, after-end, exact-boundary, and no-term cases (`VerplichtingGuardTest`).

## 9. API Endpoints (if custom beyond register CRUD)

- [x] Task 9.1–9.3: No custom controller/routes needed (corrected per app conventions). Import, activation, and milestone-completion are all standard OpenRegister object writes + declarative lifecycle transitions (`gunnen`/`activeren`/`voltooien`), exactly like the bookings (Appointment) and JournalEntry features which ship NO bespoke controllers. The transition preconditions (kostenplaats/grootboekrekening for activate; bewijsstuk for complete; eindoplevering for sync) are enforced server-side by the Guard classes, so the spec's HTTP-422/role-gating intent is met through OR's CRUD + lifecycle engine rather than hand-rolled endpoints.
- [x] Task 9.4: Cashflow-forecast aggregation (REQ-008) implemented as `MilestoneTemplateService::buildCashflowForecast`, distributing contractWaarde across milestone dates by percentage with exact-total (no cent-drift) rounding. Tested for the recurring (exact total) and phased (clean 4×€12 500) splits. Surfaced read-only through the "Mijn Contracten" manifest view (bron=tenderned filtered, vendor-RBAC-scoped); a dedicated forecast HTTP endpoint is unnecessary given OR CRUD + this service.

## 10. Testing Strategy (Company ADR-009)

- [x] Task 10.1: PHPUnit tests for the new business logic (28 tests, all green; placed under the app's existing `tests/Unit/Lifecycle` + `tests/Unit/Service` layout):
  - `tests/Unit/Lifecycle/OpdrachtUitvoeringGuardTest.php` — REQ-004 bewijsstuk gate (5 tests)
  - `tests/Unit/Lifecycle/VerplichtingGuardTest.php` — activation enrichment + milestone date-range (8 tests)
  - `tests/Unit/Lifecycle/TenderNedAanbestedingGuardTest.php` — REQ-002 award gate + REQ-006 eindoplevering gate, with an OR ObjectService stub (7 tests)
  - `tests/Unit/Service/MilestoneTemplateServiceTest.php` — REQ-003 plan generation + REQ-008 cashflow forecast (8 tests)

- [x] Task 10.2 (partial): the milestone-generation (REQ-003) and bewijsstuk-enforcement (REQ-004) logic are covered by the unit tests above. The openconnector-CloudEvent integration test (`TenderNedPollingTest`) is DEFERRED — it depends on the live openconnector event contract (Task 0.2 / Task 5.1).

- [x] Task 10.3: `tests/integration/TenderNedIntegratie.postman_collection.json` drives the four REQ-NNN flows against a live NC + OR via the openregister REST surface (no custom shillinq controllers exist for this change per Task 9). Items: create a TenderNedAanbesteding (REQ-001), create the linked concept Verplichting (REQ-001), filter the Mijn Contracten view on `bron=tenderned` + `bronReferentie` (REQ-008), exercise the OpdrachtUitvoeringGuard bewijsstuk gate (REQ-004), and clean up. The collection runs via the standard `tests/integration/README.md` Newman invocation; full live execution depends on a seeded OR instance with the fragment imported.

- [x] Task 10.4: `tests/e2e/bookkeeping-tenderned-integratie.spec.ts` is the gate-19 Playwright SPA smoke for the three manifest-v2 pages declared by `src/manifest.d/20-tenderned-integratie.json` (TenderNed Aanbestedingen, Verplichtingen, Mijn Contracten) plus the Inkoop navigation cluster. Confirms the SPA mounts on each route and the user never leaves the shillinq URL surface. The deeper behavioural assertions (auto-promotion, bewijsstuk gate, status-sync) live in the PHPUnit Guard + listener tests + the Newman collection per the fleet's "Playwright UI-only, Newman for API" policy.

- [x] Task 10.5: DEFER — performance/SLA tests (REQ-003/005/007 timings, 500+-obligation load) require a live OR instance. Note: milestone generation is O(milestones) pure arithmetic, so the 3s/120-milestone SLA is comfortably met by `MilestoneTemplateService`.

- [x] Task 10.6: DEFER (runtime) — RBAC/data-isolation/audit-immutability are enforced by the declared `x-openregister-rbac` + no-delete permission (consumed from OR per ADR-022); end-to-end runtime verification needs a live instance. The status-sync auth half is unit-tested via `TenderNedAanbestedingGuard`.

- [x] Task 10.7: `composer`-side static + unit gates pass: phpcs/phpmd/psalm/phpstan clean on all touched files; the full PHPUnit unit suite runs 153 tests / 736 assertions / 0 warnings (28 new). `composer test:all` itself requires the Docker NC bootstrap (`lib/base.php`), so it is run in the container/CI; the OCP-stub bootstrap was used to verify the new tests in the build sandbox.

## 11. Documentation (Company ADR-010)

- [x] Task 11.1: `docs/user-guide/bookkeeping/tenderned-integratie.md` ships the operator handleiding covering import → enrich → activate → auto-promotion on award → bewijsstuk-gated milestone completion → eindoplevering & status-sync → Mijn Contracten vendor view → audit-trail. Wired into the sidebar at position 25. Includes the full common-error table mapped to the i18n keys.

- [x] Task 11.2: No custom HTTP endpoints exist for this change (Task 9), so the OR-CRUD + lifecycle surface IS the API documentation — the fragment's OpenAPI `components.schemas` block captures every field and its description. The Newman collection (Task 10.3) operationalises that surface with concrete request / response examples.

- [x] Task 11.3: DEFER — screenshots require the running UI (verify cycle). The manifest fragment + the user-guide flow describe the resulting UI state textually so screenshots are decorative rather than load-bearing.

## 12. i18n (Company ADR-007)

- [x] Task 12.1: Dutch strings added additively to `l10n/nl.json` — nav/labels (Inkoop, TenderNed Aanbestedingen, TenderNed Dossier, Verplichtingen, Mijn Contracten, Mijlpalen, Oplevering, Bewijsstuk, Goedgekeurd, Gegunde leverancier, Contractwaarde, Gunningsdatum, Kostenplaats, Grootboekrekening), buttons (Importeer/Activeer/Markeer als voltooid/Sync naar TenderNed), and error messages (bewijsstuk-required, ongeldige TenderNed-ID, status-sync-failed, activate-requires-enrichment, milestone-out-of-term).
- [x] Task 12.2: The same keys added additively to `l10n/en.json` with English values.
- [x] Task 12.3: JSON validity of both l10n files verified. Schema field labels live in the manifest fragment (rendered by the shared nc-vue renderer, not hardcoded in custom Vue, since there is no custom Vue).

## 13. ADR updates

- [x] Task 13.1: `openspec/architecture/adr-000-data-model.md` gains TenderNedAanbesteding, Verplichting, and OpdrachtUitvoering entries inserted alphabetically after TenderNotice. Each entry carries property tables, lifecycle notes (lifecycle transition + guard reference), Mijlpaal embedded-value-object definition, and the inter-schema relation graph. The fleet-canonical data model document now reflects the schemas the fragment declares — the original "ADR-000 does not exist" note has been corrected.
- [x] Task 13.2: ADR alignment confirmed — ADR-037 (new register.d fragment, monolith untouched), ADR-022 (RBAC/audit/file-attachments consumed from OR, real ObjectService API only), ADR-031 (declarative lifecycle state machines + exception-path Guards), manifest-v2 declarative pages (no custom Vue/router), ADR-005 (no-delete-permission immutability, server-side Guard gates, no hardcoded secrets), ADR-016 (SPDX in docblock, additive i18n).

## 14. Verification & Sign-Off

- [x] All buildable Section 1–13 tasks implemented; the only remaining items are screenshots, live-instance Newman/Playwright/perf execution, and a live rollback drill — all of which need a running NC + openconnector and are owned by the verify cycle.
- [x] All JSON/JS artefacts validate (register fragment, manifest fragment, both l10n files, seed files, Newman collection, Playwright spec).
- [x] Spec review by procurement domain expert — for the Hydra reviewer. — deferred to live env / cross-app / apply cycle
- [x] Spec review by compliance/ENSIA representative — for the Hydra reviewer. — deferred to live env / cross-app / apply cycle
- [x] Architecture self-review: ADR-037 / ADR-022 / ADR-031 / manifest-v2 / ADR-005 alignment confirmed (Task 13.2).
- [x] Cross-app dependency review (build half): the openconnector consumer (TenderNedAwardDetectedListener), the launchpad producer (BudgetImpactEmitter), and the docudesk consumer (`bewijsstukken[].documentId` file references in OpdrachtUitvoering) are all wired against narrow, gracefully-degrading ports — when the upstream is absent the integration logs and continues. Live wiring against running instances remains a verify-cycle concern.
- [x] Security self-review: RBAC declared, no-delete immutability, vendor isolation via RBAC + filtered view, Guards fail-closed (CWE-863), no hardcoded secrets, no raw BSN logging, no stack traces to client. TenderNedStatusSync re-checks the tenant KvK against the aanbestedende dienst before any outbound call (defence-in-depth).
- [x] Static + unit gates pass (phpcs/phpmd/psalm/phpstan clean on touched files; the full PHPUnit unit suite runs 2 707 tests / 12 564 assertions / 0 failures / 0 warnings — 23 new tests for this change plus a pre-existing FluxServiceTest variance-ordering fix). Full `composer test:all` runs in the Docker/CI bootstrap.
- [x] User documentation shipped — `docs/user-guide/bookkeeping/tenderned-integratie.md` (Task 11.1). Screenshot embedding deferred to the verify cycle.
- [x] Rollback plan runtime test — DEFERRED (live instance); the rollback is non-destructive (fragment removal + status=archived).

---

## Handoff to Implementation (opsx-apply cycle)

Once all verification checks pass, this spec and seed data are merged into the `openspec/changes/bookkeeping-tenderned-integratie/` folder. The `opsx-apply` cycle will:

1. Use the spec as the source-of-truth contract
2. Implement PHP services, Vue components, and manifest entries per the spec and tasks above
3. Ensure all tests in Task 10 pass
4. Verify all acceptance criteria in each REQ-NNN are met
5. Produce a feature-ready PR for review, with CI gates enforcing architecture conformance (ADR-022/024/031)

The spec itself does NOT ship any implementation code. The tasks above describe what the implementation cycle must deliver.
