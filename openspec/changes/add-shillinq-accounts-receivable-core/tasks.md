# Tasks — Accounts Receivable (Core)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-accounts-receivable-core` spec — they are recorded now so
> the spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-accounts-receivable-core` capability spec already exists, no `CustomerMaster`/`ARInvoice`/`DunningRecord` schemas are declared, and no `lib/Service/AR*` / `lib/Service/Dunning*` PHP classes are present (per ADR-031 anti-pattern enumeration); explicitly note this capability "carries forward the original Shillinq invoicing scope" — **VERIFIED 2026-06-01**: `lib/Settings/shillinq_register.json` contains no AR-related schemas; `lib/Service/` contains no `AR*` or `Dunning*` classes; no `bookkeeping-accounts-receivable-core` spec existed prior to this change.
- [x] Task 2: Author `specs/bookkeeping-accounts-receivable-core/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T2 (compliance + operations)` / `Depends on: bookkeeping-general-ledger, bookkeeping-document-attachment-integration, bookkeeping-bank-reconciliation` header, `REQ-AR-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-022 + ADR-031 inline — **DONE**: `specs/bookkeeping-accounts-receivable-core/spec.md` authored with REQ-AR-001 through REQ-AR-010.
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (dunning-workflow stability, customer-master ADR-022 question, UBL field shape) / Rollback / Open Questions — **DONE**: `proposal.md` authored with `kind: config`, full Scope, Risks, Rollback, and Open Questions.
- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (sub-ledger materialises GL), D2 (OR dunning consumed with PHP-guard fallback), D3 (write-off compensating posting), D4 (credit-limit aggregation), D5 (AR aging aggregation), D6 (UBL declared not computed) — **DONE**: `design.md` authored with all six decisions and full Reuse Analysis / Declarative-vs-imperative decision table.
- [ ] Task 5: Declare the `CustomerMaster` schema in `lib/Settings/shillinq_register.json` with all REQ-AR-002 fields (customerNumber, name, billingAddress, creditLimit, dunningPolicyId, paymentTerms, taxRegistration, contact details, administrationId)
- [ ] Task 6: Declare the `ARInvoice` schema in `lib/Settings/shillinq_register.json` with all REQ-AR-003 fields (customerId, invoiceNumber, invoiceDate, dueDate, currency, amount, taxLines, lineItems, sourceDocumentUri, ublPartyId, ublDocumentTypeCode, state, periodId, administrationId) — UBL fields declared but not computed
- [ ] Task 7: Declare the `DunningRecord` schema in `lib/Settings/shillinq_register.json` with all REQ-AR-005 fields (invoiceId, step, sentAt, channel, payload, escalationLevel, administrationId)
- [ ] Task 8: Add `x-openregister-lifecycle` to `ARInvoice` declaring every transition in REQ-AR-004 (`draft → issued → paid` plus `overdue` / `disputed` / `written-off`) consuming OR dunning-workflow per REQ-AR-005 (or `DunningGuard` fallback per ADR-031 exception, documented)
- [ ] Task 9: Implement the write-off lifecycle action per REQ-AR-007 — materialises a compensating GL posting (debit write-off expense, credit AR control) via T1's materialisation extension; audit-trailed reason required
- [ ] Task 10: Declare credit-limit precondition on `ARInvoice.issue` as `x-openregister-aggregations` predicate per REQ-AR-006 (SUM(outstanding ARInvoice.amount where customerId) + this.amount <= CustomerMaster.creditLimit) — not a service
- [ ] Task 11: Declare AR aging as `x-openregister-aggregations` query grouping `ARInvoice` by `(customerId, agingBucket)` per REQ-AR-008 (buckets from `today - dueDate`; exclude `paid` / `written-off`)
- [ ] Task 12: Declare payment matching path per REQ-AR-009 — bank-rec emits candidate `ReconciliationMatch`; operator confirms; AR lifecycle transitions `issued → paid` via lifecycle action
- [ ] Task 13: Add 4 manifest navigation entries (`Customers`, `Accounts Receivable`, `AR Aging`, `Dunning`) + their `type: index` / `type: detail` pages to `src/manifest.json` per REQ-AR-010; `node tests/validate-manifest.js` exits 0
- [x] Task 14: Update `openspec/architecture/adr-000-data-model.md` with `CustomerMaster`/`ARInvoice`/`DunningRecord` entries, reconciling against any existing `Customer`/`Invoice` data-model entries — **DONE**: three entities added in ASCII alphabetical order; ARInvoice carries a reconciliation note against the existing `Invoice` entry; entity count updated 225 → 228.

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB) confirms the AR flow matches Dutch SMB practice (customer intake → invoice issue → dunning escalation → payment match → GL posting → aging → write-off). Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local dunning table; lifecycle declarative or ADR-031-exception-annotated guard; manifest carries the navigation). No source code changes outside `openspec/changes/add-shillinq-accounts-receivable-core/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests for AR lifecycle, overdue auto-transition, dunning timeline, write-off compensating posting, credit-limit aggregation rejection, AR aging buckets (pre-declared on Tasks 5–12); Playwright MCP browser tests for the 4 manifest navigation entries (pre-declared on Task 13); `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/accounts-receivable.md` per ADR-030 journeydoc convention and commits AR invoice + dunning timeline screenshots to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Accounts Receivable`, `Customer`, `Customers`, `AR Invoice`, `Dunning`, `Reminder`, `Formal Notice`, `Collection`, `Write-off`, `Disputed`, `Credit Limit`, `Aging`, `Issued`, `Paid`, `Overdue`.
