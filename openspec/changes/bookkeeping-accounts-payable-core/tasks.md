# Tasks — Accounts Payable (Core)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-accounts-payable-core` spec — they are recorded now so
> the spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this change
> itself.

## Deduplication Check

- [x] Task 1: Confirm no `bookkeeping-accounts-payable-core` capability spec
  already exists, no `Payee`/`APTransaction`/`DunningNotice` schemas are
  declared, and no `lib/Service/AP*` / `lib/Service/Dunning*` PHP classes are
  present (per ADR-031 anti-pattern enumeration); verify no overlap with
  `bookkeeping-accounts-receivable-core` (mirror spec); document findings
  explicitly even if "no overlap found"
  - **Findings (2026-06-09):** Recorded in `dedup-notes.md`. No canonical
    `Payee` / `APTransaction` / `DunningNotice` schemas exist in
    `lib/Settings/shillinq_register.json` or in `lib/Settings/register.d/*`.
    No `lib/Db/` Mapper classes name `ap_transaction`, `payee`, `dunning_*`,
    or `accounts_payable_*`. Pre-existing baseline carries an alternate AP
    flavour (`VendorMaster` + `APInvoice` + `PaymentRun` schemas, used by
    `add-shillinq-bookkeeping-compliance`) — kept untouched; this T2 change
    adds the canonical `Payee` / `APTransaction` / `DunningNotice` shape per
    REQ-AP-001 alongside. Existing `DunningRunService` /
    `DunningController` belong to `bookkeeping-credit-control-dunning`
    (ladder runs); they are a different concept from the per-invoice
    `DunningNotice` timeline declared here and remain untouched. The AR
    mirror (`CustomerMaster` + `ARInvoice` + `DunningRecord`) covers the
    symmetric receivables side per `add-shillinq-accounts-receivable-core`;
    no overlap.

## Spec Authoring

- [x] Task 2: Author `specs/bookkeeping-accounts-payable-core/spec.md` with
  `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)`
  / `Depends on: bookkeeping-chart-of-accounts, bookkeeping-general-ledger,
  bookkeeping-document-attachment-integration, bookkeeping-bank-reconciliation`
  header, `REQ-AP-NNN` requirements using RFC 2119 keywords, and `#### Scenario:`
  blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline (COMPLETED — spec
  authored at `specs/bookkeeping-accounts-payable-core/spec.md`; `openspec
  validate` exits 0)

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec
  and including Affected Projects / Scope / Risks (dunning-workflow stability,
  payee-master ADR-022 question, AP aging performance) / Rollback / Open
  Questions (COMPLETED)

- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (sub-ledger
  materialises GL), D2 (OR dunning consumed with PHP-guard fallback), D3
  (write-off compensating posting), D4 (three aging report variants), D5 (payee
  is thin vendor master view), D6 (aging bucket config-driven), D7 (payment
  matching via bank-rec) (COMPLETED)

## Schema Declarations

- [ ] Task 5: Declare the `Payee` schema in `lib/Settings/shillinq_register.json`
  with all REQ-AP-002 fields (vendorNumber, name, tradingName, kvkNumber,
  btwNumber, paymentTermDays, defaultExpenseAccountNumber, bankAccount,
  creditTerms, dunningPolicyRef, address, email, phone, administrationId,
  lifecycleState, contactRef); use `schema:Organization` per ADR-011

- [ ] Task 6: Declare the `APTransaction` schema in
  `lib/Settings/shillinq_register.json` with all REQ-AP-003 fields (invoiceNumber,
  vendorId, invoiceDate, dueDate, currency, totalAmount, taxAmount, lines,
  sourceDocumentUri, state, glTransactionId, administrationId); use
  `schema:Invoice` per ADR-011

- [ ] Task 7: Declare the `DunningNotice` schema in
  `lib/Settings/shillinq_register.json` with all REQ-AP-005 fields (invoiceRef,
  reminderLevel, dispatchedAt, dispatchedBy, templateRef, acknowledgedAt,
  administrationId)

## Lifecycle & Aggregations

- [ ] Task 8: Add `x-openregister-lifecycle` to `APTransaction` declaring every
  transition in REQ-AP-004 (`draft → received → issued → paid` plus `overdue` /
  `disputed` / `written-off` / `voided`) consuming OR dunning-workflow per
  REQ-AP-005 (or `APGuard` fallback per ADR-031 exception, documented); ensure
  `issued → overdue` fires via OR's `ScheduledWorkflow` (path 2 per ADR-031)

- [ ] Task 9: Implement the write-off lifecycle action per REQ-AP-005 —
  materialises a compensating GL posting (debit bad-debt recovery/write-off
  account, credit AP payable) via T1's materialisation extension; audit-trailed
  reason required

- [ ] Task 10: Declare aged payables detail aggregation as
  `x-openregister-aggregations` query per REQ-AP-006 (GROUP BY
  `(vendorId, dueDateBucket)`, exclude paid/written-off, order by vendor +
  dueDate); bucket thresholds from `IAppConfig['ap.aging.buckets']` with
  defaults `[30, 60, 90]`

- [ ] Task 11: Declare aged payables summary aggregation as
  `x-openregister-aggregations` query per REQ-AP-007 (GROUP BY
  `(vendorId, agingBucket)`, SUM amount, exclude paid/written-off, order by
  bucket DESC + amount DESC); include count and percentage calculations

- [ ] Task 12: Declare aged payables timeline aggregation as
  `x-openregister-aggregations` query per REQ-AP-008 (GROUP BY `dueDate`,
  exclude paid/written-off, order by dueDate ASC); include daysUntilDue
  calculation and vendor summaries

- [ ] Task 13: Declare payment matching path per REQ-AP-009 — bank-rec emits
  candidate `ReconciliationMatch`; operator confirms via AP detail; AP lifecycle
  transitions `issued → paid` / `partially-paid → paid` via lifecycle action

## Manifest & Navigation

- [ ] Task 14: Add 4 manifest navigation entries (`Vendors`, `Accounts Payable`,
  `AP Aging`, `Dunning`) + their `type: index` / `type: aggregate` / `type:
  detail` pages to `src/manifest.json` per REQ-AP-010; `node
  tests/validate-manifest.js` exits 0

## Seed Data

- [ ] Task 15: Add seed data to `lib/Settings/shillinq_register.json`
  `components.objects[]` per REQ-AP-011: 3 realistic Dutch vendors (utilities,
  office supplies, professional services) + 5–8 AP invoices spanning lifecycle
  states (current, overdue 15d, overdue 45d, paid, disputed) with:
  - Dutch street names, valid postcodes (`[1-9][0-9]{3}[A-Z]{2}`)
  - Realistic KvK codes and BTW numbers
  - Amounts €500–€5000 with 19% VAT
  - `@self` envelope for idempotent imports
  - Verify re-importing with `force: false` skips duplicates

## Data Model Registry

- [ ] Task 16: Update `openspec/architecture/adr-000-data-model.md` with
  `Payee`/`APTransaction`/`DunningNotice` entries, reconciling against any
  existing `Vendor`/`APInvoice` data-model entries; cite schema.org vocabulary
  and T2 tier placement

## Testing & Verification

- [ ] Task 17: Deduplication check — verify no duplicate AP services exist in
  codebase; compare against `bookkeeping-accounts-receivable-core` AR mirror
  spec; document findings in task comment

- [ ] Task 18: Run `openspec validate` on the change folder — must exit 0

- [ ] Task 19: Bookkeeper-persona peer review (e.g. `/test-persona-janwillem`
  for SMB) confirms the AP flow matches Dutch SMB practice (vendor intake →
  invoice receipt → dunning escalation → payment match → GL posting → aging →
  write-off)

- [ ] Task 20: Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031
  compliance (no app-local dunning table; lifecycle declarative or
  ADR-031-exception-annotated guard; manifest carries the navigation; seed data
  is idempotent)

## Implementation Phase (opsx-apply)

These are _not_ blocked by this spec change; they occur in the implementing
cycle after spec approval:

- [ ] **PHPUnit Unit Tests** (implementing cycle): AP lifecycle state transitions,
  overdue auto-transition via OR scheduled-workflow, dunning timeline creation,
  write-off compensating posting, aged payables aggregation queries (detail /
  summary / timeline), payment-matching confirmation flow, invoice number
  uniqueness validation

- [ ] **Playwright Browser Tests** (implementing cycle): 4 manifest navigation
  entries (Vendors list/detail, AP list/detail, AP Aging reports, Dunning
  timeline); aged payables report filters and exports (CSV/PDF/JSON); dunning
  escalation workflow (if OR dunning-workflow is stable; else manual test of
  APGuard fallback)

- [ ] **CI Gate: composer test** (implementing cycle) — all tests green at PR
  merge time

- [ ] **User Documentation** (implementing cycle): `docs/user-guide/bookkeeping/
  accounts-payable.md` per ADR-030 journeydoc convention, with screenshots of AP
  invoice receipt → posting → aging report

- [ ] **i18n Translations** (implementing cycle): Dutch (`nl_NL`) and English
  (`en_US`) strings for: "Accounts Payable", "Vendor", "Vendors", "AP Invoice",
  "Dunning", "Reminder", "Formal Notice", "Collection", "Write-off", "Disputed",
  "Payment Terms", "Aging", "Issued", "Paid", "Overdue", "Partially Paid",
  "Current", "30–60 days", "60–90 days", "90+ days"

## Verification (Spec-Only)

`openspec validate` must exit clean on the change folder. Bookkeeper-persona
peer review confirms the AP flow matches Dutch SMB practice. Architecture
reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance. No source code changes
outside `openspec/changes/bookkeeping-accounts-payable-core/`.

## Dependencies & Gating

- **Blocks**: `bookkeeping-accounts-receivable-core` (AR spec, mirror to this AP
  spec; both depend on T1 GL). Both can proceed in parallel once chart-of-accounts
  is stable.
- **Blocked by**: `bookkeeping-chart-of-accounts` (must be spec-approved before
  AP spec goes live; provides account master)
- **May gate on**: `add-shillinq-general-ledger` stability (if GL materialisation
  pattern changes, AP lifecycle actions must adapt)
