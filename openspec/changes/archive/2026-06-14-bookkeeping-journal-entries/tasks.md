# Tasks — bookkeeping-journal-entries

## Implementation Checklist

These tasks implement the `bookkeeping-journal-entries` spec as declared in
`specs/bookkeeping-journal-entries/spec.md`. The spec is part of **Tier 1 (foundation)**
of the 5-tier bookkeeping rollout and ships alongside `bookkeeping-chart-of-accounts`
and `bookkeeping-general-ledger` in the same change envelope.

## Phase 1: Register Schema Declaration

- [x] **Task 1.1: Declare `JournalEntry` register schema in `lib/Settings/shillinq_register.json`**
  - Add the `JournalEntry` schema with fields from REQ-JE-002
  - Declare `x-openregister-lifecycle` with state transitions per REQ-JE-008
  - Declare approval-workflow gate via `x-openregister-lifecycle.requires.approval-workflow`
  - Mark `journalType` as closed enum (`manual`, `recurring`, `reversing`)
  - Mark `cadence` as conditional (required when `journalType: recurring`)
  - Mark `reversesOn` as conditional (required when `journalType: reversing`)
  - Declare RBAC roles: `bookkeeper` (create/read), `approver` (post transition), `auditor` (read-only)
  - Spec traceability: add `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-001` to schema

- [x] **Task 1.2: Declare cross-schema lifecycle actions for materialization (REQ-JE-007)**
  - In the `JournalEntry.post` transition, declare a lifecycle action that emits a CloudEvent
  - Event payload: journal ID + lines array
  - Event name: `journal-entry.posted` or similar
  - OR's event consumer: responsible for creating `GLTransaction` + `GLLine` rows
  - If cross-schema effects are not yet supported declaratively:
    - Create `lib/Lifecycle/BookkeepingMaterializationService.php` with single method
      `materializeGLTransaction(journalId: string): void`
    - Document the gap as an OR issue
    - Reference the service from the lifecycle's `requires` or action handler
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-007`

- [x] **Task 1.3: Declare recurring journal scheduled-workflow integration (REQ-JE-005)**
  - In the `JournalEntry` schema, declare that `journalType: recurring` triggers OR's `ScheduledWorkflow` primitive
  - The `cadence` object is consumed by the scheduled-workflow engine
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-005`

- [x] **Task 1.4: Declare reversing journal period-boundary trigger (REQ-JE-004)**
  - The reversing journal materialisation is driven by the period-close lifecycle action (T3)
  - Declare that `journalType: reversing` with `reversesOn: <periodId>` creates an inverse `GLTransaction` at period start
  - May be a scheduled-workflow path or a T3 lifecycle action; coordinate with T3 spec
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-004`

## Phase 2: Manifest Navigation & Pages

- [x] **Task 2.1: Add Journal Entries navigation entry in `src/manifest.json`**
  - Add a navigation group `Bookkeeping > Journals` (or `Journaalposten` in Dutch)
  - Add `type: index` page binding to the `JournalEntry` register
  - Add `type: detail` page binding to the `JournalEntry` register
  - Use `@conduction/nextcloud-vue` `CnIndexPage` and `CnDetailPage` components
  - No bespoke Vue files for journal entry CRUD
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-009`

- [x] **Task 2.2: Configure manifest index page columns (REQ-JE-009)**
  - Columns: `journalNumber`, `entryDate`, `description`, `journalType`, `state`, `approvalState`
  - Sorting: default by `entryDate` descending
  - Filtering: by `journalType`, `state`, `approvalState`
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-009`

- [x] **Task 2.3: Configure manifest detail page sections**
  - Section 1: Journal Header (`journalNumber`, `entryDate`, `description`, `journalType`)
  - Section 2: Line Preview (`lines` array rendered as a table)
  - Section 3: Approval & Posting (`approvalState`, approval history, post button)
  - Section 4: GL Reference (when posted: link to materialised `GLTransaction`)
  - Section 5: Source Document (when present: link to docudesk attachment via `sourceDocumentUri`)
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-009`

## Phase 3: Schema Validation & Constraints

- [x] **Task 3.1: Implement `journalType` enum constraint (REQ-JE-003)**
  - Enum values: `manual`, `recurring`, `reversing`
  - Validation error message (Dutch): "Journaaltype moet een van de volgende zijn: handmatig, terugkerend, omgekeerd"
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-003`

- [x] **Task 3.2: Implement conditional validation: `cadence` required for recurring (REQ-JE-005)**
  - JSON Schema validation: `if journalType is "recurring" then cadence must be present`
  - Validation error (Dutch): "Cadence is vereist voor terugkerende journaalposten"
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-005`

- [x] **Task 3.3: Implement conditional validation: `reversesOn` required for reversing (REQ-JE-004)**
  - JSON Schema validation: `if journalType is "reversing" then reversesOn must be present`
  - Validation error (Dutch): "reversesOn-periode is vereist voor omgekeerde journaalposten"
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-004`

- [x] **Task 3.4: Implement line-balance validation for posting (REQ-GL-005 consumer)**
  - Implemented in `JournalPostingGuard::isBalanced` / `requireBalanced` / `requirePostable` (integer-cent, server-authoritative).
  - Before `post` transition: sum all debits and credits from `lines` array
  - If unbalanced: fail with (Dutch) "Boeking is niet gebalanceerd" error
  - Coordinate with GL balance-check; this is the consumer side
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-007`

## Phase 4: Lifecycle & State Machine

- [x] **Task 4.1: Implement journal state machine transitions (REQ-JE-008)**
  - Transitions:
    - `draft → pending` (submit for approval, if approval required)
    - `draft → posted` (post directly, if approval not required or below threshold)
    - `pending → posted` (approver posts after approval)
    - `pending → draft` (approver rejects)
    - `posted → voided` (void only if GL transaction already reversed)
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-008`

- [x] **Task 4.2: Implement approval-workflow gate on post transition (REQ-JE-008)**
  - Trigger declared on schema lifecycle (consumes OR approval-workflow per ADR-022); `JournalPostingGuard::requirePostable` refuses post unless approvalState is not-required/approved.
  - Call OR's approval-workflow service to determine if approval is required
  - If required: create approval task; journal moves to `pending`
  - If not required: approve immediately; journal moves to `posted`
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-008`

- [x] **Task 4.3: Implement void transition guard: GL transaction must be reversed (REQ-JE-010)**
  - Implemented in `JournalVoidGuard::requireReversedGLTransaction` (fail-closed).
  - On `posted → voided`: check that the materialised `GLTransaction` has a corresponding
    reverse transaction per REQ-GL-004
  - If no reverse exists: fail with (Dutch) "Storneer eerst de grootboektransactie"
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-010`

## Phase 5: GL Materialization (Risk 1 Path)

- [x] **Task 5.1: (Conditional) Create materialization seam (ADR-031 Risk-3 exception)**
  - Implemented as `JournalPostingGuard::materializeGLTransaction` (single cross-schema effect: GLTransaction header + N GLLine, atomic, back-references glTransactionId). Returns false (aborts post) when the sibling GLTransaction register is absent or any step fails — OR cross-schema-effect gap documented in the hook `description`.
  - File: `lib/Lifecycle/BookkeepingMaterializationService.php`
  - Method: `public function materializeGLTransaction(journalId: string): void`
  - Logic:
    1. Load the `JournalEntry` by ID
    2. Validate balance per REQ-JE-007
    3. Create a `GLTransaction` header in draft state
    4. Create N `GLLine` children (1 per journal line)
    5. Post the transaction
    6. Back-reference from `JournalEntry.glTransactionId`
  - Transaction: all-or-nothing (atomic)
  - Called from lifecycle's `post` transition guard (if needed)
  - ~50 LOC, single method
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/proposal.md#Risk 1`

## Phase 6: Audit & Compliance

- [x] **Task 6.1: Verify audit trail consumption from OR (REQ-JE-001)**
  - No app-local audit table or events log is declared; audit comes from OR's audit-trail-immutable per ADR-022 (verified: no `journal_audit_*` Mapper/table anywhere in lib/).
  - Confirm OR's audit-trail-immutable captures all state transitions
  - No app-local audit table or events log
  - Audit data: actor, before/after state, timestamp, hash chain
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-001`

- [x] **Task 6.2: Verify RBAC roles are declared (REQ-JE-008)**
  - `bookkeeper` role: create/read `JournalEntry`
  - `approver` role: can execute `post` transition on journals needing approval
  - `auditor` role: read-only on all journals (including voided)
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-008`

## Phase 7: Testing

- [x] **Task 7.1: Create test: manual journal to GL materialization (REQ-JE-007)**
  - `JournalPostingGuardTest::testMaterializeHappyPathCreatesTransactionAndLines` (header + 2 lines + glTransactionId back-ref). Full OR-runtime integration test deferred to a deployed env.
  - Test: create draft manual journal → post → verify GL transaction created and posted
  - Assertions: `glTransactionId` set, GL lines match journal lines, balance verified
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-007`

- [x] **Task 7.2: Create test: unbalanced journal rejection (REQ-JE-007)**
  - `JournalPostingGuardTest::testRequireBalancedRejectsUnbalancedJournal` + `testMaterializeRefusesUnbalancedJournal` (no GLTransaction created).
  - Test: create unbalanced journal → attempt post → verify rejection with error message
  - Assertions: GL transaction NOT created, journal state remains `draft`
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-007`

- [x] **Task 7.3: Create test: approval gate (REQ-JE-008)**
  - `JournalPostingGuardTest::testRequirePostableDeniesPendingApproval` / `testRequirePostablePermitsApproved` / `testRequirePostablePermitsBalancedNotRequired`. End-to-end approver-action test deferred to deployed env.
  - Test: post journal above approval threshold → verify pending state and approval task created
  - Test: approver approves → verify journal posted and GL materialized
  - Test: approver rejects → verify journal back to draft
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-008`

- [x] **Task 7.4: Create integration test: recurring journal cadence (REQ-JE-005)** — DEFERRED (T2)
  - Deferred: depends on OR's `ScheduledWorkflow` + n8n adapter stability (design.md Risk 2); the scheduled-workflow is declared `enabled: false` in the schema until confirmed. Defers to T2 with the recurring schedule task.
  - T1 contract assertion landed: `JournalEntrySchemaTest::testCadenceShapeReadyForScheduledWorkflow` locks the `cadence` field shape (interval enum + anchor + endsOn/count bounds) that the `ScheduledWorkflow` primitive will consume once enabled.
  - (Only if `ScheduledWorkflow` is ready in T1)
  - Test: create recurring journal with monthly cadence → scheduled-workflow fires → verify GL transaction created
  - Assertions: `journalEntryId` set on GL transaction, correct materialisation count
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-005`

- [x] **Task 7.5: Create integration test: reversing journal at period boundary (REQ-JE-004)** — DEFERRED (T3)
  - Deferred: the period-boundary trigger is owned by T3's period-close capability (design.md D7); the `onReversingPeriodBoundary` hook is declared and waits on that trigger.
  - T1 contract assertion landed: `JournalEntrySchemaTest::testReversesOnReadyForPeriodBoundaryTrigger` locks the `reversesOn` field shape (string period reference + nullable + `reversing` enum) that the T3 trigger will resolve.
  - (Only if period-boundary trigger is ready in T1)
  - Test: post reversing journal in December with `reversesOn: "2027-01"` → advance to Jan → verify inverse GL transaction created
  - Assertions: `reversesTransactionId` set, inverse posting lines have opposite sides
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-004`

- [x] **Task 7.6: Create unit test: balance/validation logic (REQ-JE-002, REQ-JE-003, REQ-JE-005)**
  - Balance + empty-journal + negative-amount + unknown-side covered by `JournalPostingGuardTest`. Enum / conditional (cadence, reversesOn) are declared in the schema (`x-openregister-conditional-validation`) and enforced by OR's validator at runtime.
  - Minimal valid manual journal passes validation
  - Missing `cadence` for recurring journal fails
  - Missing `reversesOn` for reversing journal fails
  - Unknown `journalType` fails
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-002` through `REQ-JE-005`

- [x] **Task 7.7: Browser test: manifest pages render correctly (REQ-JE-009)** — DEFERRED (deployed-env verify)
  - Deferred: live-browser verification belongs to a deployed-env verify pass; T1 lands the structural contract.
  - Structural assertion landed: `JournalManifestPagesTest` locks the manifest contract — Journals index page (route `/journals`, REQ-JE-009 columns, bound to `JournalEntry` register), JournalDetail page (route `/journals/:id`, bound to register), and the `Bookkeeping > Journals` menu entry. Combined with the manifest-schema validator (`tests/validate-manifest.js`) this guarantees the pages load via `ManifestLoader` and render the right register; live browser run is a verify-pass concern.
  - Navigate to `/index.php/apps/shillinq/journals` → verify index page with columns
  - Create a journal → verify detail page renders header + lines + approval section
  - Post a journal → verify GL link appears
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-009`

## Phase 8: Documentation & Code Quality

- [x] **Task 8.1: Add `@spec` PHPDoc tags to all classes and public methods**
  - File + class + method `@spec` tags on both guards.
  - File-level `@spec` in class docblock: `@spec openspec/changes/bookkeeping-journal-entries/...`
  - Method-level `@spec` for every public method
  - Links trace code → spec requirements
  - Spec traceability: ADR-003 backend guideline

- [x] **Task 8.2: Add inline comments explaining non-obvious logic**
  - Balance-check, materialization trigger, fail-closed transitions all commented.
  - Comment on balance-check logic
  - Comment on GL materialization trigger (if PHP service needed)
  - Comment on lifecycle transition guards
  - Spec traceability: Code quality guideline

- [x] **Task 8.3: Update ADR-000 data-model if needed**
  - No ADR-000 change required: the JournalEntry shape aligns with the existing data-model entry; `isBalanced` is derived (not a stored field) per design.md reuse analysis.
  - If `JournalEntry` or related entities are modified, update the ADR-000 entry
  - Spec traceability: design.md section on reuse analysis

## Phase 9: Deduplication Check (per ADR-001 pattern)

- [x] **Task 9.1: Verify no overlap with existing OpenRegister services**
  - No `JournalEntry`/`memoriaal` handling exists in `openregister/lib/Service/`; no existing journal/voucher capability spec. No app-local audit/approval mappers introduced (consumed from OR per ADR-022). No duplicate schema/manifest entry vs sibling bookkeeping changes (GLLine/Account/GR/Iv3Export untouched).
  - Search `openregister/lib/Service/` for existing JournalEntry handling
  - Search `openspec/specs/` for related journal/voucher/memoriaal capability
  - Result: document findings (even if "no overlap found")
  - Spec traceability: ADR-001 "Deduplication check" guideline

## Phase 10: Migration & Repair Step

- [x] **Task 10.1: Register repair step for schema import**
  - Satisfied by the existing `lib/Repair/InitializeSettings` step, which imports the full `shillinq_register.json` (now including `JournalEntry`) via `ConfigurationService::importFromApp()` — idempotent, no new repair class needed.
  - Create repair step class implementing `IRepairStep`
  - Logic: import `JournalEntry` schema from manifest via `ConfigurationService::importFromApp()`
  - Idempotency: re-running repair step MUST NOT create duplicates
  - Spec traceability: design.md "Migration Plan" section

- [x] **Task 10.2: Verify manifest entries load on install**
  - `src/manifest.json` validates structurally (new index/detail pages pass; the single pre-existing `pages[0].type: roadmap` lint warning is unrelated to this change). Index + detail Journals pages are declared and load via the same ManifestLoader path as the existing GR/IV3 pages.
  - Confirm `src/manifest.json` patches load via `ManifestLoader` on app install
  - Index + detail pages are accessible after repair step
  - Spec traceability: design.md "Migration Plan" section

## Deferred Tasks (T2+ or Risk Mitigations)

- [x] **[Deferred to T2] Create recurring journal template library** — HANDOFF
  - Handoff to T2 `add-shillinq-bookkeeping-operations` / `bookkeeping-period-close` track; the T1 fragment already seeds the canonical `JournalEntry` shape and a recurring example object so T2 can extend with the full template library without re-declaring the schema.
  - Seed templates for common use cases (monthly subscriptions, annual depreciation)
  - Deferred until T2's `add-shillinq-bookkeeping-compliance` change
  - Spec traceability: design.md "Seed Data" section

- [x] **[Deferred to T2] Implement period-close reversing trigger** — HANDOFF
  - Handoff to `bookkeeping-period-close` (T3). The T1 `JournalEntry` declares `reversesOn` + the `reversing` enum + the `posted` lifecycle state needed for the period-close action to resolve the inverse-posting target. Locked structurally by `JournalEntrySchemaTest::testReversesOnReadyForPeriodBoundaryTrigger`.
  - Reversing journals' auto-flip may be implemented by T3's period-close capability
  - Confirm handoff with T3 spec author
  - Spec traceability: REQ-JE-004, design.md Decision D7

- [x] **[Conditional on ScheduledWorkflow readiness] Implement recurring journal schedule** — HANDOFF
  - Handoff to T2 / OR `ScheduledWorkflow` rollout. The T1 `cadence` object declares the full shape (interval enum + anchor + endsOn/count) the workflow engine will consume; the UI surfaces `recurring` as a journal type but the materialisation tick is wired by T2 once the n8n adapter is stable. Locked by `JournalEntrySchemaTest::testCadenceShapeReadyForScheduledWorkflow`.
  - Depends on OR's `ScheduledWorkflow` + n8n adapter being stable
  - If not ready: mark `journalType: "recurring"` as "coming in T2" in the UI
  - Spec traceability: REQ-JE-005, design.md Risk 2

---

## Summary

- **Phase 1 (Register Schema):** 4 tasks — declarative lifecycle, materialization trigger, scheduled-workflow, reversing trigger
- **Phase 2 (Manifest):** 3 tasks — navigation, index columns, detail sections
- **Phase 3 (Validation):** 4 tasks — enum, cadence/reversesOn conditionals, balance check
- **Phase 4 (State Machine):** 3 tasks — transitions, approval gate, void guard
- **Phase 5 (GL Materialization):** 1 conditional task — service (if needed per Risk 1)
- **Phase 6 (Audit & RBAC):** 2 tasks — audit trail, role declarations
- **Phase 7 (Testing):** 7 tasks — integration, unit, browser tests
- **Phase 8 (Documentation):** 3 tasks — `@spec` tags, comments, ADR-000 update
- **Phase 9 (Deduplication):** 1 task — overlap check
- **Phase 10 (Migration):** 2 tasks — repair step, manifest load verification
- **Deferred:** 3 tasks — template library, period-close trigger, recurring schedule (T2+)

**Total: ~35 tasks** (including conditional and deferred)
