# Tasks — Period Close

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-period-close`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [ ] Task 1: Confirm no `bookkeeping-period-close` capability spec already exists, no `FiscalPeriod` schema is declared in `lib/Settings/shillinq_register.json`, and no `lib/Service/PeriodClose*` PHP classes are present (per ADR-031 anti-pattern enumeration)
- [ ] Task 2: Author `specs/bookkeeping-period-close/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: bookkeeping-trial-balance, bookkeeping-general-ledger` header, `REQ-PC-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline
- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions
- [ ] Task 4: Author `design.md` with Reuse Analysis table, D1 (lifecycle on new register), D2 (closed reversible / audit-locked irreversible), D3 (additive FK promotion), D4 (year-end deferred to T3)
- [ ] Task 5: Declare the `FiscalPeriod` schema in `lib/Settings/shillinq_register.json` with all REQ-PC-002 fields (`periodId`, `name`, `startDate`, `endDate`, `fiscalYear`, `administrationId`, `state`, `closedAt`, `closedBy`, `auditLockedAt`, `auditLockedBy`, `closeReason`, `reopenedHistory`)
- [ ] Task 6: Add `x-openregister-lifecycle` to `FiscalPeriod` declaring `open → closing → closed → audit-locked` transitions with role gates (`period-closer` for close/reopen, `auditor` for audit-lock) per REQ-PC-003
- [ ] Task 7: Augment T1's `GLLine.periodId` field with `x-openregister-relations` resolving against `FiscalPeriod.periodId` (additive — existing stub-string values resolve by exact match) per REQ-PC-001
- [ ] Task 8: Augment T1's `GLTransaction.post` precondition list with the closed-period rejection clause (`requires: ['period.state in ["open","closing"]']`) per REQ-PC-004 — additive to T1's existing balance + active-account preconditions
- [ ] Task 9: Implement the reopen workflow per REQ-PC-005 — `closing → open` transition requires elevated role + audit-trailed reason; close timestamp + actor preserved in `reopenedHistory` append-only field
- [ ] Task 10: Add Bookkeeping > Period Close navigation + pages to `src/manifest.json` (`type: index` binding to `FiscalPeriod`, `type: detail` surfacing lifecycle action buttons + trial-balance preview link) per REQ-PC-007; `node tests/validate-manifest.js` exits 0
- [ ] Task 11: Add the repair-step backfill creating `FiscalPeriod` records for every distinct historical `GLLine.periodId` value (idempotent — re-runs do not duplicate records)
- [ ] Task 12: Update `openspec/architecture/adr-000-data-model.md` with the new `FiscalPeriod` entity entry, reconciling against any existing `FiscalYear`/`Period` data-model entries

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB) confirms the close lifecycle matches a real Dutch SMB monthly/quarterly close flow. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (lifecycle is declarative; no PHP state-machine service; manifest carries the navigation). No source code changes outside `openspec/changes/add-shillinq-period-close/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests for backdating rejection, reopen requires elevated role, audit-lock irreversibility, additive FK resolution against existing stub-string values, idempotent backfill (pre-declared on Tasks 5–11); Playwright MCP browser tests for the period-close detail page action buttons (pre-declared on Task 10); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/period-close.md` per ADR-030 journeydoc convention and commits a period-close detail screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Period Close`, `Open Period`, `Closing`, `Closed`, `Audit Locked`, `Reopen`, `Close Reason`, `Closed by`, `Locked by`.
