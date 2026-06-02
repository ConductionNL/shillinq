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

- [ ] **Task 2.1: Add Journal Entries navigation entry in `src/manifest.json`**
  - Add a navigation group `Bookkeeping > Journals` (or `Journaalposten` in Dutch)
  - Add `type: index` page binding to the `JournalEntry` register
  - Add `type: detail` page binding to the `JournalEntry` register
  - Use `@conduction/nextcloud-vue` `CnIndexPage` and `CnDetailPage` components
  - No bespoke Vue files for journal entry CRUD
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-009`

- [ ] **Task 2.2: Configure manifest index page columns (REQ-JE-009)**
  - Columns: `journalNumber`, `entryDate`, `description`, `journalType`, `state`, `approvalState`
  - Sorting: default by `entryDate` descending
  - Filtering: by `journalType`, `state`, `approvalState`
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-009`

- [ ] **Task 2.3: Configure manifest detail page sections**
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

- [ ] **Task 3.4: Implement line-balance validation for posting (REQ-GL-005 consumer)**
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

- [ ] **Task 4.2: Implement approval-workflow gate on post transition (REQ-JE-008)**
  - Call OR's approval-workflow service to determine if approval is required
  - If required: create approval task; journal moves to `pending`
  - If not required: approve immediately; journal moves to `posted`
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-008`

- [ ] **Task 4.3: Implement void transition guard: GL transaction must be reversed (REQ-JE-010)**
  - On `posted → voided`: check that the materialised `GLTransaction` has a corresponding
    reverse transaction per REQ-GL-004
  - If no reverse exists: fail with (Dutch) "Storneer eerst de grootboektransactie"
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-010`

## Phase 5: GL Materialization (Risk 1 Path)

- [ ] **Task 5.1: (Conditional) Create BookkeepingMaterializationService if declarative not possible**
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

- [ ] **Task 6.1: Verify audit trail consumption from OR (REQ-JE-001)**
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

- [ ] **Task 7.1: Create integration test: manual journal to GL materialization (REQ-JE-007)**
  - Test: create draft manual journal → post → verify GL transaction created and posted
  - Assertions: `glTransactionId` set, GL lines match journal lines, balance verified
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-007`

- [ ] **Task 7.2: Create integration test: unbalanced journal rejection (REQ-JE-007)**
  - Test: create unbalanced journal → attempt post → verify rejection with error message
  - Assertions: GL transaction NOT created, journal state remains `draft`
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-007`

- [ ] **Task 7.3: Create integration test: approval gate (REQ-JE-008)**
  - Test: post journal above approval threshold → verify pending state and approval task created
  - Test: approver approves → verify journal posted and GL materialized
  - Test: approver rejects → verify journal back to draft
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-008`

- [ ] **Task 7.4: Create integration test: recurring journal cadence (REQ-JE-005)**
  - (Only if `ScheduledWorkflow` is ready in T1)
  - Test: create recurring journal with monthly cadence → scheduled-workflow fires → verify GL transaction created
  - Assertions: `journalEntryId` set on GL transaction, correct materialisation count
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-005`

- [ ] **Task 7.5: Create integration test: reversing journal at period boundary (REQ-JE-004)**
  - (Only if period-boundary trigger is ready in T1)
  - Test: post reversing journal in December with `reversesOn: "2027-01"` → advance to Jan → verify inverse GL transaction created
  - Assertions: `reversesTransactionId` set, inverse posting lines have opposite sides
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-004`

- [ ] **Task 7.6: Create unit test: schema validation (REQ-JE-002, REQ-JE-003, REQ-JE-005)**
  - Minimal valid manual journal passes validation
  - Missing `cadence` for recurring journal fails
  - Missing `reversesOn` for reversing journal fails
  - Unknown `journalType` fails
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-002` through `REQ-JE-005`

- [ ] **Task 7.7: Browser test: manifest pages render correctly (REQ-JE-009)**
  - Navigate to `/index.php/apps/shillinq/journals` → verify index page with columns
  - Create a journal → verify detail page renders header + lines + approval section
  - Post a journal → verify GL link appears
  - Spec traceability: `@spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md#REQ-JE-009`

## Phase 8: Documentation & Code Quality

- [ ] **Task 8.1: Add `@spec` PHPDoc tags to all classes and public methods**
  - File-level `@spec` in class docblock: `@spec openspec/changes/bookkeeping-journal-entries/...`
  - Method-level `@spec` for every public method
  - Links trace code → spec requirements
  - Spec traceability: ADR-003 backend guideline

- [ ] **Task 8.2: Add inline comments explaining non-obvious logic**
  - Comment on balance-check logic
  - Comment on GL materialization trigger (if PHP service needed)
  - Comment on lifecycle transition guards
  - Spec traceability: Code quality guideline

- [ ] **Task 8.3: Update ADR-000 data-model if needed**
  - If `JournalEntry` or related entities are modified, update the ADR-000 entry
  - Spec traceability: design.md section on reuse analysis

## Phase 9: Deduplication Check (per ADR-001 pattern)

- [ ] **Task 9.1: Verify no overlap with existing OpenRegister services**
  - Search `openregister/lib/Service/` for existing JournalEntry handling
  - Search `openspec/specs/` for related journal/voucher/memoriaal capability
  - Result: document findings (even if "no overlap found")
  - Spec traceability: ADR-001 "Deduplication check" guideline

## Phase 10: Migration & Repair Step

- [ ] **Task 10.1: Register repair step for schema import**
  - Create repair step class implementing `IRepairStep`
  - Logic: import `JournalEntry` schema from manifest via `ConfigurationService::importFromApp()`
  - Idempotency: re-running repair step MUST NOT create duplicates
  - Spec traceability: design.md "Migration Plan" section

- [ ] **Task 10.2: Verify manifest entries load on install**
  - Confirm `src/manifest.json` patches load via `ManifestLoader` on app install
  - Index + detail pages are accessible after repair step
  - Spec traceability: design.md "Migration Plan" section

## Deferred Tasks (T2+ or Risk Mitigations)

- [ ] **[Deferred to T2] Create recurring journal template library**
  - Seed templates for common use cases (monthly subscriptions, annual depreciation)
  - Deferred until T2's `add-shillinq-bookkeeping-compliance` change
  - Spec traceability: design.md "Seed Data" section

- [ ] **[Deferred to T2] Implement period-close reversing trigger**
  - Reversing journals' auto-flip may be implemented by T3's period-close capability
  - Confirm handoff with T3 spec author
  - Spec traceability: REQ-JE-004, design.md Decision D7

- [ ] **[Conditional on ScheduledWorkflow readiness] Implement recurring journal schedule**
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
