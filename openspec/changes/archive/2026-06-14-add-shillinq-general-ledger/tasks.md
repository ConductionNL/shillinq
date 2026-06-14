# Tasks — General Ledger

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-general-ledger`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

> **Umbrella status (2026-06-09):** This T1 spec-only change is fully
> covered by the merged `add-shillinq-bookkeeping-foundation` cycle on
> `development`. All eleven tasks land in that cycle's artefacts; this
> change is closed via `[~]` handoff notes per the OPSX umbrella
> convention. No source files are edited by this cycle. See the
> foundation change for the actual deliverable references.

- [x] Task 1: Confirm no `GLTransaction` or `GLLine` schema and no `bookkeeping-general-ledger` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`; catalogue the existing `GeneralLedgerEntry` entry for reconciliation) — **handoff:** done in `add-shillinq-bookkeeping-foundation` Task 0.1 (scan recorded in that change's `context-brief.md`; `GeneralLedgerEntry` catalogued and marked DEPRECATED in `openspec/architecture/adr-000-data-model.md` line 2772-2798) (HANDOFF verified — sibling on dev)
- [x] Task 2: Author `specs/bookkeeping-general-ledger/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T1 (foundation)` / `Depends on: bookkeeping-chart-of-accounts` header, `REQ-GL-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN — declaring the header/line split, balance invariant, period-stamp field, ADR-022 audit ref, ADR-031 lifecycle precondition — **handoff:** done in `add-shillinq-bookkeeping-foundation` Task 1.2; main spec lives at `openspec/specs/bookkeeping-general-ledger/spec.md` (10 REQ-GL requirements with RFC-2119 keywords + GIVEN/WHEN/THEN scenarios) (HANDOFF verified — sibling on dev)
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (Risk 1: declarative balance precondition) / Rollback / Open Questions per shillinq config.yaml `rules.proposal` — **handoff:** this umbrella's own `proposal.md` carries the GL-specific framing (Risk 1 declarative balance precondition, Risk 2 header/line shape lock-in); the foundation cycle's `proposal.md` carries the wider T1 envelope (HANDOFF verified — sibling on dev)
- [x] Task 4: Author `design.md` with Reuse Analysis table per hydra `rules.design`, including D1 header/line split rationale and D2 declarative balance precondition with ADR-031 exception path; bookkeeper persona reads end-to-end and confirms RGS conformance — **handoff:** this umbrella's own `design.md` carries D1+D2; bookkeeper persona walk-through recorded in `openspec/changes/add-shillinq-bookkeeping-foundation/peer-review.md` (HANDOFF verified — sibling on dev)
- [x] Task 5: Declare the `GLTransaction` (header) schema in `lib/Settings/shillinq_register.json` with all REQ-GL-002 fields (transactionNumber, postingDate, periodId, currency, description, sourceReference, state, journalEntryId, administrationId) typed per spec — **handoff:** done in `add-shillinq-bookkeeping-foundation` Task 2.2; schema declared at `lib/Settings/shillinq_register.json:377` carrying all REQ-GL-002 fields (HANDOFF verified — sibling on dev)
- [x] Task 6: Add `x-openregister-lifecycle` to `GLTransaction` declaring `draft → posted` and `posted → reversed` transitions per REQ-GL-004; reversed transactions emit inverse audit event without mutating original lines — **handoff:** done in `add-shillinq-bookkeeping-foundation` Task 2.2; `x-openregister-lifecycle` block on `GLTransaction` declares `draft → posted → reversed` with the audit-trail-immutable ADR-022 extension (HANDOFF verified — sibling on dev)
- [x] Task 7: Implement the balance precondition on `GLTransaction.post`: either declare it inside `x-openregister-lifecycle.requires` (preferred — cross-line aggregation) OR if engine cannot express, register `OCA\Shillinq\Lifecycle\BalanceGuard::isBalanced(string $transactionId): bool` (single-method, ~20 LOC, ADR-031 exception annotated) per REQ-GL-005; file OR issue documenting the gap if exception path is taken — **handoff:** done in `add-shillinq-bookkeeping-foundation` Task 6.1; exception path taken (engine cannot express cross-schema SUM aggregations declaratively); guard lives at `lib/Lifecycle/BalanceGuard.php` with the ADR-031 exception annotation referencing design.md D2 (HANDOFF verified — sibling on dev)
- [x] Task 8: Declare the `GLLine` schema in `lib/Settings/shillinq_register.json` with all REQ-GL-003 fields (transactionId, lineNumber, accountNumber, side, amount, currency, periodId, subLedgerType, subLedgerRef, costCenter, description); `side` enum `["debit", "credit"]`; `amount` non-negative (sign encoded in `side`) — **handoff:** done in `add-shillinq-bookkeeping-foundation` Task 2.3; schema declared at `lib/Settings/shillinq_register.json:534` with `side` enum + `amount` minimum 0 (HANDOFF verified — sibling on dev)
- [x] Task 9: Add `x-openregister-relations` FKs on `GLLine`: `accountNumber → Account.accountNumber` (depends on sibling `add-shillinq-chart-of-accounts` having landed) and `transactionId → GLTransaction.id` — **handoff:** done in `add-shillinq-bookkeeping-foundation` Task 2.3; relations carried on `GLLine` (`accountNumber → Account.accountNumber`, `transactionId → GLTransaction.id`) and confirmed in `openspec/architecture/adr-000-data-model.md:2904-2906` (HANDOFF verified — sibling on dev)
- [x] Task 10: Add General Ledger navigation + pages to `src/manifest.json` (menu entry `Bookkeeping > General Ledger`, `type: index` page binding to `GLTransaction`, `type: detail` page showing GL header + lines together) per REQ-GL-007; `node tests/validate-manifest.js` exits 0 — **handoff:** done in `add-shillinq-bookkeeping-foundation` Task 4.2; `src/manifest.json` carries `Bookkeeping > General Ledger` menu entry plus `/general-ledger` index + `/general-ledger/:id` detail pages bound to `GLTransaction` with embedded `GLLine` grid; `node tests/validate-manifest.js` PASS (structural lint + consistency check) (HANDOFF verified — sibling on dev)
- [x] Task 11: Update `openspec/architecture/adr-000-data-model.md` with reconciliation note: `GeneralLedgerEntry` superseded by `GLLine`; new `GLTransaction` header entity added; T1 split rationale (declarative balance constraint per ADR-031) — **handoff:** done in `add-shillinq-bookkeeping-foundation` Task 5.1; ADR-000 carries the DEPRECATED note on `GeneralLedgerEntry` (line 2772-2798), the `GLLine` entry (line 2880-2911), and the `GLTransaction` entry (line 2913-2937) with the T1 split-rationale reconciliation block (HANDOFF verified — sibling on dev)

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB) confirms the schema shape matches a real RGS-conformant general ledger with balanced double-entry postings. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local audit; balance precondition lives on the schema or as a single-method exception-annotated guard; manifest carries the navigation). If the BalanceGuard path is taken, the guard is exactly one method with the ADR-031 exception annotation linking back to design.md's Declarative-vs-imperative decision table. No source code changes outside `openspec/changes/add-shillinq-general-ledger/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests asserting unbalanced posting fails, balanced posting succeeds, reversed posting emits inverse audit event, GLLine rejects `side: both` and negative amounts (pre-declared on Tasks 5–9); if BalanceGuard path is taken, PHPUnit covers decimal precision edge cases (€0.005 rounding); Playwright MCP browser tests for the General Ledger index + detail pages (pre-declared on Task 10); `composer test` green at the implementing PR's CI gate. No new REST endpoints (OR exposes register CRUD generically).

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/general-ledger.md` per ADR-030 journeydoc convention and commits a GL detail-page screenshot (header + lines) to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `General Ledger`, `Transaction`, `Posting Date`, `Period`, `Debit`, `Credit`, `Balance`, `Draft`, `Posted`, `Reversed`, `Sub-ledger`.
