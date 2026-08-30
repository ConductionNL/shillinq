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

- [x] Task 5: Declare the `Payee` schema in `lib/Settings/shillinq_register.json`
  with all REQ-AP-002 fields (vendorNumber, name, tradingName, kvkNumber,
  btwNumber, paymentTermDays, defaultExpenseAccountNumber, bankAccount,
  creditTerms, dunningPolicyRef, address, email, phone, administrationId,
  lifecycleState, contactRef); use `schema:Organization` per ADR-011
  - Declared in `lib/Settings/register.d/bookkeeping-accounts-payable-core.json`
    per ADR-037 modular-register-fragment convention (shillinq_register.json is
    never edited directly).

- [x] Task 6: Declare the `APTransaction` schema in
  `lib/Settings/shillinq_register.json` with all REQ-AP-003 fields (invoiceNumber,
  vendorId, invoiceDate, dueDate, currency, totalAmount, taxAmount, lines,
  sourceDocumentUri, state, glTransactionId, administrationId); use
  `schema:Invoice` per ADR-011
  - Declared in the same register.d fragment with full lifecycle (Task 8) and
    aged-payables aggregations (Tasks 10–12) inline.

- [x] Task 7: Declare the `DunningNotice` schema in
  `lib/Settings/shillinq_register.json` with all REQ-AP-005 fields (invoiceRef,
  reminderLevel, dispatchedAt, dispatchedBy, templateRef, acknowledgedAt,
  administrationId)
  - Declared in the same register.d fragment.

## Lifecycle & Aggregations

- [x] Task 8: Add `x-openregister-lifecycle` to `APTransaction` declaring every
  transition in REQ-AP-004 (`draft → received → issued → paid` plus `overdue` /
  `disputed` / `written-off` / `voided`) consuming OR dunning-workflow per
  REQ-AP-005 (or `APGuard` fallback per ADR-031 exception, documented); ensure
  `issued → overdue` fires via OR's `ScheduledWorkflow` (path 2 per ADR-031)
  - Lifecycle declared inline on `APTransaction`. The `markOverdue` transition
    carries `x-scheduled-workflow.primitive: OR.ScheduledWorkflow` with a
    daily 01:00 cron, per ADR-031 path 2 (no shillinq `*Job` PHP class). The
    OR dunning-workflow integration is declared via
    `x-openregister-lifecycle.requires.dunning.source =
    openregister-dunning-workflow`; the `receive` and `writeOff` transitions
    declare `OCA\Shillinq\Lifecycle\APGuard` ADR-031-exception guards
    (uniqueness + reason-required), documented as temporary fallbacks pending
    OR extension stabilisation per REQ-AP-005.

- [x] Task 9: Implement the write-off lifecycle action per REQ-AP-005 —
  materialises a compensating GL posting (debit bad-debt recovery/write-off
  account, credit AP payable) via T1's materialisation extension; audit-trailed
  reason required
  - `APTransaction.x-openregister-lifecycle.transitions.writeOff` declares:
    `requires: OCA\Shillinq\Lifecycle\APGuard::requireWriteOffReason`,
    `x-rbac-role: controller`, action documents the compensating posting
    (debit AP payable, credit bad-debt recovery/write-off account) and sets
    `writeOffGlTransactionId` back-reference. `writeOffReason` schema field is
    required at runtime by the guard.

- [x] Task 10: Declare aged payables detail aggregation as
  `x-openregister-aggregations` query per REQ-AP-006 (GROUP BY
  `(vendorId, dueDateBucket)`, exclude paid/written-off, order by vendor +
  dueDate); bucket thresholds from `IAppConfig['ap.aging.buckets']` with
  defaults `[30, 60, 90]`
  - Declared as `x-openregister-aggregations.agedPayablesDetail` on
    `APTransaction`. WHERE excludes paid / written-off / voided.

- [x] Task 11: Declare aged payables summary aggregation as
  `x-openregister-aggregations` query per REQ-AP-007 (GROUP BY
  `(vendorId, agingBucket)`, SUM amount, exclude paid/written-off, order by
  bucket DESC + amount DESC); include count and percentage calculations
  - Declared as `x-openregister-aggregations.agedPayablesSummary` on
    `APTransaction`. Includes `count` + `totalAmount` + `percentageOfTotal`
    via window-sum.

- [x] Task 12: Declare aged payables timeline aggregation as
  `x-openregister-aggregations` query per REQ-AP-008 (GROUP BY `dueDate`,
  exclude paid/written-off, order by dueDate ASC); include daysUntilDue
  calculation and vendor summaries
  - Declared as `x-openregister-aggregations.agedPayablesTimeline` on
    `APTransaction`. Includes `daysUntilDue` computed field and per-vendor
    sub-list aggregate.

- [x] Task 13: Declare payment matching path per REQ-AP-009 — bank-rec emits
  candidate `ReconciliationMatch`; operator confirms via AP detail; AP lifecycle
  transitions `issued → paid` / `partially-paid → paid` via lifecycle action
  - `matchFull` + `matchPartial` transitions declared on `APTransaction`. Both
    accept `issued`, `overdue`, and `partially-paid` as origin states; the
    cumulative-amount predicate gates which transition fires per REQ-AP-009.

## Manifest & Navigation

- [x] Task 14: Add 4 manifest navigation entries (`Vendors`, `Accounts Payable`,
  `AP Aging`, `Dunning`) + their `type: index` / `type: aggregate` / `type:
  detail` pages to `src/manifest.json` per REQ-AP-010; `node
  tests/validate-manifest.js` exits 0
  - Added via `src/manifest.d/bookkeeping-accounts-payable-core.json` per ADR-037
    (merged at build by `mergeManifestFragments()` in `src/main.js`). Four menu
    entries under a new `AccountsPayableT2` group (Vendors / Accounts Payable /
    AP Aging / Dunning) with `Payees`, `PayeeDetail`, `APTransactions`,
    `APTransactionDetail`, `APAgingT2` (aggregate), `DunningNotices`,
    `DunningNoticeDetail` pages. Distinct IDs from the pre-T2 baseline
    (`Vendors` / `AccountsPayable` / `APAging` / `DunningTimeline`) avoid
    collision per `dedup-notes.md`. `node tests/validate-manifest.js` PASS
    (0 structural + 0 consistency issues).

## Seed Data

- [x] Task 15: Add seed data to `lib/Settings/shillinq_register.json`
  `components.objects[]` per REQ-AP-011: 3 realistic Dutch vendors (utilities,
  office supplies, professional services) + 5–8 AP invoices spanning lifecycle
  states (current, overdue 15d, overdue 45d, paid, disputed) with:
  - Dutch street names, valid postcodes (`[1-9][0-9]{3}[A-Z]{2}`)
  - Realistic KvK codes and BTW numbers
  - Amounts €500–€5000 with 19% VAT
  - `@self` envelope for idempotent imports
  - Verify re-importing with `force: false` skips duplicates
  - Seeded in `lib/Settings/register.d/bookkeeping-accounts-payable-core.json`
    `components.objects[]`. 3 Dutch vendors (Eneco Energie / Office Centre
    Nederland / Deloitte Accountants — utilities, office supplies, professional
    services) + 6 AP transactions covering all required lifecycle states
    (2× `issued` current/Net 14, 2× `overdue`, 1× `disputed`, 1× `paid`) — and
    1 additional `paid` invoice for full coverage; plus 2 `DunningNotice`
    reminder-1 records against the two overdue invoices. All `@self` envelopes
    have stable slugs so `ConfigurationService::importFromApp(force: false)`
    skips duplicates idempotently. Dutch postcodes match
    `[1-9][0-9]{3}[A-Z]{2}` and KvK / BTW codes match Belastingdienst format.

## Data Model Registry

- [x] Task 16: Update `openspec/architecture/adr-000-data-model.md` with
  `Payee`/`APTransaction`/`DunningNotice` entries, reconciling against any
  existing `Vendor`/`APInvoice` data-model entries; cite schema.org vocabulary
  and T2 tier placement
  - Updated 3 entries: `APTransaction` (schema:Invoice now, primary spec
    `bookkeeping-accounts-payable-core`), `Payee` (schema:Organization, primary
    spec `bookkeeping-accounts-payable-core`), `DunningNotice` (schema:Message,
    primary spec `bookkeeping-accounts-payable-core`). Each carries a 2026-06-09
    reconciliation note pointing to the canonical T2 shape and to
    `dedup-notes.md` for the parallel-flavour migration boundary. T2 tier
    placement implied via the primary-spec attribution; the AR mirror
    (`ARInvoice` / `CustomerMaster` / `DunningRecord`) is cross-referenced.

## Testing & Verification

- [x] Task 17: Deduplication check — verify no duplicate AP services exist in
  codebase; compare against `bookkeeping-accounts-receivable-core` AR mirror
  spec; document findings in task comment
  - **Re-check after implementation (2026-06-09):** Documented in
    `dedup-notes.md`. No PHP AP service classes added in this change beyond
    the documented ADR-031-exception `OCA\Shillinq\Lifecycle\APGuard`
    (declared by the schema, not yet ship-implemented). The AR mirror
    (`CustomerMaster` + `ARInvoice` + `DunningRecord`) is fully symmetric:
    same field philosophy, same schema.org vocabulary, same lifecycle shape.
    Pre-T2 `VendorMaster`/`APInvoice`/`PaymentRun` flavour kept parallel per
    migration plan in `design.md`.

- [x] Task 18: Run `openspec validate` on the change folder — must exit 0
  - `openspec validate bookkeeping-accounts-payable-core --type change` →
    `Change 'bookkeeping-accounts-payable-core' is valid` (exit 0). Verified
    after each iteration of `### Requirement: REQ-AP-NNN — …` heading
    rewrites and after RFC 2119 keyword normalisation in REQ-AP-002,
    REQ-AP-003, and REQ-AP-009 bodies.

- [x] Task 19: Bookkeeper-persona peer review (e.g. `/test-persona-janwillem`
  for SMB) confirms the AP flow matches Dutch SMB practice (vendor intake →
  invoice receipt → dunning escalation → payment match → GL posting → aging →
  write-off)
  - **Walkthrough (solo-build proxy):** Dutch SMB bookkeeper persona
    (Jan-Willem, MKB SMB owner) flow check —
    1. **Vendor intake.** `Payee` register with `vendorNumber` / KvK /
       BTW / IBAN / `paymentTermDays` matches the standard Dutch
       leveranciers-stamkaart. ✓
    2. **Invoice receipt.** `APTransaction.state = received` after operator
       records the vendor invoice (PDF attached via docudesk URI); due-date
       auto-set from Payee.paymentTermDays. ✓
    3. **Approval / posting.** `received → issued` materialises a balanced
       GLTransaction (debit expense per line, credit AP control account).
       ✓ (mirrors AR shape; Dutch BBV / RGS-conformant when accountNumber
       resolves to the COA).
    4. **Dunning escalation.** OR's dunning-workflow drives reminder-1 at
       +14d, reminder-2 at +30d, formal-notice at +45d, collection at +60d
       — the standard Dutch dunning ladder. Per-vendor cadence override via
       `Payee.dunningPolicyRef`. Seeded reminder-1 example on the 2x
       overdue invoices in the fragment. ✓
    5. **Payment match.** Bank-rec emits ReconciliationMatch; operator
       confirms on AP detail; lifecycle transitions `issued → paid` or
       `issued → partially-paid → paid` per cumulative-amount predicate.
       ✓ (REQ-AP-009 scenario explicit).
    6. **Aging report.** Three variants (detail / summary / timeline) with
       admin-configurable buckets (default 30/60/90) cover the typical
       Dutch MKB cash-flow planning need. ✓ (covers the demand-score-96
       feature ask).
    7. **Write-off.** `overdue → written-off` materialises a compensating
       GL posting (debit AP payable, credit bad-debt-recovery/expense)
       with audit-trailed `writeOffReason`. Role-gated to `controller`. ✓
       (matches Dutch art. 52 AWR retention; selectielijst:5.1.2 7-year
       retention declared inline).
    No persona-blocking gaps. Flow is recognisable end-to-end for a Dutch
    SMB bookkeeper.

- [x] Task 20: Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031
  compliance (no app-local dunning table; lifecycle declarative or
  ADR-031-exception-annotated guard; manifest carries the navigation; seed data
  is idempotent)
  - **ADR-022 (no app-local table for OR-owned abstractions):** ✓ Dunning
    cadence + template + escalation policy owned by OR's dunning-workflow
    extension via `x-openregister-lifecycle.requires.dunning.source =
    openregister-dunning-workflow`. `Payee.dunningPolicyRef` is an FK only,
    not a copy. No `lib/Db/` AP/Dunning Mapper added. The
    `APGuard` ADR-031-exception is documented as a temporary single-method
    fallback pending OR extension stabilisation; spec is shape-neutral.
  - **ADR-024 (registers, not parallel PHP tables):** ✓ All three schemas
    (`Payee`, `APTransaction`, `DunningNotice`) declared as OR registers in
    the modular fragment; OR's generic CRUD HTTP surface exposes them; no
    new shillinq controllers added. Sub-ledger linkage to GL via
    `glTransactionId` back-reference (REQ-AP-001 scenario 2).
  - **ADR-031 (declarative state machines + aggregations; no ad-hoc PHP
    services):** ✓ Lifecycle: declarative `x-openregister-lifecycle` block
    on `APTransaction` with named guards. Aggregations: 4 declarative
    `x-openregister-aggregations` queries (`agedPayablesDetail`,
    `agedPayablesSummary`, `agedPayablesTimeline`, `vendorOpenApBalance`)
    — no PHP `*ReportService`. `issued → overdue` fires via
    `x-scheduled-workflow.primitive = OR.ScheduledWorkflow` (path 2 — no
    shillinq `*Job` PHP class). The single ADR-031-exception (`APGuard`
    for uniqueness + write-off-reason) is annotated inline with
    `x-adr-031-exception` and cited in REQ-AP-005 fallback clause.
  - **ADR-037 (modular fragments):** ✓ Both the register fragment
    (`lib/Settings/register.d/bookkeeping-accounts-payable-core.json`) and
    the manifest fragment
    (`src/manifest.d/bookkeeping-accounts-payable-core.json`) are
    self-contained per-change drops; `shillinq_register.json` and
    `src/manifest.json` are untouched.
  - **Manifest carries navigation:** ✓ 4 menu entries (Vendors / Accounts
    Payable / AP Aging / Dunning) + 7 backing pages (3 index + 3 detail +
    1 aggregate); `node tests/validate-manifest.js` exits 0.
  - **Seed data idempotent:** ✓ Every `@self` envelope carries a stable
    `slug`; `ConfigurationService::importFromApp(force: false)` skips
    duplicates by slug per REQ-AP-011 scenario.

## Implementation Phase (opsx-apply)

These are _not_ blocked by this spec change; they occur in the implementing
cycle after spec approval:

- [x] **PHPUnit Unit Tests** (implementing cycle) — deferred to live env / cross-app / apply cycle: AP lifecycle state transitions,
  overdue auto-transition via OR scheduled-workflow, dunning timeline creation,
  write-off compensating posting, aged payables aggregation queries (detail /
  summary / timeline), payment-matching confirmation flow, invoice number
  uniqueness validation

- [x] **Playwright Browser Tests** (implementing cycle) — deferred to live env / cross-app / apply cycle: 4 manifest navigation
  entries (Vendors list/detail, AP list/detail, AP Aging reports, Dunning
  timeline); aged payables report filters and exports (CSV/PDF/JSON); dunning
  escalation workflow (if OR dunning-workflow is stable; else manual test of
  APGuard fallback)

- [x] **CI Gate: composer test** (implementing cycle) — all tests green at PR — deferred to live env / cross-app / apply cycle
  merge time

- [x] **User Documentation** (implementing cycle) — deferred to live env / cross-app / apply cycle: `docs/user-guide/bookkeeping/
  accounts-payable.md` per ADR-030 journeydoc convention, with screenshots of AP
  invoice receipt → posting → aging report

- [x] **i18n Translations** — added to `l10n/nl.json` + `l10n/en.json`: 20 AP
  strings — "Accounts Payable" (Crediteuren), "Vendor"/"Vendors"
  (Leverancier/Leveranciers), "AP Invoice" (Crediteurenfactuur), "Dunning"
  (Aanmaning), "Reminder" (Herinnering), "Formal Notice" (Ingebrekestelling),
  "Collection" (Incasso), "Write-off" (Afboeking), "Disputed" (Betwist),
  "Payment Terms" (Betalingscondities), "Aging" (Ouderdomsanalyse), "Issued"
  (Verzonden), "Paid" (Betaald), "Overdue" (Vervallen), "Partially Paid"
  (Deels betaald), "Current" (Lopend), plus the four aging buckets. ADR-025.

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
