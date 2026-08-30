# Proposal: WBSO S&O Administratie

`kind: config` per ADR-032 — financial administration foundation for Shillinq, declaring core schemas for transaction tracking, document management, and compliance reporting.

## Summary

Introduce the **financial administration capability** for Shillinq, establishing the core data models and workflows for Dutch SME bookkeeping. This change declares the foundational registers (`Transaction`, `Document`, `Account`) and document lifecycle management that upstream specs (accounts-payable-receivable, accounts-receivable, tax-filing) depend upon. Per Shillinq's tier roadmap (ADR-001), this tier-1 base enables all financial workflows.

This change conforms to the shared `nextcloud-app` spec for app structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:**
- No upstream specs — this is tier-1 foundation.

## Motivation

Without a unified financial administration data model, SME bookkeeping lives in spreadsheets, disconnected documents, and manual reconciliation. Dutch regulatory compliance (annual financial statements, BTW filing, tax declarations) requires:

- **Transaction tracking** — every financial event logged with audit trail
- **Document management** — invoices, receipts, contracts with version control
- **Account hierarchy** — RGS-style chart of accounts per Dutch standard
- **Compliance reporting** — tax-ready aggregations (turnover, deductions, assets)

This proposal establishes the single source of truth for all downstream tier-2+ specs. Per the parent envelope's design roadmap, the foundation closes the loop end-to-end for basic Dutch SME bookkeeping.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md) for the canonical 5-tier breakdown.

## Affected Projects

- [x] **Project: shillinq** — adds 3 core schemas (`Transaction`, `Document`, `Account`), declares document lifecycle, registers 1 docudesk template for document storage, adds 1 manifest navigation entry behind general bookkeeping
- [ ] **Project: openregister** — no source changes
- [ ] **Project: docudesk** — registers document storage template

## Scope

### In Scope

- One new capability spec (`bookkeeping-financial-administration`) — see the `specs/` folder
- `Account` register (RGS chart-of-accounts):
  - `accountNumber` (string, RGS code e.g. 1000, 4100)
  - `name` (string)
  - `accountType` (enum: assets, liabilities, equity, revenue, expenses)
  - `parentAccountNumber` (string, optional, for hierarchy)
  - `status` (enum: active, blocked, archived)
- `Transaction` register (financial event):
  - `transactionNumber` (string, unique)
  - `transactionType` (enum: invoice, receipt, journal-entry)
  - `transactionDate` (date)
  - `amount` (decimal, EUR)
  - `description` (string)
  - `status` (enum: draft, posted, reversed)
- `Document` register (attached files):
  - `documentType` (enum: invoice, receipt, contract, tax-form)
  - `documentNumber` (string, unique)
  - `documentDate` (date)
  - `status` (enum: draft, filed, archived)
  - `fileReference` (link to docudesk storage)
- Document lifecycle with states: `draft → filed → archived`
- Audit trail on all transactions and documents per ADR-022
- 1 manifest navigation entry (Bookkeeping)

### Out of Scope

- **Implementation code** — spec-only change
- **GL line-item posting** — owned by tier-2 `bookkeeping-general-ledger`
- **Tax filing logic** — owned by tier-2+ `tax-levy-management`, `vat-btw-filing`
- **Period close & consolidation** — owned by tier-3+

## Approach

One delta, adding ADDED Requirements to one brand-new spec (`bookkeeping-financial-administration`). Each requirement is prefixed `REQ-WBSO-*`. RFC 2119 keywords; `#### Scenario:` with GIVEN/WHEN/THEN.

## New Dependencies

None. Consumes existing OpenRegister abstractions.

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 schemas (`Account`, `Transaction`, `Document`); declares document lifecycle; declares audit trail.
- `src/manifest.json` — adds 1 navigation entry (Bookkeeping).
- `lib/Settings/docudesk-templates.json` — registers 1 template for document storage.
- No new PHP services. No new Vue components.

## Cross-Project Dependencies

- **OpenRegister** — consumes `x-openregister-lifecycle`, audit-trail-immutable, relations, RBAC.
- **docudesk** — document storage template.

## Risks

### Risk 1: Account hierarchy depth and performance

**Severity**: Low
**Mitigation**: Parent account lookups use indexed queries; hierarchy depth capped at 5 levels by schema validation.

### Risk 2: Transaction volume and query performance

**Severity**: Medium
**Mitigation**: Per-year sharding via the Administration entity; index on (administrationId, transactionDate, status).

### Risk 3: Document storage quota

**Severity**: Low
**Mitigation**: docudesk enforces per-org quota; policy enforced server-side.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder. Post-implementation rollback follows the standard additive-register pattern.

## Open Questions

None. The spec is self-contained and does not depend on external design choices.
