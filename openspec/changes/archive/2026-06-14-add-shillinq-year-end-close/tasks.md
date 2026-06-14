# Tasks — Year-End Close

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-year-end-close` spec — they are recorded now so the
> spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this
> change itself.

## Tasks

- [x] Task 1: Confirm no `FiscalYear` schema or `bookkeeping-year-end-close` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`)
- [x] Task 2: Author `specs/bookkeeping-year-end-close/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4 (advanced engine)` / `Depends on: bookkeeping-period-close (T3)` header, `REQ-YEC-NNN` requirements using RFC 2119 keywords, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (high-stakes close, `isClosingAccount` enforcement) / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [x] Task 4: Author `design.md` with Decisions (declarative close, reopen-as-escape-hatch, T1 `JournalEntry` reuse, CloudEvent rollover, multi-tenant uniqueness) and Reuse Analysis table per hydra `rules.design`
- [x] Task 5: Declare the `FiscalYear` schema in `lib/Settings/shillinq_register.json` with all REQ-YEC-002 fields (yearNumber, startDate, endDate, state, closingJournalId, openingJournalId, closedAt, closedBy, reopenedAt, reopenedBy, reopenReason, administrationId), uniqueness on (yearNumber, administrationId)
- [x] Task 6: Add `x-openregister-lifecycle` to `FiscalYear` declaring `open → closing → closed` and `closed → reopened` transitions per REQ-YEC-003; precondition on `open → closing` requires all T3 fiscal periods in the year be closed per REQ-YEC-007
- [x] Task 7: Implement closing actions as lifecycle action handlers — `open → closing` emits the retained-earnings transfer T1 `JournalEntry` (manual sub-type) per REQ-YEC-003; `closing → closed` emits the next-year opening-balance T1 `JournalEntry` (balance-sheet accounts only) per REQ-YEC-004
- [x] Task 8: Implement dimensional rollover as OR CloudEvents fired by the `closing → closed` action; CostCenter / KostenDrager / Project subscribers carry active dimensions forward and skip archived per REQ-YEC-005
- [x] Task 9: Add `closed → reopened` admin-only transition guard consuming OR RBAC `admin` role per ADR-022 + REQ-YEC-006; require non-empty `reopenReason` via `x-openregister-lifecycle.requires`; action emits two reversing T1 `JournalEntry` records pairing with the original closing + opening journals
- [x] Task 10: Add Fiscal Years navigation + pages to `src/manifest.json` (menu entry `Bookkeeping > Fiscal Years`, `type: index` page binding to `FiscalYear`, `type: detail` page surfacing close + reopen actions gated by role per REQ-YEC-006)
- [x] Task 11: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph reconciliation note introducing `FiscalYear` and its references from T1 `JournalEntry.fiscalYearId` for close + reopen journal pairing

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB) confirms the close + reopen flow matches real Dutch bookkeeping practice (year N retained earnings → year N+1 opening balance; reopen reverses both). Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (declarative state machine; admin role via OR RBAC; reopen-reason precondition; CloudEvents for rollover; manifest carries role-gated navigation; no `YearEndCloseService` PHP class). No source code changes outside `openspec/changes/add-shillinq-year-end-close/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests asserting profit-year + loss-year close emit balanced retained-earnings journal, opening-balance journal carries only balance-sheet accounts, archived dimensions skipped in rollover, non-admin reopen rejected, reopen-no-reason rejected, reopen emits two reversing journals (pre-declared on Tasks 5–9); persona test confirming admin sees reopen action and bookkeeper does not (Task 10); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/year-end-close.md` per ADR-030 journeydoc convention and commits a fiscal-year close action screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Fiscal Year`, `Boekjaar`, `Year-end Close`, `Jaarafsluiting`, `Reopen Year`, `Heropen Boekjaar`, `Retained Earnings`, `Opening Balance`, `Closed`, `Reopened`, `Reopen Reason`.
