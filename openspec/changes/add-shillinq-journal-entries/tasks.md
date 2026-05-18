# Tasks — Journal Entries

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-journal-entries`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [ ] Task 1: Confirm no `JournalEntry` schema or `bookkeeping-journal-entries` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`; catalogue the existing ADR-000 `JournalEntry` entry for alignment)
- [ ] Task 2: Author `specs/bookkeeping-journal-entries/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T1 (foundation)` / `Depends on: bookkeeping-chart-of-accounts, bookkeeping-general-ledger` header, `REQ-JE-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN — declaring the three sub-types (manual/recurring/reversing), docudesk source-document FK, and OR approval-workflow integration via `x-openregister-lifecycle.requires`
- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (Risk 1: approval-policy binding, Risk 2: ScheduledWorkflow availability) / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [ ] Task 4: Author `design.md` with Reuse Analysis table per hydra `rules.design`, including D1 human-surface vs materialised-GL rationale, D2 ScheduledWorkflow primitive, D3 docudesk FK URI, D4 OR approval-workflow `requires`; bookkeeper persona reads end-to-end and confirms memoriaal + recurring + reversing UX
- [ ] Task 5: Declare the `JournalEntry` schema in `lib/Settings/shillinq_register.json` with all REQ-JE-002 fields (journalNumber, entryDate, description, lines, sourceDocumentUri, sourceDocumentApp, journalType, cadence, reversesOn, glTransactionId, approvalState, administrationId, state)
- [ ] Task 6: Add `journalType` enum `["manual", "recurring", "reversing"]`; enforce `cadence` required iff `journalType=recurring`, `reversesOn` required iff `journalType=reversing` per REQ-JE-005
- [ ] Task 7: Add `x-openregister-lifecycle` to `JournalEntry` declaring `pending → posted → voided` with `approval-workflow` `requires` (policy bound to `@self.amountPolicy` or static policy name per Risk 1 resolution) per REQ-JE-008
- [ ] Task 8: Add `x-openregister-relations` FKs on `JournalEntry`: `lines[].accountNumber → Account.accountNumber` (depends on sibling `add-shillinq-chart-of-accounts`) and `glTransactionId → GLTransaction.id` (depends on sibling `add-shillinq-general-ledger`)
- [ ] Task 9: Wire recurring-journal materialisation: declare cadence binding to OR `ScheduledWorkflow` primitive (n8n adapter preferred per ADR-031; `occ openregister:scheduled-workflow:run` cron fallback acceptable) per REQ-JE-005; reversing-journal binds to period-close trigger (Tier 3 owns the trigger) per REQ-JE-006
- [ ] Task 10: Wire journal `post` lifecycle action to emit CloudEvent that the OR engine consumes to materialise `GLTransaction` + `GLLine` rows (no PHP orchestration in shillinq) per REQ-JE-004
- [ ] Task 11: Add Journals navigation + pages to `src/manifest.json` (menu entry `Bookkeeping > Journals`, `type: index` page binding to `JournalEntry`, `type: detail` page surfacing `journalType`, `state`, `approvalState`, `sourceDocumentUri`, and the line grid) per REQ-JE-009; `node tests/validate-manifest.js` exits 0
- [ ] Task 12: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation noting the spec supersets the existing `JournalEntry` entry (derived `isBalanced` per ADR-031, deferred `vatAmount` to Tier 5)

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB) confirms the schema shape matches a real memoriaal + recurring + reversing flow with the expected approval UX. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local audit; no app-local approval table; no app-local cron; manifest carries the navigation; source documents are docudesk FK URIs not blobs). No source code changes outside `openspec/changes/add-shillinq-journal-entries/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests covering lifecycle transitions (`pending → posted → voided`), recurring materialisation via the OR scheduled-workflow primitive, reversing journal posts inverse on period boundary, approval gate routes through OR's approval-workflow (pre-declared on Tasks 5–10); Playwright MCP browser tests for the Journals index + detail + create form (pre-declared on Task 11); `composer test` green at the implementing PR's CI gate. No new REST endpoints (OR exposes register CRUD generically).

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/journal-entries.md` per ADR-030 journeydoc convention and commits a Journal create-form screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Journal`, `Journal Entry`, `Memoriaal`, `Manual`, `Recurring`, `Reversing`, `Cadence`, `Reverses On`, `Source Document`, `Approval Pending`, `Approved`, `Posted`, `Voided`.
