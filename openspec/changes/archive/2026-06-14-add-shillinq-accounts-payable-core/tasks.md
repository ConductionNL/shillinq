# Tasks — Accounts Payable (Core)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-accounts-payable-core` spec — they are recorded now so the
> spec-review gate, dependency planning, and tier-cascade impact are all
> visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-accounts-payable-core` capability spec already exists, no `VendorMaster`/`APInvoice`/`PaymentRun` schemas are declared, and no `lib/Service/AP*` or `lib/Service/Sepa*` / `lib/Service/Payment*` PHP classes are present (per ADR-031 anti-pattern enumeration)
- [x] Task 2: Author `specs/bookkeeping-accounts-payable-core/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: bookkeeping-general-ledger, bookkeeping-document-attachment-integration` header, `REQ-AP-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline; explicitly address the legacy AP/AR draft intelligence-db cluster
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (3-way match conditional, SEPA pain.001 downloadable, vendor-master ADR-022 question) / Rollback / Open Questions
- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (sub-ledger materialises GL), D2 (OR approval-workflow consumed), D3 (conditional 3-way match), D4 (PaymentRun is a register), D5 (AP aging is aggregation)
- [x] Task 5: Declare the `VendorMaster` schema in `lib/Settings/shillinq_register.json` with all REQ-AP-002 fields (vendorNumber, name, iban, paymentTerms, taxRegistration, dunningPolicyId, contact details, administrationId)
- [x] Task 6: Declare the `APInvoice` schema in `lib/Settings/shillinq_register.json` with all REQ-AP-003 fields (vendorId, invoiceNumber, invoiceDate, dueDate, currency, amount, taxLines, lineItems, sourceDocumentUri, poRef, grRef, state, periodId, administrationId)
- [x] Task 7: Declare the `PaymentRun` schema in `lib/Settings/shillinq_register.json` with all REQ-AP-007 fields (runId, runDate, selectedInvoiceIds, totalAmount, currency, sepaXml, state, administrationId)
- [x] Task 8: Add `x-openregister-lifecycle` to `APInvoice` declaring every transition in REQ-AP-004 (`draft → submitted → approved → posted → scheduled → paid` plus `disputed` / `voided`) consuming OR approval-workflow per REQ-AP-005
- [x] Task 9: Implement the 3-way match precondition on `APInvoice.post` per REQ-AP-006 — declare it inside `x-openregister-lifecycle.requires` (preferred) OR if engine cannot express conditional clauses, register `OCA\Shillinq\Lifecycle\ThreeWayMatchGuard::matches(string $invoiceId, ?string $poRef, ?string $grRef): bool` (single-method, ~20 LOC, ADR-031 exception annotated)
- [x] Task 10: Declare the SEPA pain.001 XML composition as `x-openregister-calculations` on `PaymentRun.sepaXml` per REQ-AP-007 (pin pain.001.001.03 schema version); declare iDEAL link generation as per-invoice calculation on `APInvoice.idealLink`
- [x] Task 11: Declare AP aging as `x-openregister-aggregations` query grouping `APInvoice` by `(vendorId, agingBucket)` per REQ-AP-009 (buckets computed from `today - dueDate`; exclude `paid` / `voided`)
- [x] Task 12: Declare materialisation lifecycle action on `APInvoice.post` per T1 `JournalEntry` REQ-JE-007 pattern — emits one balanced `GLTransaction` with vendor + expense lines
- [x] Task 13: Add 4 manifest navigation entries (`Vendors`, `Accounts Payable`, `AP Aging`, `Payment Runs`) + their `type: index` / `type: detail` pages to `src/manifest.json` per REQ-AP-010; `node tests/validate-manifest.js` exits 0
- [x] Task 14: Update `openspec/architecture/adr-000-data-model.md` with `VendorMaster`/`APInvoice`/`PaymentRun` entries, reconciling against any existing `Vendor`/`Invoice` data-model entries

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB) confirms the AP flow matches Dutch SMB practice (vendor intake → invoice approval → 3-way match → payment run → SEPA download → GL posting → aging). Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local approval table; no PHP SEPA generator; lifecycle declarative or ADR-031-exception-annotated guard; manifest carries the navigation). No source code changes outside `openspec/changes/add-shillinq-accounts-payable-core/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests for AP lifecycle, SEPA XML schema validation against pain.001.001.03, 2-way and 3-way match, AP aging buckets, materialised GL balance (pre-declared on Tasks 5–12); Playwright MCP browser tests for the 4 manifest navigation entries (pre-declared on Task 13); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/accounts-payable.md` per ADR-030 journeydoc convention and commits AP invoice + payment run screenshots to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Accounts Payable`, `Vendor`, `Vendors`, `AP Invoice`, `Payment Run`, `Aging`, `Approved`, `Disputed`, `Scheduled`, `Paid`, `SEPA Export`, `iDEAL Link`.
