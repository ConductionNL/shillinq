# Tasks — Bookkeeping GR Consolidation

Implementation checklist for the Gemeenschappelijke Regeling consolidation feature (T5 bookkeeping surface). All tasks assume Tiers 1–4 are in place (chart of accounts, GL, sub-ledgers, financial reporting).

## Specification & Discovery

- [ ] **Review & approve spec with GR stakeholders** — Ensure REQ-GC-* and REQ-ICP-* match real municipal GR accounting practices. Confirm with a Dutch GR finance officer (e.g., Fenit liaison, VNG contact).
  - Acceptance: Stakeholder sign-off on spec requirements.

- [ ] **Deduplication Check: Verify no overlap with existing OpenRegister services** — Search `openspec/specs/` and `openregister/lib/Service/` for any existing consolidation, elimination, or multi-organization aggregation logic.
  - Check ObjectService, RegisterService, SchemaService, ConfigurationService for consolidation capability.
  - Check @conduction/nextcloud-vue for consolidation UI components.
  - Document findings even if "no overlap found".
  - Acceptance: Signed-off deduplication report or "no overlap found" note.

- [ ] **opsx-ff Discovery: Resolve elimination-rule matching strategy** — Determine whether the OpenRegister lifecycle engine supports complex cross-line matching (account-pair + amount) or if a PHP guard is needed.
  - Test `x-openregister-lifecycle.requires` with account-pair aggregation precondition.
  - If not supported, estimate scope of `lib/Lifecycle/EliminationMatcher.php` guard.
  - Acceptance: Written decision (implementation path chosen, ~lines of PHP if needed).

- [ ] **opsx-ff Discovery: Proportional consolidation scope** — Confirm whether proportional consolidation (50%–99.9% ownership) is required for MVP or deferred.
  - If deferred: remove REQ-GC-004 scenario from spec.
  - If included: model ownership percentage on Organization or ConsolidationGroup.
  - Acceptance: Scope decision documented.

- [ ] **opsx-ff Discovery: Scheduled consolidation trigger** — Determine consolidation trigger mechanism.
  - Option A: On-demand trigger only (manual "Consolidate Now" button).
  - Option B: Scheduled at period close (ScheduledWorkflow integration).
  - Option C: Hybrid (auto-trigger at period close, manual override anytime).
  - Acceptance: Trigger mechanism chosen, workflow documented.

---

## Register & Schema Declaration

- [ ] **Declare ConsolidationGroup schema in `lib/Settings/shillinq_register.json`** — Add schema definition with properties per REQ-GC-001.
  - Properties: name, consolidationMethod (enum), parentOrganization (FK), status, description.
  - Add `x-openregister-lifecycle` with states: active, inactive, archived.
  - Add relation to EliminationRule (one-to-many).
  - Acceptance: Schema validates per JSON Schema spec; openregister CLI accepts register update.

- [ ] **Declare ConsolidatedReport schema in `lib/Settings/shillinq_register.json`** — Add schema definition with properties per REQ-GC-003.
  - Properties: consolidationGroupId (FK), reportDate, consolidationMethod, status (enum: draft, finalized, published, archived), eliminationsApplied, balanceSheetSummary (JSON), incomeStatementSummary (JSON).
  - Add `x-openregister-lifecycle` with state transitions: draft → finalized → published → archived.
  - Add relation to ConsolidationGroup and IntercompanyTransaction.
  - Acceptance: Schema validates; status lifecycle enforced by OR lifecycle engine.

- [ ] **Declare IntercompanyTransaction schema in `lib/Settings/shillinq_register.json`** — Add schema definition with properties per REQ-ICP-001.
  - Properties: consolidationGroupId (FK), fromMemberId (FK), toMemberId (FK), transactionDate, amount, accountFrom, accountTo, reference, description, glTransactionId (FK), eliminationStatus (enum), isManualOverride.
  - Add relation to ConsolidationGroup, Organization (two FK roles), and GLTransaction.
  - Acceptance: Schema validates; foreign keys resolve to correct entities.

- [ ] **Declare EliminationRule schema in `lib/Settings/shillinq_register.json`** — Add schema definition with properties per REQ-ICP-002.
  - Properties: consolidationGroupId (FK), ruleType (enum: auto-match, reference-match, manual-review), accountPairFrom, accountPairTo, amountTolerance, description, isActive.
  - Add relation to ConsolidationGroup (many-to-one).
  - Acceptance: Schema validates; rules can be created, read, updated, archived.

---

## Manifest & Navigation

- [ ] **Add manifest entries for Group Consolidation** — Update `src/manifest.json` with navigation and page definitions.
  - Add menu item: "Consolidation > Group Consolidation" with icon.
  - Add `type: index` page entry (lists all ConsolidationGroups).
  - Add `type: detail` page entry (shows group details, member list, latest report, list of elimination rules).
  - Acceptance: Menu item appears in app navigation; index and detail pages render via @conduction/nextcloud-vue generics.

- [ ] **Add manifest entries for Inter-Company Transactions** — Update `src/manifest.json` with navigation and page definitions.
  - Add menu item: "Consolidation > Inter-Company Transactions" with icon.
  - Add `type: index` page entry (lists all IntercompanyTransactions, filtered by consolidation group and status).
  - Add `type: detail` page entry (shows transaction details, GL reference, elimination status, override notes).
  - Acceptance: Menu items appear; pages render; filtering by group/status works.

---

## Data Seeding & Import

- [ ] **Create seed data file `lib/Settings/seeds/gr-consolidation-examples.json`** — Define example GR consolidation group with members and elimination rules.
  - Include 3 Organization records (example municipalities).
  - Include 1 ConsolidationGroup record (example GR, full consolidation method).
  - Include 3 EliminationRule records (revenue/expense, assets/liabilities, payables/receivables pairs).
  - Include 1 sample IntercompanyTransaction record (pending, awaiting consolidation).
  - Use SPDX header + metadata block per design.md.
  - Use @self envelope for all records.
  - Acceptance: JSON validates; all records have unique slugs.

- [ ] **Register seed import in repair step** — Integrate seed file into `ConfigurationService::importFromApp()` pipeline.
  - Call `ConfigurationService::importFromApp('shillinq', 'gr-consolidation-examples.json', version, force=false)` in repair step.
  - Ensure idempotency: re-import skips existing records matched by slug.
  - Acceptance: Repair step runs without error; seed data loads on first install; re-run does not create duplicates.

---

## Consolidation Logic (Lifecycle Hooks)

- [ ] **Implement consolidation trigger mechanism** — Per opsx-ff discovery decision.
  - If on-demand: expose "Consolidate Now" action on ConsolidationGroup detail page → calls OpenRegister lifecycle action → triggers consolidation workflow.
  - If scheduled: wire ScheduledWorkflow integration (OR's background job system per ADR-031) to trigger at period boundary.
  - Acceptance: Trigger works; ConsolidatedReport is created with correct reportDate and initial status = draft.

- [ ] **Implement GL aggregation for balanceSheetSummary** — Query GL lines, group by account type (assets, liabilities, equity), sum amounts, apply consolidation method (full or proportional), populate JSON snapshot.
  - Input: ConsolidationGroup members + reportDate.
  - Logic: Query GLLine where period <= reportDate; sum by account; apply ownership % if proportional.
  - Output: JSON object `{ assets: {...}, liabilities: {...}, equity: {...} }` with EUR amounts.
  - Acceptance: Manual test with sample data shows correct aggregation.

- [ ] **Implement GL aggregation for incomeStatementSummary** — Query GL lines for revenue and expense accounts, sum by type, apply consolidation method, populate JSON snapshot.
  - Input: ConsolidationGroup members + reportDate.
  - Logic: Query GLLine where period <= reportDate; sum revenue + expense accounts; apply ownership % if proportional.
  - Output: JSON object `{ revenue: {...}, expenses: {...} }` with EUR amounts.
  - Acceptance: Manual test shows correct aggregation.

- [ ] **Implement elimination-rule matching** — Per opsx-ff discovery decision.
  - If declarative (OR lifecycle supports it): wire `x-openregister-lifecycle.requires` precondition to match account pairs + amounts.
  - If PHP guard needed: implement `lib/Lifecycle/EliminationMatcher.php::matchAndEliminate(consolidationGroupId, reportDate)` method.
    - Query IntercompanyTransaction records where consolidationGroupId matches.
    - For each active EliminationRule, find transaction pairs (A→B, B→A) matching (accountFrom, accountTo) within amountTolerance.
    - Mark matched transactions eliminationStatus = "eliminated".
    - Exclude marked transactions from GL aggregations (balanceSheetSummary, incomeStatementSummary).
  - Acceptance: Sample inter-company pair is eliminated correctly; audit trail records the elimination rule applied.

- [ ] **Implement manual override logic** — Allow operators to exclude transactions from elimination or force elimination.
  - If `IntercompanyTransaction.isManualOverride = true`: skip rule matching for this transaction.
  - Expose "Exclude from Elimination" action on Inter-Company Transaction detail page.
  - Expose "Force Eliminate" action on pending Inter-Company Transaction (even if no rule matches).
  - Acceptance: Override action works; audit trail records the override with reason/note.

- [ ] **Implement immutability rule on finalized/published reports** — Prevent modification of IntercompanyTransaction.eliminationStatus if it belongs to a finalized or published ConsolidatedReport.
  - Query: find ConsolidatedReport records where reportId corresponds to this consolidation group + reportDate.
  - Check: if report status is finalized or published, reject updates to eliminationStatus.
  - Acceptance: Attempt to modify a finalized transaction returns 403; error message explains immutability.

---

## Audit & Compliance

- [ ] **Audit trail integration** — Verify OpenRegister audit-trail-immutable captures all events.
  - Test: Create ConsolidationGroup → verify audit entry created with actor, timestamp, before/after.
  - Test: Create ConsolidatedReport → verify audit entry.
  - Test: Run consolidation (trigger elimination rules) → verify audit entries for each eliminated transaction + aggregate "consolidated with N eliminations".
  - Test: Finalize ConsolidatedReport → verify audit entry.
  - Test: Publish ConsolidatedReport → verify audit entry.
  - Acceptance: All events logged; audit trail UI shows complete history.

- [ ] **Spec traceability PHPDoc tags** — Every PHP class and public method MUST have `@spec` tags per ADR-003.
  - All new classes: `@spec openspec/changes/bookkeeping-gr-consolidation/specs/*.md#REQ-*` (link to relevant requirement).
  - File-level `@spec` in header docblock: `@spec openspec/changes/bookkeeping-gr-consolidation/tasks.md#task-N`.
  - Acceptance: `grep -r "@spec" lib/` finds all new code; no orphaned methods.

---

## Frontend & UI

- [ ] **Implement Group Consolidation index page** — Use `CnIndexPage` with `useListView`.
  - List all ConsolidationGroups (columns: name, consolidationMethod, latestReport.reportDate, status).
  - Add action: "New Group" button → opens CnFormDialog for ConsolidationGroup schema.
  - Add action: "Consolidate Now" button on each row → triggers consolidation workflow.
  - Show latest ConsolidatedReport status per group (draft / finalized / published).
  - Acceptance: Page loads; groups listed; create/edit dialogs work; Consolidate Now trigger works.

- [ ] **Implement Group Consolidation detail page** — Use `CnDetailPage` with `CnDetailCard` sections.
  - Section 1: Group header (name, consolidationMethod, status, parent organization).
  - Section 2: Member organizations (list of Organization records linked by parentOrganizationId).
  - Section 3: Elimination rules (list of EliminationRule records, with edit/delete actions).
  - Section 4: Consolidated reports (list of ConsolidatedReport records, with view/finalize/publish actions).
  - Sidebar: Files (attachments), Audit Trail (OpenRegister audit tab).
  - Acceptance: All sections render; member and rule counts are correct; audit trail tab shows events.

- [ ] **Implement Inter-Company Transactions index page** — Use `CnIndexPage` with `useListView`.
  - List all IntercompanyTransaction records (columns: fromMember, toMember, amount, accountFrom↔accountTo, transactionDate, eliminationStatus).
  - Add filters: consolidationGroup (dropdown), eliminationStatus (checkboxes: pending, eliminated, excluded).
  - Add action: "New Transaction" button → opens CnFormDialog for IntercompanyTransaction schema.
  - Add action: "Exclude from Elimination" / "Force Eliminate" on rows (bulk actions).
  - Acceptance: Transactions list loads; filters work; bulk actions apply correctly.

- [ ] **Implement Inter-Company Transactions detail page** — Use `CnDetailPage` with `CnDetailCard` sections.
  - Section 1: Transaction header (fromMember, toMember, amount, accounts, date, reference, description).
  - Section 2: Elimination status (current status, rule matched, override reason if applicable).
  - Section 3: GL reference (link to `glTransactionId` if present; "Not yet posted" if null).
  - Sidebar: Audit Trail (OpenRegister audit tab).
  - Actions: Edit, Delete, "Exclude from Elimination", "Force Eliminate" (context-dependent based on status).
  - Acceptance: All fields display; GL reference link works; elimination actions update status correctly.

- [ ] **Add ConsolidatedReport viewer** — Display frozen snapshot balances (read-only).
  - Show balanceSheetSummary as a table (Assets, Liabilities, Equity with EUR totals).
  - Show incomeStatementSummary as a table (Revenue, Expenses with EUR totals).
  - Show elimination count: "X inter-company transactions eliminated".
  - Show status badge (Draft / Finalized / Published).
  - Actions: Finalize (if draft), Publish (if finalized), Archive (if published).
  - Acceptance: Snapshots display correctly; status transitions work; immutability rule prevents editing.

---

## Testing & Verification

- [ ] **Unit tests for EliminationMatcher (if PHP guard needed)** — Test matching logic with sample data.
  - Test case: Exact account-pair + amount match → transaction marked eliminated.
  - Test case: Amount tolerance threshold (e.g., ±0.01) → transaction within tolerance marked eliminated.
  - Test case: No matching rule → transaction remains pending.
  - Test case: Manual override isManualOverride = true → transaction excluded even if rule matches.
  - Acceptance: All tests pass; coverage ≥80%.

- [ ] **Integration test: Full consolidation workflow** — Test end-to-end from group creation through report finalization.
  - Precondition: Two member organizations with GL postings.
  - Step 1: Create ConsolidationGroup.
  - Step 2: Create EliminationRule for inter-company account pair.
  - Step 3: Create IntercompanyTransaction records matching the rule.
  - Step 4: Run consolidation → ConsolidatedReport created.
  - Step 5: Verify GL aggregation (balanceSheetSummary, incomeStatementSummary) excludes eliminated transactions.
  - Step 6: Finalize report → status = finalized.
  - Step 7: Verify elimination status is now immutable.
  - Acceptance: Workflow completes; GL totals are correct; immutability enforced.

- [ ] **Integration test: Proportional consolidation (if in MVP)** — Test aggregation with ownership percentages.
  - Precondition: ConsolidationGroup with consolidationMethod = "proportional", member with 75% ownership.
  - Step 1: Member has assets of €100,000.
  - Step 2: Run consolidation.
  - Step 3: Verify balanceSheetSummary includes €75,000 (75% of €100,000).
  - Acceptance: Proportional aggregation calculates correctly.

- [ ] **Browser test: Group Consolidation UI** — Automated UI testing with Playwright.
  - Test case: Create new consolidation group.
  - Test case: Add elimination rules.
  - Test case: Trigger consolidation.
  - Test case: View consolidated report.
  - Test case: Finalize and publish report.
  - Acceptance: All UI interactions work; no console errors.

- [ ] **Browser test: Inter-Company Transactions UI** — Automated UI testing with Playwright.
  - Test case: Create new inter-company transaction.
  - Test case: Edit transaction (before consolidation).
  - Test case: Exclude transaction from elimination.
  - Test case: Force eliminate pending transaction.
  - Test case: Verify immutability (attempt to modify after report finalized).
  - Acceptance: All UI interactions work; immutability error displays correctly.

- [ ] **Manual smoke testing** — Before opening PR, verify:
  - [ ] Create a ConsolidationGroup via UI → verify it persists and appears in list.
  - [ ] Create an EliminationRule via UI → verify it links to the group.
  - [ ] Create an IntercompanyTransaction via UI → verify it appears in list with pending status.
  - [ ] Click "Consolidate Now" on a group → verify ConsolidatedReport is created and status = draft.
  - [ ] View the ConsolidatedReport → verify balanceSheetSummary and incomeStatementSummary are populated (not empty JSON).
  - [ ] Verify eliminated transactions are excluded from the report totals.
  - [ ] Click "Finalize" on the report → verify status = finalized and further edits are blocked.
  - [ ] Verify audit trail shows all events (creation, elimination, finalization).
  - Acceptance: All smoke tests pass; no 403/500 errors.

---

## Documentation

- [ ] **Update ADR-000 data model** — Add annotation reconciling ConsolidationGroup, ConsolidatedReport, and IntercompanyTransaction.
  - Note: These entities are declared in bookkeeping-gr-consolidation spec (T5).
  - Link: `### [spec: bookkeeping-group-consolidation, bookkeeping-intercompany-posting]`
  - Acceptance: ADR-000 updated; entries marked with spec link.

- [ ] **Add spec-level README** — Brief summary of GR consolidation in human-readable format.
  - File: `docs/features/consolidation.md` (or similar).
  - Content: What is a GR, why consolidation matters, how to set up a group, how elimination works.
  - Include screenshots of index/detail pages.
  - Acceptance: README is clear to a Dutch municipal finance officer.

---

## Final Checks

- [ ] **All tasks in this list are completed and verified** — Sign off on each task before opening PR.
- [ ] **No deferred work in task descriptions** — All acceptance criteria are met; no TODO comments in code.
- [ ] **Deduplication check passed** — No overlap with existing OpenRegister services documented.
- [ ] **Spec review sign-off** — GR stakeholder has approved spec requirements.
- [ ] **All tests passing** — `composer check:strict` + browser tests + smoke tests.
- [ ] **PR description drafted** — Summarizes changes, references spec, links to related issues (if any).

---

## Notes

- **Elimination-rule matching complexity** — If the OpenRegister lifecycle engine cannot express cross-line aggregations in preconditions, implement `lib/Lifecycle/EliminationMatcher.php` as a thin guard called by the lifecycle engine (per ADR-031). This adds ~40 LOC but keeps the core logic declarative.
- **Performance at scale** — With 50+ members, consolidation may take seconds to aggregate GL + apply eliminations. Consider materialization caching (ConsolidatedReport JSON is immutable once finalized) and async consolidation runs (scheduled via OR's background job system).
- **Future enhancements** — Equity consolidation (associates), multi-currency translation, subsidiary acquisition accounting, statutory filing formats (SBR/XBRL) are explicit out-of-scope for this spec; record them on the T5+ roadmap.
