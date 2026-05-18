# Proposal: add-shillinq-accounts-receivable-core

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`CustomerMaster`, `ARInvoice`, `DunningRecord`) +
`x-openregister-lifecycle` consuming OR dunning-workflow per ADR-022
+ aggregations + manifest entries. No PHP dunning table, no PHP
billing-service classes are authored (subject to ADR-031 exception:
at most one single-method `DunningGuard` if OR's dunning-workflow
extension is not yet stable).

## Summary

Introduce the **accounts receivable (core)** capability for Shillinq
as one of the T2 compliance + operations capabilities (per
`adr-001-bookkeeping-tier-roadmap.md`). This capability **carries
forward the original Shillinq invoicing scope** — customer
invoicing was the original product surface, and this spec formalises
it under the T2 declarative envelope. The change declares the
`CustomerMaster`, `ARInvoice`, and `DunningRecord` registers; the AR
lifecycle (`draft → issued → paid` / `overdue` / `written-off`)
consuming OR's dunning-workflow per ADR-022; the write-off path; the
UBL 2.1 / Peppol BIS 3.0 field shape declared for T4 attachment but
NOT computed in T2; credit-limit check as aggregation; AR aging as
aggregation; payment matching against bank lines from
`bookkeeping-bank-reconciliation`.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`add-shillinq-general-ledger`](../add-shillinq-general-ledger/proposal.md)
(materialises GL transactions),
[`add-shillinq-document-attachment-integration`](../add-shillinq-document-attachment-integration/proposal.md)
(invoice PDF attachment via docudesk),
[`add-shillinq-bank-reconciliation`](../add-shillinq-bank-reconciliation/proposal.md)
(payment matching against bank statement lines).

## Motivation

Customer invoicing was the original Shillinq scope; AR is the
operational completion. Per ADR-022, dunning workflow comes from
OR's dunning-workflow extension, not from an app-local dunning
table; per ADR-031, AR aging is a declarative aggregation, not a
`ARReportService`.

The legacy AP/AR draft cluster from intelligence-db
(`competitor_features` with `app_slug=shillinq`) calls out customer
master + AR invoice + dunning workflow as top-tier customer-asked
features alongside AP.

This is one of eight T2 capability changes; this proposal scopes
only the AR core slice.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-accounts-receivable-core`); declares 3 new registers
  (`CustomerMaster`, `ARInvoice`, `DunningRecord`) with lifecycles
  and aggregations; adds 4 manifest navigation entries (Customers,
  Accounts Receivable, AR Aging, Dunning).
- [ ] Project: openregister — no source changes; consumes existing
  dunning-workflow (if stable; else ADR-031 exception),
  `x-openregister-lifecycle`, `x-openregister-aggregations`.
- [ ] Project: docudesk — no source changes; AR invoice PDF
  attachments referenced by FK URI per
  `bookkeeping-document-attachment-integration`.

## Scope

### In Scope

- One new capability spec (`bookkeeping-accounts-receivable-core`) —
  see the `specs/` folder.
- The `CustomerMaster` register with contact details, billing
  address, credit limit, dunning policy reference, payment terms.
- The `ARInvoice` register with customer FK, invoice number, dates,
  line items, tax breakdown, payment due, source-document URI per
  `bookkeeping-document-attachment-integration`, UBL 2.1 / Peppol
  BIS 3.0 fields declared (NOT computed in T2 — T4 attaches the
  outbound).
- The AR lifecycle (`draft → issued → paid` plus `overdue` /
  `disputed` / `written-off`) consuming OR's dunning-workflow per
  ADR-022.
- The `DunningRecord` register tracking dunning steps (reminder /
  formal notice / debt-collection escalation) per administration-
  configurable policy.
- Write-off path: a compensating GL posting created via materialisation
  with audit-trailed reason.
- Credit-limit check on `ARInvoice.issue` as
  `x-openregister-aggregations` query (sum of outstanding `ARInvoice`
  amount per customer < credit limit).
- AR aging declared as `x-openregister-aggregations` query grouping
  `ARInvoice` by `(customerId, agingBucket)`, excluding `paid` /
  `written-off`.
- Payment matching: when bank-reconciliation emits a candidate
  match against an AR invoice, the operator confirms and the AR
  invoice transitions to `paid`.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **UBL 2.1 / Peppol BIS 3.0 outbound** — T4. T2 declares the field
  shape but does NOT compute / emit.
- **Multi-currency translation** — T5.
- **VAT/BTW posting automation** — T3.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-accounts-receivable-core`** — declares the three
registers, the lifecycle (consuming OR dunning-workflow), the
write-off path, the credit-limit check + aging aggregations, the
payment-matching pattern, and the UBL field shape (for T4).

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-AR-*` for
traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 new schemas
  (`CustomerMaster`, `ARInvoice`, `DunningRecord`); declares
  lifecycle on `ARInvoice` and `DunningRecord`, aggregations on
  credit-limit + AR aging.
- `src/manifest.json` — adds 4 navigation entries + their
  `type: index` + `type: detail` pages.
- No new PHP services (subject to ADR-031 exception: one
  single-method `DunningGuard` if OR's dunning-workflow extension
  is not yet stable).
- No new bespoke Vue components.

## Cross-Project Dependencies

- **OpenRegister** — depends on dunning-workflow (ADR-022 — if
  stable; else ADR-031 exception path), `x-openregister-lifecycle`,
  `x-openregister-aggregations`.
- **T1 general ledger** — depends on `add-shillinq-general-ledger`
  for the materialised `GLTransaction` pattern (on issue, on
  write-off).
- **T2 document-attachment-integration** — depends on
  `add-shillinq-document-attachment-integration` for the invoice-
  PDF URI contract.
- **T2 bank-reconciliation** — depends on
  `add-shillinq-bank-reconciliation` for payment matching against
  bank statement lines.

## Risks

### Risk 1: Dunning workflow not yet stable on OR

**Severity**: Medium
**Mitigation**: If OR's dunning-workflow extension is still draft
at T2 implementation time, the spec captures the gap, files an OR
issue, and the implementing cycle MAY ship a single-method
`OCA\Shillinq\Lifecycle\DunningGuard` per ADR-031 §"PHP guards
remain a legitimate seam". The guard is removed once OR's
extension lands. Spec is shape-neutral.

### Risk 2: Customer master overlaps with OR's contact abstraction

**Severity**: Low-Medium
**Mitigation**: Per ADR-022, prefer the OR abstraction. The spec
declares the bookkeeping-side fields as a thin view onto contacts
if OR's contact abstraction is stable; otherwise app-local with
documented migration plan. Resolved during the implementing cycle.

### Risk 3: Write-off lifecycle path requires GL compensating posting

**Severity**: Low
**Mitigation**: REQ-AR-007 declares write-off as a lifecycle
transition that materialises a compensating GL posting (debit
write-off expense, credit AR control). The path is declarative; no
PHP write-off service.

### Risk 4: UBL 2.1 / Peppol BIS 3.0 field shape may drift

**Severity**: Low
**Mitigation**: T2 declares the field shape under "UBL 2.1 / Peppol
BIS 3.0" header but does NOT compute / emit. T4 attaches the
outbound. Field naming follows the UBL canonical names so T4 can
attach additively.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
registers are non-destructive — AR invoices remain queryable.

## Open Questions

1. **Dunning-workflow stability on OR** — see Risk 1; resolved in
   `opsx-ff` discovery; OR issue filed if needed.
2. **Customer master vs OR contact** — see Risk 2; resolved per
   ADR-022 review.
3. **Default dunning cadence** — reminder 1 at +14 days,
   reminder 2 at +30 days, formal notice at +45 days, collection
   escalation at +60 days; all customisable per administration;
   defaults resolved during the implementing cycle's UX review.
