# Tasks — Year-End Close

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-year-end-close` spec — they are recorded now so the
> spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-year-end-close` capability spec already exists, no `ClosingEntry` / `RetainedEarnings` / `ClosingAccount` schemas are declared, and no `lib/Service/Closing*` PHP classes are present (per ADR-031 anti-pattern enumeration); explicitly note this capability "operationalises the transition from one fiscal year to the next"

  **Audit result (2026-06-08):** No `ClosingEntry` / `RetainedEarnings` / `ClosingAccount` / `ClosingEntryTemplate` slugs in `lib/Settings/shillinq_register.json`. No `lib/Service/Closing*` classes (verified `ls lib/Service/`). The `FiscalYear` schema already exists with an `open → closing → closed → reopened` lifecycle from a prior change. This capability operationalises the transition from one fiscal year to the next and layers the closing checklist, declarative closing-entry templates, materialisation and retained-earnings rollforward on top of the existing FiscalYear lifecycle (additively, the `closing` state stands in for the spec's `in-progress`).
- [ ] Task 2: Author `specs/bookkeeping-year-end-close/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-base (advanced engine features)` / `Kind: config` header, `REQ-YEC-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline
- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Motivation / Dependencies (T1 GL, T2 compliance, T4 fixed assets) / Risks (closing-workflow stability, closing-entry generation complexity, archive-period immutability, multi-currency deferred to T5) / Rollback / Open Questions
- [ ] Task 4: Author `design.md` with Reuse Analysis table, D1 (FY lifecycle state machine), D2 (OR closing-workflow with PHP guard fallback), D3 (archive-period locking), D4 (declarative closing checklist), D5 (retained-earnings rollforward), D6 (opening-balance seeding)
- [ ] Task 5: Declare the `ClosingEntry` schema in `lib/Settings/shillinq_register.json` with all REQ-YEC-001 fields (closingEntryNumber, fiscalYearId, entryDate, entryType, description, automationTemplate, amount, glTransactionId, approvalStatus, approvedBy, approvedAt, administrationId)
- [ ] Task 6: Declare the `RetainedEarnings` schema in `lib/Settings/shillinq_register.json` with all REQ-YEC-002 fields (retainedEarningsId, fiscalYearId, openingBalance, netIncome, distributions, closingBalance, closingEntryId, administrationId)
- [ ] Task 7: Declare the `ClosingAccount` schema in `lib/Settings/shillinq_register.json` with all REQ-YEC-003 fields (accountNumber, administrationId, isActive, effectiveFrom)
- [ ] Task 8: Declare the `ClosingEntryTemplate` schema in `lib/Settings/shillinq_register.json` with all REQ-YEC-004 fields (templateId, templateName, description, accountPattern, closingAccountNumber, reverseNextPeriod, automationTrigger, administrationId, lifecycleState, createdAt, modifiedAt)
- [ ] Task 9: Extend the T2 `FiscalYear` schema (from `bookkeeping-compliance`) additively with `x-openregister-lifecycle` declaring the state machine per REQ-YEC-005: states {open, in-progress, closed}, transitions open → in-progress, in-progress → closed, with preconditions on the final transition
- [ ] Task 10: Declare closing checklist as `x-openregister-lifecycle.requires` predicates on the `FiscalYear` in-progress → closed transition per REQ-YEC-006: Trial Balance Verified, Accruals Recorded, Depreciation Posted, FX Gains/Losses Declared, Related-Party Transactions Reviewed (all customisable, overrideable by CFO)
- [ ] Task 11: Enable archive-period locking via `x-openregister-lifecycle` immutable-period flag on `FiscalYear.closed` state per REQ-YEC-007 (GL transactions and GL lines become read-only when FY is closed)
- [ ] Task 12: Declare balance-carryforward validation as `x-openregister-aggregations` precondition per REQ-YEC-008 (validate next FY opening balances = prior FY closing balances) — not a PHP service
- [ ] Task 13: Declare closing-entry materialization per REQ-YEC-009 via `x-openregister-lifecycle` action on `ClosingEntry` approval/posting, invoking T1's materialisation extension to create balanced GLTransaction with matching GL lines
- [ ] Task 14: Declare automated closing-entry generation per REQ-YEC-010 as `x-openregister-lifecycle` action on `FiscalYear` in-progress → closed transition: query active `ClosingEntryTemplate` records, iterate GL lines, calculate amounts, generate `ClosingEntry` records with `approvalStatus: pending-approval` (or `ScheduledWorkflow` if async is preferred)
- [ ] Task 15: Add 2 manifest navigation entries (`Year-End Close Checklist`, `Closing Entries`) + their `type: index` pages to `src/manifest.json` per REQ-YEC-011; both use generic `CnIndexPage` renderer (ADR-017); `node tests/validate-manifest.js` exits 0
- [ ] Task 16: Seed three default `ClosingEntryTemplate` records per REQ-YEC-012 in `lib/Settings/shillinq_register.json` via `components.objects[]` with `@self` envelope: Revenue Closing (4000–4999 → 9900), Expense Closing (5000–6999 → 9900), Accrual Reversal (9700–9799 → 5900, reverse next period). All marked `lifecycleState: active` on install.
- [ ] Task 17: Update `openspec/architecture/adr-000-data-model.md` with `ClosingEntry` / `RetainedEarnings` / `ClosingAccount` / `ClosingEntryTemplate` entries and relations, reconciling against any existing `ClosingEntry` / `YearEndClose` / `ClosingJournal` data-model entries

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB) confirms the year-end close flow matches Dutch SMB practice (begin close checklist → auto-generate closing entries → approve → post → lock period → seed next FY). Financial-officer persona (`/test-persona-annemarie` for standards architect) confirms compliance with IAS/IFRS closing practices (trial balance, accruals, depreciation, FX, related-party). Architecture reviewer confirms ADR-022 + ADR-031 compliance (no app-local closing service; lifecycle declarative or ADR-031-exception-annotated guard; manifest carries navigation; closing checklist is aggregation-driven precondition). No source code changes outside `openspec/changes/bookkeeping-year-end-close/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle
(separate `opsx-apply`) is responsible for:

- **Unit tests:**
  - Closing checklist precondition evaluation: trial-balance validation,
    accrual aggregation, depreciation check, FX detection, related-party
    flagging (all pre-declared on Tasks 10)
  - Closing-entry generation: account patterns matched correctly,
    amounts summed correctly, GL lines generated balanced, reversal
    entries created for next period (pre-declared on Tasks 14)
  - Retained-earnings calculation: net income formula correct
    (revenue – expense), opening/closing balance rollforward correct,
    distribution deduction correct
  - Balance-carryforward validation: next-FY opening total = prior-FY
    closing total (within rounding tolerance), no account mismatches
  - Archive-period locking: GL posting to closed FY rejected with
    correct error message; reading closed GL data allowed
  - Lifecycle transitions: `open → in-progress`, `in-progress → closed`,
    and conditional `closed → open` (unclose) with audit trail
    
- **Browser tests (Playwright MCP):**
  - Year-End Close Checklist page renders; checklist items display
    status (pass/fail/warning)
  - Closing Entries index page renders; list shows all closing entries
    for selected FY
  - Approval workflow: operator approves pending entry, entry status
    changes to approved, "Post to GL" button appears
  - Posting: operator clicks "Post to GL", entry materialises as GL
    transaction, detail page shows linked GLTransaction
  - Manifest navigation: both entries reachable from Bookkeeping menu

- `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation
cycle authors `docs/user-guide/bookkeeping/year-end-close.md` per
ADR-030 journeydoc convention with sections:

- Overview: what is year-end close, why it matters for Dutch SMB
- Checklist overview: what each checklist item validates
- Closing-entry generation: how closing entries are auto-generated,
  what operators can customize (templates)
- Approval workflow: how to review and approve closing entries
- Emergency close: CFO override for rounding errors, audit trail
- Corrections post-close: unclosing, posting correction, re-closing
- Screenshots: checklist page, closing entries index, approval workflow

Commits AR invoice + dunning timeline screenshots to `docs/images/`.

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation
cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:

- `Year-End Close`
- `Closing Entries`
- `Year-End Close Checklist`
- `Begin Close Process`
- `Complete Close`
- `Closing Entry`
- `Trial Balance Verified`
- `Accruals Recorded`
- `Depreciation Posted`
- `FX Gains/Losses Declared`
- `Related-Party Transactions Reviewed`
- `All Checks Passed`
- `Trial Balance Imbalance`
- `Revenue Closing`
- `Expense Closing`
- `Accrual Reversal`
- `Retained Earnings`
- `Opening Balance`
- `Closing Balance`
- `Closing Account`
- `Income Summary`
- `Fiscal Year`
- `In Progress`
- `Closed`
- `Immutable`
- `Archive`
- `Unclose`
- `Emergency Close`
- `Override Checklist`
- `Pending Approval`
- `Approved`
- `Posted`
- `Draft`
