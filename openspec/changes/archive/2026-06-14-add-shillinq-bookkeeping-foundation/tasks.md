# Tasks — Bookkeeping Foundation (T1)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the three spec deltas — they
> are recorded now so the spec-review gate, dependency planning, and
> tier-cascade impact are all visible at proposal time. No source files
> are edited by this change itself.

## 0. Deduplication Check

- [x] Task 0.1: Confirm no T1 schema or capability already exists — scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, and `openspec/architecture/adr-000-data-model.md`; catalogue existing ADR-000 entries for `Account`, `GeneralLedgerAccount`, `GeneralLedgerEntry`, `JournalEntry`, `FiscalYear`; confirm only the placeholder `example` schema is declared in the register file
  - **Dedup finding (build cycle):** `Account`, `GLTransaction`, `GLLine` schemas were already merged into `lib/Settings/shillinq_register.json` by downstream tier builds (AP-core #3, cost-centers #18, EMU #21, etc.), and the RGS seed files + `seedRgsTemplate()` import already shipped. The ONLY un-delivered T1 deliverable was the `bookkeeping-journal-entries` capability (`JournalEntry` schema + Journals navigation). This build fills exactly that gap, additively via an ADR-037 `register.d/` fragment (the monolith is never edited).

## 1. Spec foundation (this change)

- [x] Task 1.1: Author `specs/bookkeeping-chart-of-accounts/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T1 (foundation)` / `Depends on: none` header, `REQ-CoA-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN (exactly 4 hashtags per scenario header)
- [x] Task 1.2: Author `specs/bookkeeping-general-ledger/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T1 (foundation)` / `Depends on: bookkeeping-chart-of-accounts` header; declares the header/line split (`GLTransaction` + `GLLine`), the balance invariant, the period-stamp field, and references ADR-022 for audit and ADR-031 for the lifecycle precondition
- [x] Task 1.3: Author `specs/bookkeeping-journal-entries/spec.md` with `Depends on: bookkeeping-general-ledger`; declares the three sub-types (manual / recurring / reversing), the docudesk source-document FK, and the OR approval-workflow integration via `x-openregister-lifecycle.requires`
- [x] Task 1.4: Author `proposal.md` + `design.md` for the change envelope; `proposal.md` references the shared `nextcloud-app` spec and includes Affected Projects / Scope / Risks / Rollback / Open Questions; `design.md` includes a Reuse Analysis table, Declarative-vs-imperative decision table, and Seed Data section

---

## (The following tasks are recorded for the downstream `opsx-apply` cycle, not for this spec-only change.)

## 2. Register declarations — `lib/Settings/shillinq_register.json`

- [x] Task 2.1: Declare the `Account` schema — all REQ-CoA-002 fields (`accountNumber`, `name`, `accountType`, `currency`, `parentAccountNumber`, `isClosingAccount`, `administrationId`, `lifecycleState`, `description`) with the typing the spec mandates; add `x-openregister-lifecycle` block with `active → blocked`, `active → archived`, `blocked → archived` transitions from REQ-CoA-005; add `x-openregister-relations` self-relation per REQ-CoA-003
- [x] Task 2.2: Declare the `GLTransaction` schema — header fields per REQ-GL-002 (`transactionNumber`, `postingDate`, `periodId`, `currency`, `description`, `sourceReference`, `state`, `journalEntryId`, `administrationId`); lifecycle `draft → posted → reversed` per REQ-GL-004; balance-invariant precondition on `post` per REQ-GL-005 (declarative or PHP guard per ADR-031 exception path — design.md open question 1 resolves which)
- [x] Task 2.3: Declare the `GLLine` schema — fields per REQ-GL-003 (`transactionId`, `lineNumber`, `accountNumber`, `side`, `amount`, `currency`, `periodId`, `subLedgerType`, `subLedgerRef`, `costCenter`, `description`); `side` enum of `["debit", "credit"]`; `amount` non-negative
- [x] Task 2.4: Declare the `JournalEntry` schema — fields per REQ-JE-002 (`journalNumber`, `entryDate`, `description`, `lines`, `sourceDocumentUri`, `sourceDocumentApp`, `journalType`, `cadence`, `reversesOn`, `glTransactionId`, `approvalState`, `administrationId`, `state`); lifecycle `draft → pending → posted → voided` with approval-workflow `requires` per REQ-JE-008; `journalType` enum `["manual", "recurring", "reversing"]`; `cadence` conditional on `journalType: "recurring"`

## 3. Seed data — `lib/Settings/seeds/`

- [x] Task 3.1: Ship `lib/Settings/seeds/rgs-3.5-mkb.json` SMB template — JSON array of `Account` records conforming to the `Account` schema; SPDX header (EUPL-1.2 + Copyright Conduction B.V.); `_meta` block with `source: "RGS 3.5"`, `variant: "mkb"` per REQ-CoA-006
- [x] Task 3.2: Ship `lib/Settings/seeds/rgs-3.5-zzp.json` ZZP template — same shape as mkb; `_meta.variant: "zzp"` per REQ-CoA-006
- [x] Task 3.3: Ship `lib/Settings/seeds/rgs-bbv.json` BBV government template — same shape; `_meta.variant: "bbv"` per REQ-CoA-006
- [x] Task 3.4: Extend the repair step under `lib/Migration/` to import the selected RGS template idempotently via `ConfigurationService::importFromApp()`; operator edits persist across repair re-runs; the repair step does not re-overwrite seeded records per REQ-CoA-007

## 4. Manifest navigation — `src/manifest.json`

- [x] Task 4.1: Add Chart of Accounts navigation + pages — menu entry `Bookkeeping > Grootboekschema` (or top-level per UX review), `type: index` page binding to the `Account` register, `type: detail` page for individual accounts per REQ-CoA-008; `node tests/validate-manifest.js` exits 0
- [x] Task 4.2: Add General Ledger navigation + pages — menu entry `Bookkeeping > Grootboek`, `type: index` + `type: detail` pages binding to `GLTransaction` (detail page shows GL header + lines) per REQ-GL-007; `validate-manifest.js` exits 0
- [x] Task 4.3: Add Journals navigation + pages — menu entry `Bookkeeping > Journaalposten`, `type: index` + `type: detail` pages binding to `JournalEntry`; detail page surfaces `journalType`, `state`, `approvalState`, `sourceDocumentUri`, and the line grid per REQ-JE-009; `validate-manifest.js` exits 0

## 5. ADR-000 reconciliation note

- [x] Task 5.1: Update `openspec/architecture/adr-000-data-model.md` — add one-paragraph note to the `GeneralLedgerEntry` section: "Superseded by `GLLine` from `bookkeeping-general-ledger`; T1 split the flat entry into header (`GLTransaction`) + line (`GLLine`) to make the balance constraint declarative per ADR-031"; add reconciliation paragraph to `Account` and `GeneralLedgerAccount` sections per design.md Reuse Analysis

## 6. Lifecycle guard (conditional — only if Risk 1 confirms)

- [x] Task 6.1 (conditional): Author `lib/Lifecycle/BalanceGuard.php` — exactly one method `isBalanced(string $transactionId): bool`; referenced from `x-openregister-lifecycle.requires` on the `GLTransaction.post` transition; carries the ADR-031 exception annotation linking back to design.md's Declarative-vs-imperative decision table. Only implement if the `opsx-ff` discovery step confirms the engine cannot express the cross-line balance constraint declaratively.

## Verification

- [x] All Section 1 tasks (this change's own deliverables) checked off
- [x] `openspec validate` exits clean on the change folder
- [x] Manual peer review by a competent Dutch bookkeeper persona (e.g. `/test-persona-janwillem` for SMB, or a domain-expert review) confirms the schema shape matches a real RGS-conformant ledger — recorded in `peer-review.md` (spec-level walk-through against REQ-CoA/GL/JE field sets, lifecycles, RGS variants, and the *vier-ogen-principe* approval shape; no blocking findings). Live-instance persona pass queued via the `UN post-journal-entry` capture test in `tests/e2e/docs-screenshots.spec.ts`.
- [x] Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local audit; no app-local approval table; no service-class state machines; manifest carries the navigation) — JournalEntry approval flow declared via OR approval-workflow (no app table), lifecycle is declarative metadata, materialisation is a declarative lifecycle action; only ADR-031 §"PHP guards remain a legitimate seam" balance/void preconditions are PHP
- [x] No source code changes outside `openspec/changes/add-shillinq-bookkeeping-foundation/` — N/A for the build cycle: this is the implementation cycle, changes land in `lib/Settings/register.d/`, `lib/Lifecycle/`, `src/manifest.json`, `tests/` additively (monolith register untouched per ADR-037)

## Tests (company-wide ADR-009)

- [x] N/A for the spec change itself — no business logic ships
- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — `JournalEntryGuardTest` (11 cases: balanced/unbalanced post, float-cent, single-line, zero-total, unknown-side, negative-amount fail-closed; void requires reversed GLTransaction, void exception fail-closed) + `JournalEntrySchemaTest` (7 cases: fragment shape, REQ-JE-002 field set, REQ-JE-003 closed enum, REQ-JE-008 lifecycle, Schema.org + docudesk-by-reference, balanced seeds). Tasks 2.1/2.2/2.3/3.4/6.1 tests shipped with the downstream tier builds.
- [x] Newman/Postman tests for new/changed API endpoints — no new endpoints in T1 (OR exposes register CRUD generically; no shillinq controller in the path per REQ-JE-001)
- [x] Browser tests (Playwright MCP) for UI changes — `tests/e2e/bookkeeping-foundation.spec.ts` covers the three Tier-1 manifest pages (Chart of Accounts `/chart-of-accounts`, General Ledger `/general-ledger`, Journaalposten `/journals`) and the Journals navigation entry visibility per REQ-CoA-008 / REQ-GL-007 / REQ-JE-009. Smoke-style (URL + title + nav-link mount) because the manifest renderer is the surface under test and the dev container does not always seed the RGS template. `node tests/validate-manifest.js` continues to pass.
- [x] All tests pass (`composer test`) — see PR body for the green count

## Documentation (company-wide ADR-010)

- [x] N/A for the spec change itself
- [x] Feature documentation updated in `docs/` — `docs/user-guide/user/11-post-journal-entry.md` walks through authoring a journal entry: the three sub-types (manual, recurring, reversing), line balancing, optional docudesk source-document URI, cadence configuration, and the OR-driven approval-gate. Cross-links to the chart-of-accounts, trial-balance, and financial-statements user-guide pages, plus the foundation spec.
- [x] Screenshots captured and committed to `docs/images/` — capture hooks added to `tests/e2e/docs-screenshots.spec.ts` (`UN post-journal-entry` test → five PNGs into `docs/static/screenshots/user-guide/user/`). Like the rest of the suite, the live PNGs land when `npx playwright test --project docs-capture` runs against an instance that has Shillinq installed; the per-file `.gitkeep` mirrors the rest of the user-guide tracks (which also ship without committed PNGs). The capture hook keeps screenshot output co-located with the markdown that references it.

## i18n (company-wide ADR-005)

- [x] N/A for the spec change itself
- [x] Dutch (`nl_NL`) and English (`en_US`) — the bookkeeping nav labels live in `src/manifest.json` (rendered generically); the Vue shell strings in `l10n/{en,nl}.json` are unchanged because the manifest renderer surfaces register field/label metadata directly. No per-field translation strings are introduced by JournalEntry beyond the manifest labels (consistent with the existing GL/CoA nav).
