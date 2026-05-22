# Proposal: bookkeeping-accounts-receivable-core

`kind: config` per ADR-032 — the centre of mass is declarative schemas (`CustomerMaster`, `ARInvoice`, `DunningRecord`) + `x-openregister-lifecycle` consuming OR dunning-workflow per ADR-022 + aggregations + manifest entries. No PHP dunning table, no PHP billing-service classes are authored (subject to ADR-031 exception: at most one single-method `DunningGuard` if OR's dunning-workflow extension is not yet stable).

## Summary

Introduce the **accounts receivable (core)** capability for Shillinq as one of the T2 compliance + operations capabilities (per `adr-001-bookkeeping-tier-roadmap.md`). This capability carries forward the original Shillinq invoicing scope — customer invoicing was the original product surface, and this spec formalises it under the T2 declarative envelope. The change declares the `CustomerMaster`, `ARInvoice`, and `DunningRecord` registers; the AR lifecycle (`draft → issued → paid` / `overdue` / `disputed` / `written-off`) consuming OR's dunning-workflow per ADR-022; the write-off path; the UBL 2.1 / Peppol BIS 3.0 field shape declared for T4 attachment but NOT computed in T2; credit-limit check as aggregation; AR aging as aggregation; payment matching against bank lines from `bookkeeping-bank-reconciliation`.

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app structure and `ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:** 
- [`bookkeeping-chart-of-accounts`](../bookkeeping-chart-of-accounts/proposal.md) (Account register)
- [`bookkeeping-document-attachment-integration`](../bookkeeping-document-attachment-integration/proposal.md) (invoice PDF attachment)
- [`bookkeeping-bank-reconciliation`](../bookkeeping-bank-reconciliation/proposal.md) (payment matching against bank statement lines)

## Motivation

Customer invoicing was the original Shillinq scope; AR is the operational completion. Per ADR-022, dunning workflow comes from OR's dunning-workflow extension, not from an app-local dunning table; per ADR-031, AR aging is a declarative aggregation, not a custom `ARReportService`.

Market research (tender analysis) identifies customer master + AR invoice + dunning workflow as top-tier customer-asked features alongside AP. This is one of eight T2 capability changes; this proposal scopes only the AR core slice.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-accounts-receivable-core`); declares 3 new registers (`CustomerMaster`, `ARInvoice`, `DunningRecord`) with lifecycles and aggregations; adds 4 manifest navigation entries (Customers, Accounts Receivable, AR Aging, Dunning).
- [ ] Project: openregister — no source changes; consumes existing dunning-workflow (if stable; else ADR-031 exception), `x-openregister-lifecycle`, `x-openregister-aggregations`.
- [ ] Project: docudesk — no source changes; AR invoice PDF attachments referenced by FK URI per `bookkeeping-document-attachment-integration`.

## Scope

### In Scope

- One new capability spec (`bookkeeping-accounts-receivable-core`) — see the `specs/` folder.
- The `CustomerMaster` register with contact details, billing address, credit limit, dunning policy reference, payment terms.
- The `ARInvoice` register with customer FK, invoice number, dates, line items, tax breakdown, payment due, source-document URI per `bookkeeping-document-attachment-integration`, UBL 2.1 / Peppol BIS 3.0 fields declared (NOT computed in T2 — T4 attaches the outbound).
- The AR lifecycle (`draft → issued → paid` plus `overdue` / `disputed` / `written-off`) consuming OR's dunning-workflow per ADR-022.
- The `DunningRecord` register tracking dunning steps (reminder / formal notice / debt-collection escalation) per administration-configurable policy.
- Write-off path: a compensating GL posting created via materialisation with audit-trailed reason.
- Credit-limit check on `ARInvoice.issue` as `x-openregister-aggregations` query (sum of outstanding `ARInvoice` amount per customer < credit limit).
- AR aging declared as `x-openregister-aggregations` query grouping `ARInvoice` by `(customerId, agingBucket)`, excluding `paid` / `written-off`.
- Payment matching: when bank-reconciliation emits a candidate match against an AR invoice, the operator confirms and the AR invoice transitions to `paid`.

### Out of Scope

- Invoice generation workflows (belongs to T3 `bookkeeping-ar-issuing`).
- Multi-currency revaluation (belongs to T4 `treasury-multi-currency`).
- Dunning workflow definition (consumes OR's dunning-workflow extension; workflow authoring is out of scope for app).
- Advanced AR analytics (belongs to T4 `bookkeeping-ar-analytics`).
- Customer portal (belongs to T5 self-service tier).

## Design Approach

- **Seed data:** 3–5 realistic Dutch customer and invoice records in `lib/Settings/shillinq_register.json`.
- **Manifest integration:** 4 new nav entries (Customers, Accounts Receivable list, AR Aging, Dunning log).
- **Dashboard widgets:** KPI cards for outstanding receivables, overdue aging buckets, dunning escalations.
- **No custom PHP services:** all logic declarative via OpenRegister schemas + lifecycle + aggregations.

## Risks & Mitigation

| Risk | Mitigation |
|------|-----------|
| Dunning-workflow extension unstable | ADR-031 exception: implement single `DunningGuard` service if needed; wrap in lifecycle. |
| Bank reconciliation missing | AR aging reports work standalone; payment matching deferred to reconciliation availability. |
| Large AR dataset performance | Index on `customerId` + `status` in register settings; aggregation lazy-evaluation. |

## Success Criteria

- [x] All 5 demand-driven features mapped to AR aging + overdue tracking
- [x] CustomerMaster, ARInvoice, DunningRecord registers declared with schema.org alignment
- [x] AR lifecycle + aggregations integrated into shillinq register
- [x] Seed data includes 3–5 realistic Dutch customers + invoices
- [x] 4 manifest nav entries visible in app UI
- [x] Payment matching via bank reconciliation documented (external dependency)
