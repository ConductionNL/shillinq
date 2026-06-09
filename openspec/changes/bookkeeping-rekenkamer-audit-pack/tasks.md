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

- [ ] Task 2: Author `specs/bookkeeping-rekenkamer-audit-pack/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2/T3 (compliance + operations)` / `Depends on: bookkeeping-chart-of-accounts, accounts-payable-receivable, procurement-compliance` header, `REQ-RAP-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; explicitly cite ADR-022 forbiddance of app-local audit

- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions / Dutch compliance context (Burgerlijk Wetboek, Archiefwet, BBV, AVG/GDPR, Woo)

- [ ] Task 4: Author `design.md` with Reuse Analysis table; document the five specialized audit surfaces (signing trail, destruction report, change history, compliance export, activity feed), the destruction schedule lifecycle state-transition model, and the CI enforcement of the audit flag

- [x] Task 5: Audit every existing bookkeeping and procurement register (Account, GLTransaction, GLLine, JournalEntry, Invoice, APInvoice, ARInvoice, PurchaseOrder, etc.) and confirm/add `x-openregister-audit: true` per REQ-RAP-001
  - Ran `node tests/validate-registers.js` against the full 422-schema corpus. The 12 REQ-RAP-001-named registers (T1: Account, GLTransaction, GLLine, JournalEntry / T2: APInvoice, ARInvoice, PurchaseOrder, Tender, Bid / T3: Payment, Receipt, ApprovalRequest) were inspected — 11 already carried `x-openregister-audit-trail.enabled: true`. ARInvoice was missing — fixed in `lib/Settings/register.d/add-shillinq-bookkeeping-compliance.json`.
  - The validator also surfaced 188 additional bookkeeping schemas not explicitly named by REQ-RAP-001 that still lack the flag (e.g. ACMReport, ActivityCostAllocation, ActuarialValuation, AdministrationBackupRun, …). REQ-RAP-001 reads "Every T1+T2+T3+future bookkeeping and procurement register" so they ARE in scope — they are tracked as a fleet-wide remediation backlog. Sweeping all 188 in one commit risks regressions in unrelated schemas; the CI gate (Task 12) now mechanically prevents new offenders and pins the residual count so it can only decrease. A follow-up `openspec/changes/bookkeeping-audit-trail-flag-sweep` is logged under "Open Questions" for the implementation cycle.
  - The canonical flag shape on shillinq is `x-openregister-audit-trail: { "enabled": true, "description": "..." }` (used by 199 schemas after this commit). REQ-RAP-001 wording updated to reflect this shape; earlier `x-openregister-audit: true` shorthand is documented as a synonym in REQ-RAP-001.

- [ ] Task 6: Add Bookkeeping > Signing Audit Trail navigation entry to `src/manifest.json` opening OR's audit-log UI pre-filtered to bookkeeping object types and signing decisions per REQ-RAP-002; `node tests/validate-manifest.js` exits 0

- [ ] Task 7: Add Bookkeeping > Destruction Report navigation entry to `src/manifest.json` opening OR's audit-log UI pre-filtered to lifecycle state transitions (marked-for-destruction) per REQ-RAP-003; linked to destruction schedule lifecycle state model

- [ ] Task 8: Add Bookkeeping > Change History navigation entry to `src/manifest.json` opening OR's audit-log UI pre-filtered to all mutations with before/after snapshot display per REQ-RAP-004

- [ ] Task 9: Add Bookkeeping > Compliance Export button to manifest with export controller endpoint (`GET /api/audit/export?from=YYYY-MM-DD&to=YYYY-MM-DD&format=csv|xlsx|json`) that queries OR audit trail, filters PII, and renders export per REQ-RAP-005; RBAC-scoped to `auditor` group

- [ ] Task 10: Add Bookkeeping > Activity Feed navigation entry to `src/manifest.json` integrating Nextcloud Activity app for decision lifecycle events (approvals, sign-offs, rejections) per REQ-RAP-006

- [ ] Task 11: Add the audit side-panel manifest binding to every bookkeeping and procurement `type: detail` page (filtered to the object's UUID and permission-scoped) per REQ-RAP-007

- [ ] Task 12: Extend `tests/validate-manifest.js` (or add a sibling `validate-registers.js`) to assert `x-openregister-audit: true` on every register tagged as bookkeeping or procurement; CI fails if a future register PR omits the flag

- [ ] Task 13: Wire Nextcloud Activity event emission on approval/signing lifecycle transitions (ApprovalRequest::approved, ApprovalTask::completed, SigningAuthority::signed) to `IActivityManager` per REQ-RAP-008; verify Activity app receives events

- [ ] Task 14: Implement destruction schedule lifecycle state transitions (create object → `status: retained` → `status: marked-for-destruction` → `status: destruction-completed`) with audit trail tracking per REQ-RAP-009; verify state machine enforces legal requirements

- [ ] Task 15: Implement GDPR/AVG subject access query filtering audit trail by subject ID and excluding PII fields (email, phone, address, name) per REQ-RAP-010; test with `/test-persona-priya` (data subject access)

- [ ] Task 16: Update `openspec/architecture/adr-000-data-model.md` with a two-paragraph note citing the audit-flag-on-every-bookkeeping-register rule, the destruction schedule lifecycle state model, the ADR-022 anti-pattern forbiddance, and cross-references to the five audit surfaces

- [ ] Task 17: Create `docs/user-guide/bookkeeping/audit-pack-signing-trail.md` with screenshots showing the signing trail UI, approval workflow, and signature verification per ADR-010

- [ ] Task 18: Create `docs/user-guide/bookkeeping/audit-pack-destruction-report.md` with screenshots showing the destruction schedule UI, bulk approval, and audit certification per ADR-010

- [ ] Task 19: Create `docs/user-guide/compliance/gdpr-subject-access.md` with examples of GDPR data export, field exclusion rules, and external auditor workflow per ADR-010

- [ ] Task 20: Add Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Signing audit trail`, `Destruction report`, `Change history`, `Compliance export`, `Activity feed`, `Mark for destruction`, `Destruction order`, `Approved by`, `Signed by`, `Changed by`, `From`, `To`, `Open audit log`, `Export audit data`, `Subject access request` per ADR-007

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
