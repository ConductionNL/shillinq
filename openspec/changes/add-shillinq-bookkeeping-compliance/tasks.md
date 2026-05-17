# Tasks — Bookkeeping Compliance + Operations (T2)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the eight spec deltas — they
> are recorded now so the spec-review gate, dependency planning, and
> tier-cascade impact are all visible at proposal time. No source files
> are edited by this change itself.

## 0. Deduplication Check

### Task 0.1: Confirm no T2 schema or capability already exists

- **spec_ref**: all eight specs (folder scan)
- **files**: `lib/Settings/shillinq_register.json`,
  `openspec/specs/**`, `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**:
  - GIVEN the shillinq repo at the head of `feat/bookkeeping-engine`
    WHEN `lib/Settings/shillinq_register.json` is inspected
    THEN no `FiscalPeriod`, `VendorMaster`, `APInvoice`, `PaymentRun`,
    `CustomerMaster`, `ARInvoice`, `DunningRecord`, `BankStatement`,
    `BankStatementLine`, `MatchingRule`, or `ReconciliationMatch`
    schema is already declared (T1 schemas — `Account`,
    `GLTransaction`, `GLLine`, `JournalEntry` — are permitted and
    expected).
  - GIVEN `openspec/specs/` WHEN scanned THEN no
    `bookkeeping-trial-balance`, `bookkeeping-period-close`,
    `bookkeeping-accounts-payable-core`, `bookkeeping-accounts-receivable-core`,
    `bookkeeping-financial-statements`, `bookkeeping-audit-trail`,
    `bookkeeping-document-attachment-integration`, or
    `bookkeeping-bank-reconciliation` capability spec already exists.
  - GIVEN `adr-000-data-model.md` WHEN read THEN any existing entries
    for `FiscalPeriod`, `Vendor*`, `Customer*`, `Invoice*`,
    `BankStatement*`, or `Reconciliation*` are catalogued and the
    reconciliation note from `design.md` is appended (similar to T1's
    GeneralLedgerEntry → GLLine supersession note).
- [ ] Implement
- [ ] Test

### Task 0.2: Confirm no parallel storage / service classes already exist

- **spec_ref**: every T2 spec's "Reviewer confirms no parallel storage"
  scenario
- **files**: `lib/Db/`, `lib/Service/`, `lib/Controller/`,
  `appinfo/info.xml`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN `lib/Db/` WHEN scanned THEN no Mapper classes naming any
    of: `ap_invoice`, `vendor_master`, `payment_run`,
    `ar_invoice`, `customer_master`, `dunning_*`,
    `fiscal_period`, `bank_statement`, `bank_line`,
    `reconciliation_*`, `match_rule`, `audit_*`, `event_log_*`
    SHALL exist.
  - GIVEN `lib/Service/` WHEN scanned THEN no service classes named
    `TrialBalance*`, `*ReportBuilder*`, `BalanceSheet*`,
    `ProfitAndLoss*`, `CashFlow*`, `Statement*`, `Aging*`,
    `Dunning*`, `Reconcil*`, `Match*`, `Payment*`, `Sepa*`,
    `Ideal*`, `Xbrl*`, `Sbr*`, or `Audit*` SHALL exist (other than
    the at-most-3 conditional lifecycle guards permitted by
    ADR-031 exception, each cited per-spec).
  - GIVEN `appinfo/routes.php` WHEN scanned THEN no routes
    matching `/psd2/webhook`, `/attachment/upload`, or
    `/audit/purge` SHALL exist.
- [ ] Implement
- [ ] Test

## 1. Spec foundation (this change)

### Task 1.1: Author bookkeeping-trial-balance spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-trial-balance/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries the
    `Status: proposed` / `Scope: shillinq` /
    `Tier: T2 (compliance + operations)` /
    `Depends on: T1 bookkeeping-general-ledger` header.
  - GIVEN the spec WHEN scanned THEN every requirement uses
    `### REQ-TB-NNN:` and SHALL/MUST/SHOULD/MAY RFC 2119 keywords.
  - GIVEN each requirement WHEN inspected THEN at least one
    `#### Scenario:` block with GIVEN/WHEN/THEN exists (exactly 4
    hashtags on the scenario header per conduction-schema rule).
  - GIVEN the spec WHEN scanned for ADR citations THEN ADR-022
    (no parallel storage) and ADR-031 (declarative aggregation
    over service) MUST be cited inline.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.2: Author bookkeeping-period-close spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-period-close/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: T2 bookkeeping-trial-balance, T1 bookkeeping-general-ledger`.
  - GIVEN the spec WHEN scanned THEN it declares the
    `FiscalPeriod` register, the `open → closing → closed →
    audit-locked` lifecycle, the closed-period posting precondition
    added to T1's `GLTransaction.post`, the reopen workflow with
    elevated-role + audit-trailed reason, and explicitly defers
    year-end close to T3.
  - GIVEN the spec WHEN scanned for ADR citations THEN ADR-022 +
    ADR-031 MUST be cited inline.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.3: Author bookkeeping-accounts-payable-core spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-accounts-payable-core/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: T1 bookkeeping-general-ledger, T2 bookkeeping-document-attachment-integration`.
  - GIVEN the spec WHEN scanned THEN it declares `VendorMaster`,
    `APInvoice`, `PaymentRun` registers; the AP lifecycle
    consuming OR approval-workflow per ADR-022; the conditional
    3-way match (2-way fallback when PO/GR absent); SEPA pain.001
    + iDEAL as `x-openregister-calculations` per ADR-031.
  - GIVEN the spec WHEN scanned for legacy intelligence-db cluster
    references THEN it explicitly addresses the legacy AP/AR
    draft cluster (per proposal Motivation).
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.4: Author bookkeeping-accounts-receivable-core spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-accounts-receivable-core/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: T1 bookkeeping-general-ledger, T2 bookkeeping-document-attachment-integration, T2 bookkeeping-bank-reconciliation`.
  - GIVEN the spec WHEN scanned THEN it declares
    `CustomerMaster`, `ARInvoice`, `DunningRecord` registers; the
    AR lifecycle consuming OR dunning-workflow per ADR-022 (with
    shape-neutral PHP-guard fallback per ADR-031 exception); the
    write-off path; the UBL 2.1 / Peppol BIS 3.0 field shape
    declared for T4 attachment but NOT computed in T2.
  - GIVEN the spec WHEN scanned THEN it explicitly notes the
    capability "carries forward the original Shillinq invoicing
    scope" per proposal Motivation.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.5: Author bookkeeping-financial-statements spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-financial-statements/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: T1 bookkeeping-general-ledger, T2 bookkeeping-trial-balance`.
  - GIVEN the spec WHEN scanned THEN it declares the Balance
    Sheet / P&L / Cash Flow as compositions of trial-balance
    aggregations + a presentation manifest under
    `lib/Settings/statements/`; XBRL/PDF export as declarative
    calculations; the `CnReportPage` renderer path (preferred)
    or a per-statement bespoke Vue fallback (with sunset note);
    BBV scope explicitly deferred to T3.
  - GIVEN the spec WHEN scanned for ADR citations THEN ADR-024
    (Tier-4 manifest) and ADR-031 (declarative report assembly)
    MUST be cited inline.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.6: Author bookkeeping-audit-trail spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-audit-trail/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries `Depends on: none`
    (the capability is a wiring + UI surface, not a code
    dependency).
  - GIVEN the spec WHEN scanned THEN it declares (a) every
    bookkeeping register must carry `x-openregister-audit: true`,
    (b) the manifest entry into OR's audit-log UI pre-filtered
    to bookkeeping object types, (c) the audit side panel on
    every bookkeeping detail page, (d) retention governed by OR
    per ADR-022.
  - GIVEN the spec WHEN scanned THEN it explicitly forbids
    `lib/Db/Audit*`, `lib/Service/Audit*` per ADR-022
    anti-pattern.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.7: Author bookkeeping-document-attachment-integration spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-document-attachment-integration/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries `Depends on: none`
    (defines a cross-app contract; consumed by other T1 + T2
    specs).
  - GIVEN the spec WHEN scanned THEN it declares the FK URI
    contract (`docudesk://attachments/<uuid>/<filename>`), the
    mime-type-per-role metadata, the non-blocking failure mode
    when docudesk is unavailable (URI persists, audit records
    the gap, warning banner on detail), the auditor-role
    pass-through.
  - GIVEN the spec WHEN scanned for ADR citations THEN ADR-022
    (consume docudesk, no parallel attachment storage) MUST be
    cited inline.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.8: Author bookkeeping-bank-reconciliation spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-bank-reconciliation/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: T1 bookkeeping-general-ledger, T2 bookkeeping-document-attachment-integration`.
  - GIVEN the spec WHEN scanned THEN it declares `BankStatement`,
    `BankStatementLine`, `MatchingRule`, `ReconciliationMatch`
    registers; CAMT.053 + MT940 + manual CSV import; matching
    rules declared as schema metadata per ADR-031; the suspense-
    account routing; the bank-statement lifecycle including
    audit-lock; PSD2 live-feed explicitly deferred to T4.
  - GIVEN the spec WHEN scanned for ADR citations THEN ADR-031
    (declarative rule evaluation over service) and ADR-022
    (consume audit + docudesk) MUST be cited inline.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.9: Author proposal.md + design.md for the change envelope

- **spec_ref**: change root
- **files**: `proposal.md`, `design.md`
- **acceptance_criteria**:
  - GIVEN `proposal.md` WHEN inspected THEN it references the
    shared `nextcloud-app` spec per shillinq config.yaml
    `rules.proposal` and includes Affected Projects / Scope /
    Risks / Rollback / Open Questions; it explicitly cites the
    T1 foundation change by file path under "Cross-Project
    Dependencies".
  - GIVEN `design.md` WHEN inspected THEN it includes a
    Reuse Analysis table, a Seed Data section (statement
    presentation manifests), a Decisions section (D1..D10), and a
    Declarative-vs-imperative decision table per ADR-031
    enforcement.
- [x] Implement
- [ ] Test (peer review — bookkeeper persona reads the eight
  specs end-to-end and confirms RJ 270 / IFRS-for-SMEs +
  Belastingdienst conformance)

---

## (The following tasks are recorded for the downstream `opsx-apply` cycle, not for this spec-only change.)

## 2. Register declarations — `lib/Settings/shillinq_register.json`

### Task 2.1: Declare the `FiscalPeriod` schema + promote T1's `GLLine.periodId` to FK

- **spec_ref**: `bookkeeping-period-close/spec.md` (REQ-PC-001 .. REQ-PC-006)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema WHEN loaded THEN fields per REQ-PC-002 are
    present (`periodId`, `name`, `startDate`, `endDate`,
    `fiscalYear`, `administrationId`, `state`, `closedAt`,
    `closedBy`, `auditLockedAt`, `auditLockedBy`, `closeReason`,
    `reopenedHistory`).
  - GIVEN the schema's lifecycle WHEN scanned THEN it declares
    `open → closing → closed → audit-locked` with the close +
    reopen + audit-lock transitions per REQ-PC-003.
  - GIVEN T1's `GLLine.periodId` field WHEN inspected THEN it
    carries an additive `x-openregister-relations` block resolving
    against `FiscalPeriod` (per REQ-PC-001 additive migration
    note).
  - GIVEN T1's `GLTransaction.post` precondition WHEN inspected
    THEN it carries an additive closed-period rejection clause
    per REQ-PC-004.
- [ ] Implement
- [ ] Test (PHPUnit: backdating rejected; reopen requires
  elevated role; audit-lock irreversible)

### Task 2.2: Declare the `VendorMaster` + `APInvoice` + `PaymentRun` schemas

- **spec_ref**: `bookkeeping-accounts-payable-core/spec.md` (REQ-AP-001 .. REQ-AP-008)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the three schemas WHEN loaded THEN every field from
    REQ-AP-002 / REQ-AP-003 / REQ-AP-007 is present with the
    typing the spec mandates.
  - GIVEN `APInvoice`'s lifecycle WHEN scanned THEN it declares
    every transition in REQ-AP-004 with the approval-workflow
    consumed from OR per REQ-AP-005 (no app-local approval table).
  - GIVEN `PaymentRun`'s `sepaXml` field WHEN inspected THEN it
    is declared as an `x-openregister-calculations` output per
    REQ-AP-007 (no PHP service).
  - GIVEN the 3-way match precondition on `APInvoice.post` WHEN
    inspected THEN it conditionally activates if PO + GR
    registers are present (per REQ-AP-006).
- [ ] Implement
- [ ] Test (PHPUnit: AP lifecycle; SEPA XML schema validation
  against pain.001.001.03; 2-way and 3-way match)

### Task 2.3: Declare the `CustomerMaster` + `ARInvoice` + `DunningRecord` schemas

- **spec_ref**: `bookkeeping-accounts-receivable-core/spec.md` (REQ-AR-001 .. REQ-AR-008)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the three schemas WHEN loaded THEN every field from
    REQ-AR-002 / REQ-AR-003 / REQ-AR-005 is present.
  - GIVEN `ARInvoice`'s lifecycle WHEN scanned THEN it declares
    every transition in REQ-AR-004 with dunning consumed from
    OR per REQ-AR-005 (or DunningGuard fallback per ADR-031
    exception, documented).
  - GIVEN credit-limit check WHEN inspected THEN it is an
    `x-openregister-aggregations` query per REQ-AR-006, not a
    service.
  - GIVEN AR aging WHEN inspected THEN it is an
    `x-openregister-aggregations` query per REQ-AR-008.
- [ ] Implement
- [ ] Test (PHPUnit: AR lifecycle; overdue auto-transition;
  dunning timeline; write-off compensating posting)

### Task 2.4: Declare the bank-reconciliation registers (`BankStatement`, `BankStatementLine`, `MatchingRule`, `ReconciliationMatch`)

- **spec_ref**: `bookkeeping-bank-reconciliation/spec.md`
  (REQ-BR-001 .. REQ-BR-010)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the four schemas WHEN loaded THEN every field from the
    relevant REQs is present with the typing the spec mandates.
  - GIVEN `BankStatement`'s lifecycle WHEN scanned THEN it
    declares `imported → in-progress → reconciled → audit-locked`
    per REQ-BR-008.
  - GIVEN `MatchingRule.predicates` WHEN scanned THEN it accepts
    every predicate shape in REQ-BR-005.
  - GIVEN the parser declaration WHEN inspected THEN it is
    EITHER `x-openregister-calculations` OR a single-method
    `StatementParser` (ADR-031 exception cited in file header),
    per REQ-BR-003.
  - GIVEN the duplicate-import constraint WHEN scanned THEN it
    is declarative (uniqueness on file checksum + period
    overlap) per REQ-BR-009.
- [ ] Implement
- [ ] Test (PHPUnit: CAMT.053 + MT940 parsing; match emission;
  suspense routing; duplicate import rejection)

### Task 2.5: Declare `x-openregister-audit: true` on every T1 + T2 register

- **spec_ref**: `bookkeeping-audit-trail/spec.md` (REQ-AT-001)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN every register declared by T1 + T2 WHEN inspected
    THEN each carries `x-openregister-audit: true` (or the
    OR-canonical equivalent).
  - GIVEN T1 schemas (`Account`, `GLTransaction`, `GLLine`,
    `JournalEntry`) WHEN re-inspected post-T2 THEN the audit flag
    is preserved (T1 already declared it; T2 confirms).
- [ ] Implement
- [ ] Test (PHPUnit: audit event emission on every register's
  create/update/lifecycle transition)

### Task 2.6: Declare the trial-balance + aging + cash-flow + AR-aging aggregations

- **spec_ref**: `bookkeeping-trial-balance/spec.md` (REQ-TB-001 .. REQ-TB-003),
  `bookkeeping-accounts-payable-core/spec.md` (REQ-AP-009),
  `bookkeeping-accounts-receivable-core/spec.md` (REQ-AR-008),
  `bookkeeping-financial-statements/spec.md` (REQ-FS-001)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the trial-balance aggregation WHEN inspected THEN it
    groups `GLLine` by `(period_id, account_number, side)` with
    opening / movement / closing buckets per REQ-TB-002, excludes
    `state: reversed` parents, and declares the balance
    invariant per REQ-TB-003.
  - GIVEN AP aging WHEN inspected THEN it groups `APInvoice` by
    `(vendorId, agingBucket)` per REQ-AP-009, excluding `paid` /
    `voided`.
  - GIVEN AR aging WHEN inspected THEN it groups `ARInvoice`
    similarly per REQ-AR-008.
  - GIVEN cash-flow aggregation WHEN inspected THEN it operates
    on `GLLine` filtered to liquidity accounts (indirect method
    default per REQ-FS-003).
- [ ] Implement
- [ ] Test (PHPUnit: aggregation correctness vs hand-computed
  trial balance; aging buckets; invariant detection on tampered
  state)

## 3. Statement presentation manifests — `lib/Settings/statements/`

### Task 3.1: Ship RJ 270 Balance Sheet presentation manifest

- **spec_ref**: `bookkeeping-financial-statements/spec.md`
  (REQ-FS-002)
- **files**: `lib/Settings/statements/rj270-balance-sheet.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN it parses as JSON and
    matches the shape declared in REQ-FS-002.
  - GIVEN the top SPDX header WHEN inspected THEN it carries
    EUPL-1.2 + Copyright Conduction B.V. per
    `feedback_spdx-in-docblock.md`.
  - GIVEN the sections WHEN counted THEN ~40 line items are
    present covering fixed assets / current assets / equity /
    provisions / long-term + short-term debt per RJ 270 SMB.
- [ ] Implement
- [ ] Test (peer review by bookkeeper persona; assembled output
  matches a known-good RJ 270 reference balance sheet)

### Task 3.2: Ship RJ 270 Profit & Loss presentation manifest

- **spec_ref**: `bookkeeping-financial-statements/spec.md`
  (REQ-FS-002)
- **files**: `lib/Settings/statements/rj270-pl.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN ~30 line items covering
    revenue / cost of sales / operating expenses / financial
    result / tax / net result per RJ 270.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.3: Ship RJ 270 Cash Flow Statement presentation manifest

- **spec_ref**: `bookkeeping-financial-statements/spec.md`
  (REQ-FS-002 + indirect-method default note in REQ-FS-003)
- **files**: `lib/Settings/statements/rj270-cash-flow.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN ~25 line items covering
    operating / investing / financing activities per the
    indirect method.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.4: Extend the repair step to import the statement manifests

- **spec_ref**: `bookkeeping-financial-statements/spec.md`
  (REQ-FS-002 — `_meta` block + import path)
- **files**: existing repair class under `lib/Migration/` or
  `lib/Settings/SettingsService.php`
- **acceptance_criteria**:
  - GIVEN a fresh shillinq install WHEN the repair step runs
    THEN the three statement manifests are queryable via the
    OR object API.
  - GIVEN per-administration override WHEN a manifest is edited
    after import THEN the operator edit persists across
    subsequent repair runs (idempotent — no re-overwrite).
- [ ] Implement
- [ ] Test (PHPUnit + browser smoke in dev container)

## 4. Manifest navigation — `src/manifest.json`

### Task 4.1: Add Trial Balance navigation + page

- **spec_ref**: `bookkeeping-trial-balance/spec.md` (REQ-TB-005)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Trial Balance` with a `type: report` (or
    `type: index` fallback per REQ-TB-005) page; period query
    parameter defaults to active `FiscalPeriod`.
  - GIVEN `validate-manifest.js` WHEN run THEN it exits 0.
- [ ] Implement
- [ ] Test (validate-manifest + browser smoke)

### Task 4.2: Add Period Close navigation + pages

- **spec_ref**: `bookkeeping-period-close/spec.md` (REQ-PC-007)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Period Close` with `type: index` +
    `type: detail` pages binding to `FiscalPeriod`; detail page
    surfaces lifecycle action buttons + trial-balance preview
    link.
- [ ] Implement
- [ ] Test (same as 4.1)

### Task 4.3: Add Accounts Payable navigation + pages

- **spec_ref**: `bookkeeping-accounts-payable-core/spec.md` (REQ-AP-010)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Vendors`, `Bookkeeping > Accounts Payable`,
    `Bookkeeping > AP Aging`, `Bookkeeping > Payment Runs`
    entries per REQ-AP-010.
- [ ] Implement
- [ ] Test (same as 4.1)

### Task 4.4: Add Accounts Receivable navigation + pages

- **spec_ref**: `bookkeeping-accounts-receivable-core/spec.md` (REQ-AR-010)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Customers`, `Bookkeeping > Accounts Receivable`,
    `Bookkeeping > AR Aging`, `Bookkeeping > Dunning` entries
    per REQ-AR-010.
- [ ] Implement
- [ ] Test (same as 4.1)

### Task 4.5: Add Financial Statements navigation + pages

- **spec_ref**: `bookkeeping-financial-statements/spec.md`
  (REQ-FS-003 + REQ-FS-007)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Financial Statements > Balance Sheet`,
    `> Profit & Loss`, `> Cash Flow Statement` entries; renderer
    is `CnReportPage` (preferred) or the per-statement bespoke
    Vue fallback (with sunset note); XBRL + PDF export actions
    are declared on each page.
- [ ] Implement
- [ ] Test (same as 4.1)

### Task 4.6: Add Audit Trail navigation + side-panel declarations

- **spec_ref**: `bookkeeping-audit-trail/spec.md` (REQ-AT-003 +
  REQ-AT-004)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Audit Trail` opening OR's audit-log UI
    pre-filtered to bookkeeping object types.
  - GIVEN every bookkeeping `type: detail` manifest entry WHEN
    inspected THEN each declares the OR audit-log side panel
    filtered to the object's UUID per REQ-AT-004.
- [ ] Implement
- [ ] Test (same as 4.1)

### Task 4.7: Add Bank Reconciliation navigation + pages

- **spec_ref**: `bookkeeping-bank-reconciliation/spec.md`
  (REQ-BR-010)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Bank Reconciliation` + `Bookkeeping >
    Matching Rules` entries per REQ-BR-010.
- [ ] Implement
- [ ] Test (same as 4.1)

## 5. ADR-000 reconciliation note

### Task 5.1: Update adr-000-data-model.md with T2 entities

- **spec_ref**: `proposal.md` Impact section
- **files**: `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**:
  - GIVEN the ADR WHEN opened THEN T2's new entities
    (`FiscalPeriod`, `VendorMaster`, `APInvoice`, `PaymentRun`,
    `CustomerMaster`, `ARInvoice`, `DunningRecord`,
    `BankStatement`, `BankStatementLine`, `MatchingRule`,
    `ReconciliationMatch`) are added with their canonical
    Schema.org annotation + Primary spec reference.
  - GIVEN any pre-existing data-model entries that overlap
    (e.g. `FiscalYear`, `Vendor`, `Customer`, `Invoice`,
    `BankStatement`) WHEN present THEN a reconciliation
    paragraph is appended matching design.md's Reuse Analysis.
- [ ] Implement
- [ ] Test (peer review by bookkeeper persona)

## 6. Conditional lifecycle guards (only if ADR-031 exception triggers per-spec)

### Task 6.1 (conditional): Author ThreeWayMatchGuard

- **spec_ref**: `bookkeeping-accounts-payable-core/spec.md` REQ-AP-006
- **files**: `lib/Lifecycle/ThreeWayMatchGuard.php` (new, single
  method)
- **acceptance_criteria**:
  - GIVEN OR's lifecycle engine cannot express conditional
    preconditions declaratively WHEN the guard is implemented
    THEN it has exactly one method
    `matches(string $invoiceId, ?string $poRef, ?string $grRef): bool`
    and is referenced from
    `x-openregister-lifecycle.requires` on `APInvoice.post`.
  - GIVEN the guard WHEN code-reviewed THEN it carries the
    ADR-031 exception annotation linking back to design.md's
    Declarative-vs-imperative decision table.
- [ ] Implement (only if conditional triggered)
- [ ] Test (PHPUnit: 2-way pass; 3-way pass; 3-way reject on
  quantity mismatch)

### Task 6.2 (conditional): Author DunningGuard

- **spec_ref**: `bookkeeping-accounts-receivable-core/spec.md` REQ-AR-005
- **files**: `lib/Lifecycle/DunningGuard.php` (new, single method)
- **acceptance_criteria**:
  - GIVEN OR's dunning-workflow extension is NOT yet stable
    WHEN the guard is implemented THEN it has exactly one
    method evaluating dunning cadence + escalation;
    `DunningRecord` writes remain declarative.
  - GIVEN the guard WHEN code-reviewed THEN it carries the
    ADR-031 exception annotation with the OR-issue link for
    the dunning-workflow extension.
- [ ] Implement (only if conditional triggered)
- [ ] Test (PHPUnit: reminder 1 at +14 days; collection
  escalation at +60 days)

### Task 6.3 (conditional): Author StatementParser

- **spec_ref**: `bookkeeping-bank-reconciliation/spec.md`
  REQ-BR-003
- **files**: `lib/Lifecycle/StatementParser.php` (new, single
  method)
- **acceptance_criteria**:
  - GIVEN OR's calculation extension does NOT yet support
    CAMT.053 / MT940 parsing primitives WHEN the parser is
    implemented THEN it has exactly one method
    `parse(string $contents, string $format): array`, ~50 LOC,
    no state, no orchestration.
- [ ] Implement (only if conditional triggered)
- [ ] Test (PHPUnit: CAMT.053 25-line file; MT940 25-line file;
  CSV import)

## Verification

- [ ] All Section 1 tasks (this change's own deliverables) checked off
- [ ] `openspec validate` exits clean on the change folder
- [ ] Manual peer review by a competent Dutch bookkeeper persona
      (e.g. `/test-persona-janwillem` for SMB, or a domain-expert
      review) confirms the eight specs end-to-end match a real
      RJ 270 / IFRS-for-SMEs SMB bookkeeping flow + Belastingdienst
      retention obligations
- [ ] Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031
      compliance (no app-local audit; no app-local approval table;
      no app-local dunning table; no service-class state machines;
      no PHP report builders; no PHP rule-engine; no file storage;
      manifest carries the navigation; calculations carry SEPA XML
      + XBRL composition)
- [ ] No source code changes outside `openspec/changes/add-shillinq-bookkeeping-compliance/`

## Tests (company-wide ADR-009)

<!-- T2 spec-only change. Implementation-cycle tests are pre-declared on tasks 2-6 above for completeness. -->

- [ ] N/A for the spec change itself — no business logic ships
- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — declared on tasks 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 3.4, 6.1, 6.2, 6.3; lands with implementation cycle
- [ ] Newman/Postman tests for new/changed API endpoints — no new endpoints in T2 (OR exposes register CRUD generically; tests cover the register HTTP surface)
- [ ] Browser tests (Playwright MCP) for UI changes — declared on tasks 4.1 through 4.7; lands with implementation cycle
- [ ] All tests pass (`composer test`) — enforced at implementing PR's CI gate

## Documentation (company-wide ADR-010)

<!-- User-facing tutorial pages land with the implementation cycle, not the spec. -->

- [ ] N/A for the spec change itself
- [ ] Feature documentation updated in `docs/` — `docs/user-guide/bookkeeping/` subpages for trial-balance, period-close, accounts-payable, accounts-receivable, bookkeeping-financial-statements, audit-trail, document-attachment, bank-reconciliation authored during implementation cycle per ADR-030 journeydoc convention
- [ ] Screenshots captured and committed to `docs/images/` — authored during implementation cycle (~8 screenshots: trial balance, period-close detail, AP invoice + payment run, AR invoice + dunning timeline, balance sheet, audit-log side panel, bank-rec detail)

## i18n (company-wide ADR-005)

<!-- No user-facing strings in the spec; translation work lands with the implementation cycle. -->

- [ ] N/A for the spec change itself
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added during implementation cycle — required terms: `Trial Balance`, `Period Close`, `Open Period`, `Closing`, `Closed`, `Audit Locked`, `Reopen`, `Accounts Payable`, `Vendor`, `Vendors`, `AP Invoice`, `Payment Run`, `Aging`, `Accounts Receivable`, `Customer`, `Customers`, `AR Invoice`, `Dunning`, `Reminder`, `Formal Notice`, `Collection`, `Write-off`, `Disputed`, `Credit Limit`, `Balance Sheet`, `Profit & Loss`, `Cash Flow Statement`, `Comparative`, `XBRL Export`, `PDF Export`, `Audit Trail`, `Source Document`, `Attachment`, `Bank Reconciliation`, `Bank Statement`, `Matching Rule`, `Suspense Account`, `Confirm Match`, `Route to Suspense`, `Auto-confirm`, `Imported`, `Reconciled`
