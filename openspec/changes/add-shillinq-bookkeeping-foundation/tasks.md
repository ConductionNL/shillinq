# Tasks — Bookkeeping Foundation (T1)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the three spec deltas — they
> are recorded now so the spec-review gate, dependency planning, and
> tier-cascade impact are all visible at proposal time. No source files
> are edited by this change itself.

## 0. Deduplication Check

### Task 0.1: Confirm no T1 schema or capability already exists

- **spec_ref**: all three specs (folder scan)
- **files**: `lib/Settings/shillinq_register.json`,
  `openspec/specs/**`, `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**:
  - GIVEN the shillinq repo at the head of `feat/bookkeeping-engine`
    WHEN `lib/Settings/shillinq_register.json` is inspected
    THEN no `Account`, `GLTransaction`, `GLLine`, or `JournalEntry`
    schema is already declared (only the placeholder `example`).
  - GIVEN `openspec/specs/` WHEN scanned THEN no
    `bookkeeping-*` capability spec already exists.
  - GIVEN `adr-000-data-model.md` WHEN read THEN the existing entries
    for `Account`, `GeneralLedgerAccount`, `GeneralLedgerEntry`,
    `JournalEntry`, `FiscalYear` are catalogued and the reconciliation
    note from `design.md` is appended.
- [ ] Implement
- [ ] Test

## 1. Spec foundation (this change)

### Task 1.1: Author bookkeeping-chart-of-accounts spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-foundation/specs/bookkeeping-chart-of-accounts/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries the
    `Status: proposed` / `Scope: shillinq` / `Tier: T1 (foundation)` /
    `Depends on: none` header.
  - GIVEN the spec WHEN scanned THEN every requirement uses
    `### REQ-CoA-NNN:` and SHALL/MUST/SHOULD/MAY RFC 2119 keywords.
  - GIVEN each requirement WHEN inspected THEN at least one
    `#### Scenario:` block with GIVEN/WHEN/THEN exists (exactly 4
    hashtags on the scenario header per conduction-schema rule).
- [x] Implement
- [ ] Test (spec validation — `openspec validate` clean)

### Task 1.2: Author bookkeeping-general-ledger spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries the
    `Status: proposed` / `Scope: shillinq` / `Tier: T1 (foundation)` /
    `Depends on: bookkeeping-chart-of-accounts` header.
  - GIVEN the spec WHEN scanned THEN it declares the header/line
    split (`GLTransaction` + `GLLine`), the balance invariant, the
    period-stamp field, and references ADR-022 for audit and
    ADR-031 for the lifecycle precondition.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.3: Author bookkeeping-journal-entries spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-foundation/specs/bookkeeping-journal-entries/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger` (which transitively
    depends on bookkeeping-chart-of-accounts).
  - GIVEN the spec WHEN scanned THEN it declares the three
    sub-types (manual / recurring / reversing), the docudesk
    source-document FK, and the OR approval-workflow integration
    via `x-openregister-lifecycle.requires`.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.4: Author proposal.md + design.md for the change envelope

- **spec_ref**: change root
- **files**: `proposal.md`, `design.md`
- **acceptance_criteria**:
  - GIVEN `proposal.md` WHEN inspected THEN it references the
    shared `nextcloud-app` spec per shillinq config.yaml
    `rules.proposal` and includes Affected Projects / Scope /
    Risks / Rollback / Open Questions.
  - GIVEN `design.md` WHEN inspected THEN it includes a
    Reuse Analysis table and a Seed Data section per hydra
    `rules.design`.
- [x] Implement
- [ ] Test (peer review — bookkeeper persona reads the model
  end-to-end and confirms RGS conformance)

---

## (The following tasks are recorded for the downstream `opsx-apply` cycle, not for this spec-only change.)

## 2. Register declarations — `lib/Settings/shillinq_register.json`

### Task 2.1: Declare the `Account` schema

- **spec_ref**: `bookkeeping-chart-of-accounts/spec.md` (REQ-CoA-001 .. REQ-CoA-009)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN a JSON Schema validator
    WHEN `Account` is loaded
    THEN every field from REQ-CoA-002 (accountNumber, name,
    accountType, currency, parentAccountNumber, isClosingAccount,
    administrationId, lifecycleState, description) is present with
    the typing the spec mandates.
  - GIVEN the `Account` schema
    WHEN scanned for lifecycle metadata
    THEN it carries an `x-openregister-lifecycle` block with the
    `active → blocked`, `active → archived`, and `blocked →
    archived` transitions from REQ-CoA-005.
  - GIVEN the parent-relation field
    WHEN scanned
    THEN it carries `x-openregister-relations` self-relation per
    REQ-CoA-003.
- [ ] Implement
- [ ] Test (`composer check:strict` + `npm run check:manifest` if
  the manifest validator is wired; PHPUnit integration test
  asserting schema load + lifecycle transition behaviour)

### Task 2.2: Declare the `GLTransaction` schema

- **spec_ref**: `bookkeeping-general-ledger/spec.md` (REQ-GL-001 .. REQ-GL-006)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema
    WHEN loaded
    THEN it carries header fields per REQ-GL-002 (transactionNumber,
    postingDate, periodId, currency, description, sourceReference,
    state, journalEntryId, administrationId).
  - GIVEN the schema's lifecycle
    WHEN scanned
    THEN it carries `draft → posted` and `posted → reversed`
    transitions per REQ-GL-004 and the balance-invariant precondition
    per REQ-GL-005.
  - GIVEN the precondition on `post`
    WHEN inspected
    THEN it either declares a cross-line aggregation inside
    `x-openregister-lifecycle.requires` OR references a single-method
    PHP guard (`OCA\Shillinq\Lifecycle\BalanceGuard`) per the
    ADR-031 exception path — design.md's open question resolves which.
- [ ] Implement
- [ ] Test (PHPUnit asserting unbalanced posting fails; balanced
  posting succeeds; reversed posting emits inverse audit event)

### Task 2.3: Declare the `GLLine` schema

- **spec_ref**: `bookkeeping-general-ledger/spec.md` (REQ-GL-003)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema
    WHEN loaded
    THEN fields per REQ-GL-003 are present (transactionId,
    lineNumber, accountNumber, side, amount, currency, periodId,
    subLedgerType, subLedgerRef, costCenter, description).
  - GIVEN `side`
    WHEN scanned
    THEN it is an enum of `["debit", "credit"]`.
  - GIVEN `amount`
    WHEN scanned
    THEN it is a non-negative number; the sign is encoded in `side`
    per REQ-GL-003.
- [ ] Implement
- [ ] Test (PHPUnit: rejecting `side: both`, rejecting negative
  amount; accepting valid line)

### Task 2.4: Declare the `JournalEntry` schema with three sub-types

- **spec_ref**: `bookkeeping-journal-entries/spec.md` (REQ-JE-001 .. REQ-JE-010)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema
    WHEN loaded
    THEN fields per REQ-JE-002 are present (journalNumber, entryDate,
    description, lines, sourceDocumentUri, sourceDocumentApp,
    journalType, cadence, reversesOn, glTransactionId,
    approvalState, administrationId, state).
  - GIVEN `journalType`
    WHEN scanned
    THEN it is an enum of `["manual", "recurring", "reversing"]`.
  - GIVEN the schema's lifecycle
    WHEN scanned
    THEN it declares `pending → posted → voided` with the
    approval-workflow `requires` per REQ-JE-008.
  - GIVEN `cadence`
    WHEN journalType is `recurring`
    THEN it is required; otherwise it is forbidden (REQ-JE-005).
- [ ] Implement
- [ ] Test (PHPUnit: lifecycle transitions; recurring materialisation
  via the OR scheduled-workflow primitive; reversing journal posts
  inverse on period boundary)

## 3. Seed data — `lib/Settings/seeds/`

### Task 3.1: Ship RGS 3.5 SMB seed template

- **spec_ref**: `bookkeeping-chart-of-accounts/spec.md` (REQ-CoA-006)
- **files**: `lib/Settings/seeds/rgs-3.5-mkb.json`
- **acceptance_criteria**:
  - GIVEN the seed file
    WHEN loaded
    THEN it is a JSON array of records each conforming to the
    `Account` schema.
  - GIVEN the file
    WHEN opened
    THEN the top SPDX header is present and an `_meta` block with
    `source: "RGS 3.5"`, `variant: "mkb"` is included per design.md
    Seed Data section.
- [ ] Implement
- [ ] Test (PHPUnit: load + import + queryable; record count
  matches RGS 3.5 canonical SMB cardinality)

### Task 3.2: Ship RGS 3.5 ZZP seed template

- **spec_ref**: `bookkeeping-chart-of-accounts/spec.md` (REQ-CoA-006)
- **files**: `lib/Settings/seeds/rgs-3.5-zzp.json`
- **acceptance_criteria**:
  - GIVEN the seed file
    WHEN loaded
    THEN every record conforms to the `Account` schema and the
    `_meta.variant` is `"zzp"`.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.3: Ship BBV seed template for government bookkeeping

- **spec_ref**: `bookkeeping-chart-of-accounts/spec.md` (REQ-CoA-006)
- **files**: `lib/Settings/seeds/rgs-bbv.json`
- **acceptance_criteria**:
  - GIVEN the seed file
    WHEN loaded
    THEN every record conforms to the `Account` schema and the
    `_meta.variant` is `"bbv"`.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.4: Extend the repair step to import the selected template

- **spec_ref**: `bookkeeping-chart-of-accounts/spec.md` (REQ-CoA-007)
- **files**: existing repair class under `lib/Migration/` or
  `lib/Settings/SettingsService.php`
- **acceptance_criteria**:
  - GIVEN a fresh shillinq install
    WHEN the repair step runs
    THEN the chosen RGS template's accounts appear in the `Account`
    register, idempotent on re-run.
  - GIVEN per-administration override
    WHEN an account is edited after seeding
    THEN the operator edit persists across subsequent repair runs
    (the repair step does not re-overwrite seeded records).
- [ ] Implement
- [ ] Test (PHPUnit + browser smoke in dev container)

## 4. Manifest navigation — `src/manifest.json`

### Task 4.1: Add Chart of Accounts navigation + pages

- **spec_ref**: `bookkeeping-chart-of-accounts/spec.md` (REQ-CoA-008)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest
    WHEN scanned
    THEN it declares a menu entry `Bookkeeping > Chart of Accounts`
    (or top-level if the bookkeeper persona review favours a flat
    nav), a `type: index` page binding to the `Account` register,
    and a `type: detail` page for individual accounts.
  - GIVEN `node tests/validate-manifest.js`
    WHEN run
    THEN it exits 0 (schema + consistency clean).
- [ ] Implement
- [ ] Test (validate-manifest + browser smoke)

### Task 4.2: Add General Ledger navigation + pages

- **spec_ref**: `bookkeeping-general-ledger/spec.md` (REQ-GL-007)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest
    WHEN scanned
    THEN it declares a menu entry `Bookkeeping > General Ledger`
    with `type: index` + `type: detail` pages binding to
    `GLTransaction` (the detail page shows GL header + lines).
  - GIVEN `validate-manifest.js`
    WHEN run
    THEN it exits 0.
- [ ] Implement
- [ ] Test (same as 4.1)

### Task 4.3: Add Journals navigation + pages

- **spec_ref**: `bookkeeping-journal-entries/spec.md` (REQ-JE-009)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest
    WHEN scanned
    THEN it declares a menu entry `Bookkeeping > Journals` with
    `type: index` + `type: detail` pages binding to `JournalEntry`.
  - GIVEN the detail page config
    WHEN inspected
    THEN it surfaces the `journalType`, `state`, `approvalState`,
    `sourceDocumentUri`, and the line grid.
- [ ] Implement
- [ ] Test (same as 4.1)

## 5. ADR-000 reconciliation note

### Task 5.1: Update adr-000-data-model.md

- **spec_ref**: `proposal.md` Impact section
- **files**: `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**:
  - GIVEN the ADR
    WHEN opened
    THEN the `GeneralLedgerEntry` section carries a one-paragraph
    note: "Superseded by `GLLine` from
    `bookkeeping-general-ledger`; T1 split the flat entry into
    header (`GLTransaction`) + line (`GLLine`) to make the balance
    constraint declarative per ADR-031".
  - GIVEN the `Account` and `GeneralLedgerAccount` sections
    WHEN read
    THEN the reconciliation paragraph from design.md's Reuse
    Analysis is inserted.
- [ ] Implement
- [ ] Test (peer review by the bookkeeper persona)

## 6. Lifecycle guard (conditional — only if Risk 1 confirms)

### Task 6.1 (conditional): Author BalanceGuard

- **spec_ref**: `bookkeeping-general-ledger/spec.md` REQ-GL-005
- **files**: `lib/Lifecycle/BalanceGuard.php` (new, single method)
- **acceptance_criteria**:
  - GIVEN the discovery step concluded the engine cannot express
    the cross-line balance constraint declaratively
    WHEN the guard is implemented
    THEN it has exactly one method `isBalanced(string $transactionId): bool`
    and is referenced from `x-openregister-lifecycle.requires` on
    the `GLTransaction.post` transition.
  - GIVEN the guard
    WHEN code-reviewed
    THEN it carries the ADR-031 exception annotation linking back
    to design.md's Declarative-vs-imperative decision table.
- [ ] Implement (only if conditional triggered)
- [ ] Test (PHPUnit: balanced returns true; unbalanced returns false;
  decimal precision edge cases — €0.005 rounding)

## Verification

- [ ] All Section 1 tasks (this change's own deliverables) checked off
- [ ] `openspec validate` exits clean on the change folder
- [ ] Manual peer review by a competent Dutch bookkeeper persona
      (e.g. `/test-persona-janwillem` for SMB, or a domain-expert
      review) confirms the schema shape matches a real RGS-conformant
      ledger
- [ ] Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031
      compliance (no app-local audit; no app-local approval table; no
      service-class state machines; manifest carries the navigation)
- [ ] No source code changes outside `openspec/changes/add-shillinq-bookkeeping-foundation/`

## Tests (company-wide ADR-009)

<!-- T1 spec-only change. Implementation-cycle tests are pre-declared on tasks 2-6 above for completeness. -->

- [ ] N/A for the spec change itself — no business logic ships
- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — declared on tasks 2.1, 2.2, 2.3, 2.4, 3.4, 6.1; lands with implementation cycle
- [ ] Newman/Postman tests for new/changed API endpoints — no new endpoints in T1 (OR exposes register CRUD generically; tests cover the register HTTP surface)
- [ ] Browser tests (Playwright MCP) for UI changes — declared on tasks 4.1, 4.2, 4.3; lands with implementation cycle
- [ ] All tests pass (`composer test`) — enforced at implementing PR's CI gate

## Documentation (company-wide ADR-010)

<!-- User-facing tutorial pages land with the implementation cycle, not the spec. -->

- [ ] N/A for the spec change itself
- [ ] Feature documentation updated in `docs/` — `docs/user-guide/bookkeeping/` index + per-capability pages (chart-of-accounts, general-ledger, journal-entries) authored during implementation cycle per ADR-030 journeydoc convention
- [ ] Screenshot captured and committed to `docs/images/` — authored during implementation cycle (3 screenshots: CoA index, GL detail, Journal create form)

## i18n (company-wide ADR-005)

<!-- No user-facing strings in the spec; translation work lands with the implementation cycle. -->

- [ ] N/A for the spec change itself
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added during implementation cycle — required terms: `Bookkeeping`, `Chart of Accounts`, `General Ledger`, `Journal Entry`, `Account`, `Debit`, `Credit`, `Balance`, `Posted`, `Reversed`, `Approval Pending`, `Recurring`, `Reversing`
