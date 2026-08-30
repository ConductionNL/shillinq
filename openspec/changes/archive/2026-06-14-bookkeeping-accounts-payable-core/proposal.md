# Proposal: bookkeeping-accounts-payable-core

`kind: config` per ADR-032 — the centre of mass is declarative schemas
(`Payee`, `APTransaction`, `DunningNotice`) + account payable lifecycle
consuming OR's dunning-workflow per ADR-022 + aggregations + manifest
entries. No PHP AP table, no PHP AP-service classes are authored (subject
to ADR-031 exception: at most one single-method `APGuard` if OR's
dunning-workflow extension is not yet stable).

## Summary

Introduce the **accounts payable (core)** capability for Shillinq as one
of the T2 compliance + operations capabilities (per
`adr-001-bookkeeping-tier-roadmap.md`). This capability establishes the
foundational AP data model, invoice tracking, and vendor payment
scheduling. The change declares the `Payee`, `APTransaction`, and
`DunningNotice` registers; the AP lifecycle (`draft → issued → paid` /
`overdue` / `written-off`) consuming OR's dunning-workflow per ADR-022;
the write-off path; aged payables reporting as aggregations with payment
scheduling detail; and vendor management.

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md)
spec for app structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`bookkeeping-chart-of-accounts`](../add-shillinq-chart-of-accounts/proposal.md)
(account master data), [`bookkeeping-general-ledger`](../add-shillinq-general-ledger/proposal.md)
(materialised GL transactions), [`bookkeeping-document-attachment-integration`](../add-shillinq-document-attachment-integration/proposal.md)
(invoice PDF attachment via docudesk), [`bookkeeping-bank-reconciliation`](../add-shillinq-bank-reconciliation/proposal.md)
(payment matching against bank statement lines).

## Motivation

Accounts payable is the operational mirror to accounts receivable. Dutch
SMBs must track vendor invoices, manage payment schedules, and age payables
for cash-flow planning. Aged payables reports with scheduling detail
(showing upcoming obligations by due date) are market-demanded features
(demand score: 96 across three survey variants).

Per ADR-022, dunning workflow comes from OR's dunning-workflow extension,
not from an app-local table; per ADR-031, AP aging and payment scheduling
are declarative aggregations, not `APReportService` PHP classes.

The legacy AP/AR draft cluster from intelligence-db (`competitor_features`
with `app_slug=shillinq`) calls out vendor master + AP invoice + dunning
workflow as top-tier customer-asked features. This is one of eight T2
capability changes; this proposal scopes only the AP core slice.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-accounts-payable-core`);
  declares 3 new registers (`Payee`, `APTransaction`, `DunningNotice`) with
  lifecycles and aggregations; adds 4 manifest navigation entries (Vendors,
  Accounts Payable, AP Aging, Dunning).
- [ ] Project: openregister — no source changes; consumes existing dunning-workflow
  (if stable; else ADR-031 exception), `x-openregister-lifecycle`,
  `x-openregister-aggregations`.
- [ ] Project: docudesk — no source changes; AP invoice PDF attachments
  referenced by FK URI per `bookkeeping-document-attachment-integration`.

## Scope

### In Scope

- One new capability spec (`bookkeeping-accounts-payable-core`) — see the `specs/`
  folder.
- The `Payee` register with vendor contact details, payment address, tax ID,
  bank account (IBAN), dunning policy reference, payment terms.
- The `APTransaction` register with vendor FK, invoice number, dates, line items,
  tax breakdown, payment due, source-document URI per
  `bookkeeping-document-attachment-integration`.
- The AP lifecycle (`draft → issued → paid` plus `overdue` / `disputed` /
  `written-off`) consuming OR's dunning-workflow per ADR-022.
- The `DunningNotice` register tracking dunning steps (reminder / formal notice /
  debt-collection escalation) per administration-configurable policy.
- Write-off path: a compensating GL posting created via materialisation with
  audit-trailed reason.
- Aged payables aggregation with vendor payment scheduling detail (showing
  upcoming obligations grouped by due date bucket and vendor).
- Payables aging analysis with three variants: detail (per-invoice breakdown),
  summary (by vendor), and timeline (by due date).

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue components,
  controllers, tests, and CI changes are deliberately not in this proposal;
  the task list references them but the implementation lands via a separate
  `opsx-apply` cycle.
- **Multi-currency posting** — T5. AP invoices reference EUR only in T2.
- **VAT/BTW reverse-charge automation** — T3. Reverse charge scenarios are
  manually configured per invoice; no automatic determination.
- **Peppol BIS 3.0 inbound e-invoicing** — future. Manual vendor invoice upload
  is the T2 intake path.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-accounts-payable-core`** — declares the three registers, the
lifecycle (consuming OR dunning-workflow), the write-off path, the aged
payables aggregations with scheduling detail, and vendor management.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`,
`#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement
is prefixed `REQ-AP-*` for traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the already-bumped
`@conduction/nextcloud-vue@^1.0.0-beta.66`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 new schemas (`Payee`,
  `APTransaction`, `DunningNotice`); declares lifecycle on `APTransaction`
  and `DunningNotice`, aggregations on aged payables + scheduling.
- `src/manifest.json` — adds 4 navigation entries + their `type: index` +
  `type: detail` pages.
- No new PHP services (subject to ADR-031 exception: one single-method `APGuard`
  if OR's dunning-workflow extension is not yet stable).
- No new bespoke Vue components.

## Cross-Project Dependencies

- **OpenRegister** — depends on dunning-workflow (ADR-022 — if stable; else
  ADR-031 exception path), `x-openregister-lifecycle`,
  `x-openregister-aggregations`.
- **T1 chart of accounts** — depends on `bookkeeping-chart-of-accounts` for
  account master data and AP control account reference.
- **T1 general ledger** — depends on `bookkeeping-general-ledger` for materialised
  `GLTransaction` pattern (on issue, on write-off).
- **T2 document-attachment-integration** — depends on
  `bookkeeping-document-attachment-integration` for invoice PDF URI contract.
- **T2 bank-reconciliation** — depends on `bookkeeping-bank-reconciliation`
  for payment matching against bank statement lines.

## Risks

### Risk 1: Dunning workflow not yet stable on OR

**Severity**: Medium
**Mitigation**: If OR's dunning-workflow extension is still draft at T2
implementation time, the spec captures the gap, files an OR issue, and the
implementing cycle MAY ship a single-method `OCA\Shillinq\Lifecycle\APGuard`
per ADR-031 §"PHP guards remain a legitimate seam". The guard is removed
once OR's extension lands. Spec is shape-neutral.

### Risk 2: Payee master overlaps with OR's contact abstraction

**Severity**: Low-Medium
**Mitigation**: Per ADR-022, prefer the OR abstraction. The spec declares the
bookkeeping-side fields as a thin view onto contacts if OR's contact
abstraction is stable; otherwise app-local with documented migration plan.
Resolved during the implementing cycle.

### Risk 3: Write-off lifecycle path requires GL compensating posting

**Severity**: Low
**Mitigation**: REQ-AP-006 declares write-off as a lifecycle transition that
materialises a compensating GL posting (credit AP payable, debit expense).
The path is declarative; no PHP write-off service.

### Risk 4: Aged payables aggregation performance with large invoice volumes

**Severity**: Low-Medium
**Mitigation**: Aggregation queries are declarative via OR's
`x-openregister-aggregations` extension. If performance gates trip during
implementation testing, the cycle MAY implement a pre-aggregated cache per
OR's caching pattern (indexed by due-date bucket + vendor).

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder;
no runtime impact. After implementation (separate cycle), rollback follows
the standard pattern: revert the implementing PR; registers are non-destructive
— AP invoices remain queryable.

## Open Questions

1. **Dunning-workflow stability on OR** — see Risk 1; resolved in `opsx-ff`
   discovery; OR issue filed if needed.
2. **Payee master vs OR contact** — see Risk 2; resolved per ADR-022 review.
3. **Default dunning cadence** — reminder 1 at +14 days, reminder 2 at +30 days,
   formal notice at +45 days, collection escalation at +60 days; all customisable
   per administration; defaults resolved during the implementing cycle's UX review.
4. **AP aging bucket definition** — current invoices (0–30 days overdue), 30–60
   days, 60–90 days, 90+ days; customisable per administration; definitions
   resolved during the implementing cycle.
