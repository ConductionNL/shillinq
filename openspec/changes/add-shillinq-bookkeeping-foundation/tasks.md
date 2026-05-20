# Tasks — Bookkeeping Foundation (T1)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the three spec deltas — they
> are recorded now so the spec-review gate, dependency planning, and
> tier-cascade impact are all visible at proposal time. No source files
> are edited by this change itself.

## 0. Deduplication Check

- [ ] Task 0.1: Confirm no T1 schema or capability already exists — scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, and `openspec/architecture/adr-000-data-model.md`; catalogue existing ADR-000 entries for `Account`, `GeneralLedgerAccount`, `GeneralLedgerEntry`, `JournalEntry`, `FiscalYear`; confirm only the placeholder `example` schema is declared in the register file

## 1. Spec foundation (this change)

- [x] Task 1.1: Author `specs/bookkeeping-chart-of-accounts/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T1 (foundation)` / `Depends on: none` header, `REQ-CoA-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN (exactly 4 hashtags per scenario header)
- [x] Task 1.2: Author `specs/bookkeeping-general-ledger/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T1 (foundation)` / `Depends on: bookkeeping-chart-of-accounts` header; declares the header/line split (`GLTransaction` + `GLLine`), the balance invariant, the period-stamp field, and references ADR-022 for audit and ADR-031 for the lifecycle precondition
- [x] Task 1.3: Author `specs/bookkeeping-journal-entries/spec.md` with `Depends on: bookkeeping-general-ledger`; declares the three sub-types (manual / recurring / reversing), the docudesk source-document FK, and the OR approval-workflow integration via `x-openregister-lifecycle.requires`
- [x] Task 1.4: Author `proposal.md` + `design.md` for the change envelope; `proposal.md` references the shared `nextcloud-app` spec and includes Affected Projects / Scope / Risks / Rollback / Open Questions; `design.md` includes a Reuse Analysis table, Declarative-vs-imperative decision table, and Seed Data section

---

## (The following tasks are recorded for the downstream `opsx-apply` cycle, not for this spec-only change.)

## 2. Register declarations — `lib/Settings/shillinq_register.json`

- [ ] Task 2.1: Declare the `Account` schema — all REQ-CoA-002 fields (`accountNumber`, `name`, `accountType`, `currency`, `parentAccountNumber`, `isClosingAccount`, `administrationId`, `lifecycleState`, `description`) with the typing the spec mandates; add `x-openregister-lifecycle` block with `active → blocked`, `active → archived`, `blocked → archived` transitions from REQ-CoA-005; add `x-openregister-relations` self-relation per REQ-CoA-003
- [ ] Task 2.2: Declare the `GLTransaction` schema — header fields per REQ-GL-002 (`transactionNumber`, `postingDate`, `periodId`, `currency`, `description`, `sourceReference`, `state`, `journalEntryId`, `administrationId`); lifecycle `draft → posted → reversed` per REQ-GL-004; balance-invariant precondition on `post` per REQ-GL-005 (declarative or PHP guard per ADR-031 exception path — design.md open question 1 resolves which)
- [ ] Task 2.3: Declare the `GLLine` schema — fields per REQ-GL-003 (`transactionId`, `lineNumber`, `accountNumber`, `side`, `amount`, `currency`, `periodId`, `subLedgerType`, `subLedgerRef`, `costCenter`, `description`); `side` enum of `["debit", "credit"]`; `amount` non-negative
- [ ] Task 2.4: Declare the `JournalEntry` schema — fields per REQ-JE-002 (`journalNumber`, `entryDate`, `description`, `lines`, `sourceDocumentUri`, `sourceDocumentApp`, `journalType`, `cadence`, `reversesOn`, `glTransactionId`, `approvalState`, `administrationId`, `state`); lifecycle `draft → pending → posted → voided` with approval-workflow `requires` per REQ-JE-008; `journalType` enum `["manual", "recurring", "reversing"]`; `cadence` conditional on `journalType: "recurring"`

## 3. Seed data — `lib/Settings/seeds/`

- [ ] Task 3.1: Ship `lib/Settings/seeds/rgs-3.5-mkb.json` SMB template — JSON array of `Account` records conforming to the `Account` schema; SPDX header (EUPL-1.2 + Copyright Conduction B.V.); `_meta` block with `source: "RGS 3.5"`, `variant: "mkb"` per REQ-CoA-006
- [ ] Task 3.2: Ship `lib/Settings/seeds/rgs-3.5-zzp.json` ZZP template — same shape as mkb; `_meta.variant: "zzp"` per REQ-CoA-006
- [ ] Task 3.3: Ship `lib/Settings/seeds/rgs-bbv.json` BBV government template — same shape; `_meta.variant: "bbv"` per REQ-CoA-006
- [ ] Task 3.4: Extend the repair step under `lib/Migration/` to import the selected RGS template idempotently via `ConfigurationService::importFromApp()`; operator edits persist across repair re-runs; the repair step does not re-overwrite seeded records per REQ-CoA-007

## 4. Manifest navigation — `src/manifest.json`

- [ ] Task 4.1: Add Chart of Accounts navigation + pages — menu entry `Bookkeeping > Grootboekschema` (or top-level per UX review), `type: index` page binding to the `Account` register, `type: detail` page for individual accounts per REQ-CoA-008; `node tests/validate-manifest.js` exits 0
- [ ] Task 4.2: Add General Ledger navigation + pages — menu entry `Bookkeeping > Grootboek`, `type: index` + `type: detail` pages binding to `GLTransaction` (detail page shows GL header + lines) per REQ-GL-007; `validate-manifest.js` exits 0
- [ ] Task 4.3: Add Journals navigation + pages — menu entry `Bookkeeping > Journaalposten`, `type: index` + `type: detail` pages binding to `JournalEntry`; detail page surfaces `journalType`, `state`, `approvalState`, `sourceDocumentUri`, and the line grid per REQ-JE-009; `validate-manifest.js` exits 0

## 5. ADR-000 reconciliation note

- [ ] Task 5.1: Update `openspec/architecture/adr-000-data-model.md` — add one-paragraph note to the `GeneralLedgerEntry` section: "Superseded by `GLLine` from `bookkeeping-general-ledger`; T1 split the flat entry into header (`GLTransaction`) + line (`GLLine`) to make the balance constraint declarative per ADR-031"; add reconciliation paragraph to `Account` and `GeneralLedgerAccount` sections per design.md Reuse Analysis

## 6. Lifecycle guard (conditional — only if Risk 1 confirms)

- [ ] Task 6.1 (conditional): Author `lib/Lifecycle/BalanceGuard.php` — exactly one method `isBalanced(string $transactionId): bool`; referenced from `x-openregister-lifecycle.requires` on the `GLTransaction.post` transition; carries the ADR-031 exception annotation linking back to design.md's Declarative-vs-imperative decision table. Only implement if the `opsx-ff` discovery step confirms the engine cannot express the cross-line balance constraint declaratively.

## Verification

- [ ] All Section 1 tasks (this change's own deliverables) checked off
- [ ] `openspec validate` exits clean on the change folder
- [ ] Manual peer review by a competent Dutch bookkeeper persona (e.g. `/test-persona-janwillem` for SMB, or a domain-expert review) confirms the schema shape matches a real RGS-conformant ledger
- [ ] Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local audit; no app-local approval table; no service-class state machines; manifest carries the navigation)
- [ ] No source code changes outside `openspec/changes/add-shillinq-bookkeeping-foundation/`

## Tests (company-wide ADR-009)

- [ ] N/A for the spec change itself — no business logic ships
- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — declared on tasks 2.1, 2.2, 2.3, 2.4, 3.4, 6.1; lands with implementation cycle
- [ ] Newman/Postman tests for new/changed API endpoints — no new endpoints in T1 (OR exposes register CRUD generically; tests cover the register HTTP surface)
- [ ] Browser tests (Playwright MCP) for UI changes — declared on tasks 4.1, 4.2, 4.3; lands with implementation cycle
- [ ] All tests pass (`composer test`) — enforced at implementing PR's CI gate

## Documentation (company-wide ADR-010)

- [ ] N/A for the spec change itself
- [ ] Feature documentation updated in `docs/` — `docs/user-guide/bookkeeping/` index + per-capability pages (grootboekschema, grootboek, journaalposten) authored during implementation cycle per ADR-030 journeydoc convention
- [ ] Screenshots captured and committed to `docs/images/` — 3 screenshots: CoA index, GL detail, Journal create form; authored during implementation cycle

## i18n (company-wide ADR-005)

- [ ] N/A for the spec change itself
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added during implementation cycle — required terms: `Boekhouding`, `Grootboekschema`, `Grootboek`, `Journaalboeking`, `Rekening`, `Debet`, `Credit`, `Balans`, `Geboekt`, `Teruggeboekt`, `Goedkeuring vereist`, `Herhalend`, `Terugboeken`
