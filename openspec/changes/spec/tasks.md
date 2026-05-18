# Tasks — General Ledger

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-general-ledger`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `GLTransaction` or `GLLine` schema and no `bookkeeping-general-ledger` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`; catalogue the existing `GeneralLedgerEntry` entry for reconciliation)
- [x] Task 2: Author `specs/bookkeeping-general-ledger/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T1 (foundation)` / `Depends on: bookkeeping-chart-of-accounts` header, `REQ-GL-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN — declaring the header/line split, balance invariant, period-stamp field, ADR-022 audit ref, ADR-031 lifecycle precondition
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (Risk 1: declarative balance precondition) / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [x] Task 4: Author `design.md` with Reuse Analysis table per hydra `rules.design`, including D1 header/line split rationale and D2 declarative balance precondition with ADR-031 exception path; bookkeeper persona reads end-to-end and confirms RGS conformance
- [x] Task 5: Declare the `GLTransaction` (header) schema in `lib/Settings/shillinq_register.json` with all REQ-GL-002 fields (transactionNumber, postingDate, periodId, currency, description, sourceReference, state, journalEntryId, administrationId) typed per spec
- [x] Task 6: Add `x-openregister-lifecycle` to `GLTransaction` declaring `draft → posted` and `posted → reversed` transitions per REQ-GL-004; reversed transactions emit inverse audit event without mutating original lines
- [x] Task 7: Implement the balance precondition on `GLTransaction.post`: ADR-031 exception path taken — `OCA\Shillinq\Lifecycle\BalanceGuard::isBalanced` registered in `x-openregister-lifecycle.transitions.post.requires` (cross-schema SUM aggregation not yet expressible declaratively in OR engine); OR issue to be filed
- [x] Task 8: Declare the `GLLine` schema in `lib/Settings/shillinq_register.json` with all REQ-GL-003 fields (transactionId, lineNumber, accountNumber, side, amount, currency, periodId, subLedgerType, subLedgerRef, costCenter, description); `side` enum `["debit", "credit"]`; `amount` non-negative (sign encoded in `side`)
- [x] Task 9: Add `x-openregister-relations` FKs on `GLLine`: `accountNumber → Account.accountNumber` (depends on sibling `add-shillinq-chart-of-accounts` having landed) and `transactionId → GLTransaction.id`
- [x] Task 10: Add General Ledger navigation + pages to `src/manifest.json` (menu entry `Bookkeeping > General Ledger`, `type: index` page binding to `GLTransaction`, `type: detail` page showing GL header + lines together) per REQ-GL-007
- [x] Task 11: Update `openspec/architecture/adr-000-data-model.md` with reconciliation note: `GeneralLedgerEntry` superseded by `GLLine`; new `GLTransaction` header entity added; T1 split rationale (declarative balance constraint per ADR-031)

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB) confirms the schema shape matches a real RGS-conformant general ledger with balanced double-entry postings. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local audit; balance precondition lives on the schema or as a single-method exception-annotated guard; manifest carries the navigation). If the BalanceGuard path is taken, the guard is exactly one method with the ADR-031 exception annotation linking back to design.md's Declarative-vs-imperative decision table.

## Tests (company-wide ADR-009)

`tests/Unit/Lifecycle/BalanceGuardTest.php` covers all 6 meaningful behaviours of `BalanceGuard::isBalanced`: no-id denial, fewer-than-2-lines denial, balanced 2-line approval, unbalanced 2-line denial, balanced N-line approval, and fail-closed behavior on ObjectService exception. PHPUnit passes (run with `vendor/bin/phpunit --bootstrap=vendor/autoload.php tests/Unit/Lifecycle/`). The implementation cycle's full integration test suite (PHPUnit + Playwright) is deferred to the next `opsx-apply` per the spec's Tests section.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/general-ledger.md` per ADR-030 journeydoc convention and commits a GL detail-page screenshot (header + lines) to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `General Ledger`, `Transaction`, `Posting Date`, `Period`, `Debit`, `Credit`, `Balance`, `Draft`, `Posted`, `Reversed`, `Sub-ledger`.
