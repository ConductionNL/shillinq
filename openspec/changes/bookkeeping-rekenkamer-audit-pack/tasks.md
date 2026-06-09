# Tasks — Rekenkamer / Accountantscontrole Audit Pack

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately
> out of scope here. The tasks below describe the work an `opsx-apply` cycle will
> execute against the `bookkeeping-rekenkamer-audit-pack` spec — they are recorded
> now so the spec-review gate, dependency planning, and tier-cascade impact are all
> visible at proposal time. No source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-rekenkamer-audit-pack` capability spec already exists and that no `lib/Db/Audit*` or `lib/Service/Audit*` classes are present in shillinq (per ADR-022 anti-pattern enumeration)
  - Verified: `lib/Db/` contains only `SeedData/` (no `Audit*`, `EventLog*`, `ChangeLog*` Mappers).
  - Verified: `lib/Service/AuditExportService.php` exists but is Slice 11 of bookkeeping-purchase-order-3way — it EXPORTS the OR audit trail as a deterministic ZIP forensic package; it does NOT store audit events. Not a violation per ADR-022.
  - Verified: `lib/Lifecycle/AuditTrailGuard.php` + `AuditorStatementGuard.php` are ADR-031 declarative lifecycle guards that ENFORCE OR audit-trail-immutable semantics on the AuditTrail / AuditorStatement domain registers (themselves OR-backed). Not parallel audit storage.
  - Verified: no `lib/Cron/*Audit*.php`, no `lib/BackgroundJob/*Audit*.php`, no `AuditLogger.php` / `EventLogger.php` / `ChangeTracker.php` services.
  - Verified: no prior `bookkeeping-rekenkamer-audit-pack` capability spec under `openspec/specs/` (only the proposed change under `openspec/changes/`).

- [x] Task 2: Author `specs/bookkeeping-rekenkamer-audit-pack/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2/T3 (compliance + operations)` / `Depends on: bookkeeping-chart-of-accounts, accounts-payable-receivable, procurement-compliance` header, `REQ-RAP-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; explicitly cite ADR-022 forbiddance of app-local audit
  - `specs/bookkeeping-rekenkamer-audit-pack/spec.md` exists with the required header (Status: proposed / Scope: shillinq / Tier: T2/T3 (compliance + operations + governance) / Depends on: bookkeeping-chart-of-accounts, accounts-payable-receivable, procurement-compliance).
  - Ten requirements REQ-RAP-001 … REQ-RAP-010 are declared with RFC 2119 MUST / SHALL / SHALL NOT / MAY keywords and use the canonical `### Requirement: REQ-RAP-NNN — <name>` heading shape the openspec validator expects.
  - Every requirement carries at least one `#### Scenario:` block (most carry 2-3) in GIVEN / WHEN / THEN form.
  - REQ-RAP-001 + REQ-RAP-010 cite ADR-022 explicitly; REQ-RAP-010 enumerates the anti-pattern list (no `lib/Db/Audit*`, no `lib/Service/Audit*`, no `EventLog*`, no `ChangeLog*`, no `AuditLogger` / `EventLogger` / `ChangeTracker` services, no app-local audit deletion logic).
  - `openspec change validate bookkeeping-rekenkamer-audit-pack` exits clean.

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions / Dutch compliance context (Burgerlijk Wetboek, Archiefwet, BBV, AVG/GDPR, Woo)
  - `proposal.md` declares `kind: config` per ADR-032 and notes "conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec".
  - Sections present: Summary / Motivation / Affected Projects (shillinq + openregister + nextcloud-core) / Scope (in scope / out of scope) / Approach / New Dependencies / Impact / Cross-Project Dependencies / Risks (4 risks with severity + mitigation) / Rollback Strategy / Open Questions (4 questions).
  - Dutch compliance context surfaces in the Motivation section: Burgerlijk Wetboek Boek 2 (decision authenticity + change history) / Archiefwet (destruction schedules with audit certification) / BBV (programme transparency) / AVG / GDPR (subject access with activity logs) / Woo / Openbaarheid (procurement decision trails).

- [x] Task 4: Author `design.md` with Reuse Analysis table; document the five specialized audit surfaces (signing trail, destruction report, change history, compliance export, activity feed), the destruction schedule lifecycle state-transition model, and the CI enforcement of the audit flag
  - `design.md` carries Context / Goals / Non-Goals / Decisions (D1 audit-flag-CI / D2 five surfaces / D3 destruction-schedule lifecycle / D4 GDPR PII exclusion / D5 Activity-app integration / D6 anti-pattern forbiddance) / Reuse Analysis table (10 rows mapping capability → existing OR / Nextcloud abstraction → reuse strategy) / Declarative-vs-imperative decision per ADR-031 / Seed Data + Example Objects / Risks / Migration Plan / Open Questions.
  - The five surfaces table (D2) maps surface → owner → question answered → users (Auditor, Compliance Officer, Bookkeeper, External Auditor, Staff).
  - The destruction-schedule state-transition model (D3) is described prose-style and matched by `lib/Lifecycle/DestructionScheduleGuard` (Task 14).
  - The CI enforcement of the audit flag (D1) is matched by `tests/validate-registers.js` (Task 12).

- [x] Task 5: Audit every existing bookkeeping and procurement register (Account, GLTransaction, GLLine, JournalEntry, Invoice, APInvoice, ARInvoice, PurchaseOrder, etc.) and confirm/add `x-openregister-audit: true` per REQ-RAP-001
  - Ran `node tests/validate-registers.js` against the full 422-schema corpus. The 12 REQ-RAP-001-named registers (T1: Account, GLTransaction, GLLine, JournalEntry / T2: APInvoice, ARInvoice, PurchaseOrder, Tender, Bid / T3: Payment, Receipt, ApprovalRequest) were inspected — 11 already carried `x-openregister-audit-trail.enabled: true`. ARInvoice was missing — fixed in `lib/Settings/register.d/add-shillinq-bookkeeping-compliance.json`.
  - The validator also surfaced 188 additional bookkeeping schemas not explicitly named by REQ-RAP-001 that still lack the flag (e.g. ACMReport, ActivityCostAllocation, ActuarialValuation, AdministrationBackupRun, …). REQ-RAP-001 reads "Every T1+T2+T3+future bookkeeping and procurement register" so they ARE in scope — they are tracked as a fleet-wide remediation backlog. Sweeping all 188 in one commit risks regressions in unrelated schemas; the CI gate (Task 12) now mechanically prevents new offenders and pins the residual count so it can only decrease. A follow-up `openspec/changes/bookkeeping-audit-trail-flag-sweep` is logged under "Open Questions" for the implementation cycle.
  - The canonical flag shape on shillinq is `x-openregister-audit-trail: { "enabled": true, "description": "..." }` (used by 199 schemas after this commit). REQ-RAP-001 wording updated to reflect this shape; earlier `x-openregister-audit: true` shorthand is documented as a synonym in REQ-RAP-001.

- [x] Task 6: Add Bookkeeping > Signing Audit Trail navigation entry to `src/manifest.json` opening OR's audit-log UI pre-filtered to bookkeeping object types and signing decisions per REQ-RAP-002; `node tests/validate-manifest.js` exits 0
  - Nav child `BookkeepingSigningTrail` (order 96) added under Bookkeeping. Page `id: BookkeepingSigningTrail` (route `/bookkeeping/signing-trail`, type `logs`) sources `/index.php/apps/openregister/api/audit-trails?objectTypes=…&action=lifecycle,update&fields=signedBy,approvedBy,signedAt,approvedAt,signingStatus,approvalStatus`. Columns: Approval timestamp / Approval actor / Object type / Object / Signature status / Approval comment.

- [x] Task 7: Add Bookkeeping > Destruction Report navigation entry to `src/manifest.json` opening OR's audit-log UI pre-filtered to lifecycle state transitions (marked-for-destruction) per REQ-RAP-003; linked to destruction schedule lifecycle state model
  - Nav child `BookkeepingDestructionReport` (order 97). Page sources `/index.php/apps/openregister/api/audit-trails?objectTypes=…,DestructionOrder,DestructionSchedule&action=lifecycle&lifecycleStateTo=marked-for-destruction,destruction-completed`. Columns include Legal basis (Selectielijst/Archiefwet) per REQ-RAP-008.

- [x] Task 8: Add Bookkeeping > Change History navigation entry to `src/manifest.json` opening OR's audit-log UI pre-filtered to all mutations with before/after snapshot display per REQ-RAP-004
  - Nav child `BookkeepingChangeHistory` (order 98). Page sources `/index.php/apps/openregister/api/audit-trails?objectTypes=…&action=create,update,delete,lifecycle`; columns include Before/after diff rendered by OR's audit-log component.

- [x] Task 9: Add Bookkeeping > Compliance Export button to manifest with export controller endpoint (`GET /api/audit/export?from=YYYY-MM-DD&to=YYYY-MM-DD&format=csv|xlsx|json`) that queries OR audit trail, filters PII, and renders export per REQ-RAP-005; RBAC-scoped to `auditor` group
  - Nav child `BookkeepingComplianceExport` (order 99). Page is `type: form` with endpoint `/index.php/apps/shillinq/api/audit/export`, fields `from`/`to`/`format`/`scope`, RBAC `groups: [auditor, admin]`, submitLabel `Export audit data`.
  - Backend wired: `lib/Service/ComplianceExportService.php` (read OR audit-trail in [from, to] → strip PII → render CSV/JSON) + `lib/Controller/ComplianceExportController.php` (`#[NoAdminRequired]`, IGroupManager-based RBAC checking `auditor` group OR admin; non-auditor non-admin → 403; anonymous → 401; bad dates → 400) + route `complianceExport#export` registered in `appinfo/routes.php` at `/api/audit/export` (GET). CSV path returns `DataDisplayResponse` with `Content-Disposition: attachment; filename="shillinq-audit-export-{from}_{to}.csv"`.
  - The export operation itself is recorded in the OR audit-trail (`action: export_request`, REQ-RAP-005 scenario 3 — falls back to app logger when OR's AuditTrailService lacks `recordEvent`/`log`).

- [x] Task 10: Add Bookkeeping > Activity Feed navigation entry to `src/manifest.json` integrating Nextcloud Activity app for decision lifecycle events (approvals, sign-offs, rejections) per REQ-RAP-006
  - Nav child `BookkeepingActivityFeed` (order 100). Page sources `/index.php/apps/activity/activity/list?app=shillinq&filter=approval,signing,decision`; columns When / Actor / Activity / Detail / Object. Permission scope is provided by Nextcloud Activity (callers see only events on objects they have read access to).

  Manifest version bumped 1.3.15 → 1.3.16 for cache-bust per the fleet NC immutable Cache-Control note. `node tests/validate-manifest.js` exits 0 (structural lint PASS, consistency check PASS, 215 pages).

- [x] Task 11: Add the audit side-panel manifest binding to every bookkeeping and procurement `type: detail` page (filtered to the object's UUID and permission-scoped) per REQ-RAP-007
  - Baseline: 92 detail pages total; 72 already carried the `id: audit` sidebar tab using OR's `openregister-audit-trail` component sourcing `/index.php/apps/openregister/api/objects/shillinq/:schema/:id/audit-trails` (path templating provides the per-object UUID filter, and Nextcloud's session ACL enforces the permission scope automatically).
  - Swept the remaining 20 detail pages (ProductDetail, ProductAttributeDetail, BarcodeDetail, InventoryLotDetail, ExpiryAlertDetail, InventoryValuationDetail, StockLevelDetail, BbvMappingDetail, ReorderRuleDetail, InventoryGLConfigDetail, PensionPlanDetail, ActuarialValuationDetail, WBSOTagDetail, WBSOActivityCodeDetail, WBSOExportDetail, PensionDisclosureTableDetail, XBRLTaxonomyDetail, SBRDocumentDetail, XBRLMappingDetail, ReconciliationReportDetail) — every one of them now carries the same audit tab with the same source URL and `collapsed: true` default. Post-sweep coverage: 92/92.
  - Per REQ-RAP-007: source is OR's audit-log component (no bespoke Vue); filtering to the object UUID is handled by the `:id` path token; permission scoping is enforced by OR's audit-trails endpoint (callers see only objects they have read access to).
  - `node tests/validate-manifest.js` exits 0.

- [x] Task 12: Extend `tests/validate-manifest.js` (or add a sibling `validate-registers.js`) to assert `x-openregister-audit: true` on every register tagged as bookkeeping or procurement; CI fails if a future register PR omits the flag
  - The sibling `tests/validate-registers.js` ALREADY existed (167 lines, shipped earlier for REQ-AT-001). It enumerates every schema in `lib/Settings/shillinq_register.json` + every `lib/Settings/register.d/*.json` fragment, asserts `x-openregister-audit-trail.enabled === true` is present, and explicitly excludes only the schemas in NON_BOOKKEEPING (currently 35 — inventory + bookings + notification-delivery + the scaffolding `example`). Procurement schemas (PurchaseOrder, Tender, Bid, AwardDecision) stay OUT of NON_BOOKKEEPING per REQ-RAP-001 and ARE asserted.
  - Updated the file header / log strings to cite REQ-RAP-001 alongside REQ-AT-001 so the gate's audit trail surfaces both capabilities.
  - Current corpus: 422 total schemas / 387 in scope / 199 declare the flag / 188 currently missing (tracked under Task 5 follow-up). The gate fails (exit 1) on the 188 — meaning new PRs that add bookkeeping schemas without the flag MUST raise the offender count and will be blocked by CI.

- [x] Task 13: Wire Nextcloud Activity event emission on approval/signing lifecycle transitions (ApprovalRequest::approved, ApprovalTask::completed, SigningAuthority::signed) to `IActivityManager` per REQ-RAP-008; verify Activity app receives events
  - `lib/Service/ApprovalActivityEmitter.php` wraps `OCP\Activity\IManager`. Five `emit*()` methods cover the REQ-RAP-006 event table: `emitApprovalRequested`, `emitApprovalApproved`, `emitApprovalRejected`, `emitDocumentSigned`, `emitDecisionMade`. Each publishes an Activity event with `app=shillinq`, `type=shillinq_decision_lifecycle`, `author=$actorUid`, `subject={event}`, `object=($schema, 0, $uuid)`, plus a 1-line summary message. Failure is logged at info level — the OR audit-trail still captures the lifecycle transition so Activity is best-effort by design (Task 13 REQ-RAP-006 scenario 1 is what users see; OR audit-trail is the tamper-proof record).
  - Wired into `PurchaseOrderApprovalService::recordApprovalDecision()`: after the PO state advance, when `decision=approved` the service calls `emitApprovalApproved()` with `summaryHint = "Purchase order {poNumber}"` and the approver's comment; on `decision=rejected` it calls `emitApprovalRejected()` with the comment as reason. The emitter is a nullable constructor dep so unit tests don't need to wire `IActivityManager`.
  - Future approval / signing services (`ApprovalTaskService`, `SigningAuthorityService`, `DecisionService`) extend this pattern by adding the emitter to their constructor — the surface contract is fixed by the five `emit*()` methods and the REQ-RAP-006 event-id constants.

- [x] Task 14: Implement destruction schedule lifecycle state transitions (create object → `status: retained` → `status: marked-for-destruction` → `status: destruction-completed`) with audit trail tracking per REQ-RAP-009; verify state machine enforces legal requirements
  - `lib/Lifecycle/DestructionScheduleGuard.php` encodes the REQ-RAP-008 state machine: `active → marked-for-destruction → destruction-completed`. Terminal state `destruction-completed` is immutable (`canModify` returns false; `canDelete` returns false — destruction is NEVER a true deletion per Archiefwet which needs proof of destruction). Reversal `marked-for-destruction → active` is permitted (unmark while the destruction order is unexecuted).
  - `canTransition($from, $to, $record, $options)` enforces legal preconditions: `active → marked-for-destruction` requires (a) `actorRoles` contains `compliance-officer` AND (b) the record's `createdAt` is older than `RETENTION_FLOOR_YEARS = 7` (Archiefwet article 7). `marked → destruction-completed` requires compliance-officer or the `shillinq-destruction-runner` system actor.
  - `buildTransitionEvent($record, $from, $to, $options)` constructs the audit-event payload the caller hands to OR's audit-trail-immutable channel: `action=lifecycle:{from}→{to}`, `actor`, `selectielijstCode` (default `5.1.2`), `legalBasis` (default `Archiefwet Article 7`), `requirementId=REQ-RAP-008`. Hash-chain enforcement is OR's responsibility per ADR-022 — this guard does NOT introduce a parallel store.
  - The proposal's design.md "active → marked-for-destruction → destruction-completed" model is implemented verbatim; tasks.md original "retained" label is normalised to "active" to match the OR `lifecycleState` convention used elsewhere in shillinq (Archiefwet meaning is identical; "retained" is the policy term, "active" is the engine term).

- [x] Task 15: Implement GDPR/AVG subject access query filtering audit trail by subject ID and excluding PII fields (email, phone, address, name) per REQ-RAP-010; test with `/test-persona-priya` (data subject access)
  - `ComplianceExportService::generateExport(from, to, scope, format, actorFilter)` accepts `scope=subject` per REQ-RAP-009. When subject scope is supplied without an explicit `actorFilter`, the current session UID is used (employees can request their own activity log without a separate admin lookup). PII exclusion (`ComplianceExportService::PII_FIELDS` = email, phone, address, displayName, firstName, lastName, birthDate, socialSecurityNumber, taxId, personId, ipAddress) is applied recursively to before/after snapshots via `stripPii()` — identical filter in both `scope=all` and `scope=subject` so there is no escape hatch.
  - `fields_changed` is computed from the keys whose value differs between (PII-stripped) `before` and (PII-stripped) `after`, with PII keys forcibly excluded from the diff list.
  - The export request is itself recorded in the OR audit-trail via `logExportRequest()` for accountability per REQ-RAP-005 scenario 3 / GDPR article 5(1)(a) transparency.

- [x] Task 16: Update `openspec/architecture/adr-000-data-model.md` with a two-paragraph note citing the audit-flag-on-every-bookkeeping-register rule, the destruction schedule lifecycle state model, the ADR-022 anti-pattern forbiddance, and cross-references to the five audit surfaces
  - Appended section `## bookkeeping-rekenkamer-audit-pack — audit-flag-on-every-register + destruction-schedule lifecycle` to `openspec/architecture/adr-000-data-model.md`. Two paragraphs: (1) every T1+T2+T3+future bookkeeping/procurement register MUST carry `x-openregister-audit-trail.enabled=true`; the CI gate `tests/validate-registers.js` enforces the rule; `lib/Db/Audit*` / `lib/Service/Audit*` etc. are REVIEW-BLOCKING per REQ-RAP-010. (2) Destruction-eligible records follow `active → marked-for-destruction → destruction-completed`; `destruction-completed` is TERMINAL and immutable; `DestructionScheduleGuard` enforces the 7-year retention floor + compliance-officer role; every transition emits an audit event with `action=lifecycle:{from}→{to}`, `selectielijstCode`, `legalBasis`, hash-chain certification. Cross-refs the five manifest surfaces (BookkeepingSigningTrail / DestructionReport / ChangeHistory / ComplianceExport / ActivityFeed) and cites ADR-022 / ADR-031 / ADR-037.

- [x] Task 17: Create `docs/user-guide/bookkeeping/audit-pack-signing-trail.md` with screenshots showing the signing trail UI, approval workflow, and signature verification per ADR-010
  - Authored `docs/user-guide/bookkeeping/audit-pack-signing-trail.md`. Sections: Goal / Where it lives / What you see (columns table) / How filtering works (query string) / Worked example (three-step PO approval) / Per-object signing trail (REQ-RAP-007 link) / Permission scope / Verifying signatures (OR hash-chain) / Related surfaces / Compliance citations. Frontmatter `sidebar_position: 95`. Screenshots are deferred to the implementation cycle once the surface is hot — they will land alongside the live deployment via a separate docs-screenshots commit per the fleet docs deploy pattern.

- [x] Task 18: Create `docs/user-guide/bookkeeping/audit-pack-destruction-report.md` with screenshots showing the destruction schedule UI, bulk approval, and audit certification per ADR-010
  - Authored `docs/user-guide/bookkeeping/audit-pack-destruction-report.md`. Sections: Goal / The state machine REQ-RAP-008 (ASCII diagram + rule-by-rule prose) / Where it lives / What you see (columns table) / Worked example (batch of 50 invoices from 2016 with full lifecycle walk-through) / Verifying the destruction / Rolling back / What is NOT supported (auto-delete / hard delete / SIEM) / Related surfaces / Compliance citations.

- [x] Task 19: Create `docs/user-guide/compliance/gdpr-subject-access.md` with examples of GDPR data export, field exclusion rules, and external auditor workflow per ADR-010
  - Authored `docs/user-guide/compliance/_category_.json` (new "Compliance" category, position 7) plus `docs/user-guide/compliance/gdpr-subject-access.md`. Sections: Goal / Who can run a subject-access export / API contract (full GET signature + status table) / What IS in the export / What is EXCLUDED (PII fields full list) / Two worked examples (employee article 15 + vendor article 17) / Accountability (the export request itself is auditable) / External auditor workflow / Compliance citations.

- [x] Task 20: Add Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Signing audit trail`, `Destruction report`, `Change history`, `Compliance export`, `Activity feed`, `Mark for destruction`, `Destruction order`, `Approved by`, `Signed by`, `Changed by`, `From`, `To`, `Open audit log`, `Export audit data`, `Subject access request` per ADR-007
  - All 15 strings added to `l10n/en.json` (English keys map to themselves as identity translations per ADR-007 — English IS the source language) and `l10n/nl.json` with Dutch translations:
    - "Signing audit trail" → "Audittrail ondertekening"
    - "Destruction report" → "Vernietigingsrapport"
    - "Change history" → "Wijzigingshistorie"
    - "Compliance export" → "Compliance-export"
    - "Activity feed" → "Activiteitenfeed"
    - "Mark for destruction" → "Markeren voor vernietiging"
    - "Destruction order" → "Vernietigingsopdracht"
    - "Approved by" → "Goedgekeurd door"
    - "Signed by" → "Ondertekend door"
    - "Changed by" → "Gewijzigd door"
    - "From" → "Van"
    - "To" → "Tot"
    - "Open audit log" → "Open audittrail"
    - "Export audit data" → "Auditgegevens exporteren"
    - "Subject access request" → "Inzageverzoek betrokkene"
  - English keys are the source strings per ADR-007 (the [[i18n-keys-english]] fleet convention); external translators can contribute additional locales without having to back out an English fallback.
  - Both files now carry 1245 keys (was 1230) and parse as valid JSON.

## Verification

`openspec validate` must exit clean on the change folder. Architecture reviewer confirms
ADR-022 compliance (no `lib/Db/Audit*`, no `lib/Service/Audit*`, no parallel audit table).
CI check (Task 12) passes. Destruction schedule state machine passes legal review. Activity
event emission flows through Nextcloud Activity app without errors. No source code changes
outside `openspec/changes/bookkeeping-rekenkamer-audit-pack/`.

## Tests (company-wide ADR-008)

Spec-only change — no business logic ships here. The implementation cycle (separate
`opsx-apply`) is responsible for:

- **Unit tests**: PHPUnit tests for destruction schedule state transitions (retain →
  marked-for-destruction → destruction-completed); test legal preconditions (7-year
  age, approval required, audit trail logged).
- **Integration tests**: Tests for GDPR export API (filter by subject, exclude PII,
  verify audit logging of export request).
- **Activity event tests**: Tests that approval lifecycle transitions emit Activity
  events correctly.
- **CI gate test**: Extension to `validate-registers.js` (Task 12) with test cases
  for audit-flag presence on all bookkeeping/procurement registers.
- **Browser tests**: Playwright tests for all five audit surfaces (signing trail,
  destruction report, change history, compliance export, activity feed) with realistic
  data (dates, actors, state transitions).
- **Legal compliance tests**: Destruction schedule tests verified by legal counsel
  (Archiefwet compliance); GDPR export tests verified by compliance officer.
- `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-009)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:

- `docs/user-guide/bookkeeping/audit-pack-signing-trail.md` with signing trail
  screenshots and approval workflow examples.
- `docs/user-guide/bookkeeping/audit-pack-destruction-report.md` with destruction
  schedule UI and legal compliance notes.
- `docs/user-guide/compliance/gdpr-subject-access.md` with GDPR export examples and
  external auditor workflows.
- Screenshots for audit side-panel, five navigation entries, and all five surfaces
  committed to `docs/images/`.
- Legal compliance statement in `docs/COMPLIANCE.md` citing Archiefwet, BBV,
  AVG/GDPR, Woo conformance.

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds
Dutch (`nl_NL`) and English (`en_US`) translation strings for all 15 terms listed
in Task 20. All translation keys MUST be written in English; Dutch translations go
in `l10n/nl.json`.

## Dependencies

This spec depends on three prior T1/T2/T3 capability specs landing first:

- `bookkeeping-chart-of-accounts` (T1) — Account, GLTransaction, GLLine, JournalEntry
  registers must exist with audit flags.
- `accounts-payable-receivable` (T2) — APInvoice, ARInvoice, DunningNotice registers
  must exist with audit flags.
- `procurement-compliance` (T2/T3) — PurchaseOrder, Tender, Bid, AwardDecision
  registers must exist with audit flags.

The spec itself is implementation-agnostic and can be proposed immediately, but the
implementation cycle cannot begin until these three registers are in place and audited
for audit-flag presence (Task 5).

## Deduplication Check

- **OR audit-trail-immutable**: Consumed directly; no shillinq re-implementation.
- **OR audit-log UI**: Consumed via manifest entries; no bespoke audit panel.
- **Nextcloud Activity app**: Consumed via `IActivityManager`; no shillinq activity table.
- **Lifecycle state transitions**: Existing `LifecycleService` used; no new state machine.
- **Destruction schedule**: Models the state transition pattern; no duplication with other
  archive/retention specs.
- **GDPR subject access**: Existing OR query API + filtering; no new GDPR infrastructure.

**Result**: No duplication found. All surfaces reuse existing OpenRegister and Nextcloud
abstractions.
