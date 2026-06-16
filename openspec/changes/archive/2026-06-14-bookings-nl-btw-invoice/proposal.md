# Proposal: bookings-nl-btw-invoice

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`InvoiceLine` with `vatRate`, `vatAmount` per line and service-vs-product differentiation) + kassakoppeling-compatible audit trail per ADR-022 + manifest entries for VAT reporting.

## Summary

Introduce the **invoice VAT (BTW) + kassakoppeling shape** capability for Shillinq as one of the T3 operational + NL regulatory capabilities (per `adr-001-bookkeeping-tier-roadmap.md`). This capability **enables Dutch fiscal compliance for service and product invoicing** with:

- Per-line VAT rate differentiation (21% standard, 9% reduced for services, 6% books/media, 0% exempt)
- Kassakoppeling-friendly audit trail capturing invoice issue, tax rates, and payment state per item
- VAT-by-service vs VAT-by-product line segregation (Dutch best practice)
- Automated VAT accrual to GL control account on invoice issuance
- VAT settlement tracking linked to tax period (maand/kwartaal)

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app structure.

**Depends on:** [`add-shillinq-bookkeeping-foundation`](../add-shillinq-bookkeeping-foundation/proposal.md) (GL account posting), [`add-shillinq-accounts-receivable-core`](../add-shillinq-accounts-receivable-core/proposal.md) (invoice entity structure).

## Motivation

Dutch SMB and government entities must file VAT (BTW) monthly or quarterly per Belastingdienst rules. The original Shillinq invoicing scope did not differentiate service vs product VAT rates. Competitors (14/21 in market intelligence) include VAT rate differentiation + kassakoppeling audit trail as baseline.

Per ADR-022, kassakoppeling compliance is achieved via audit-trail schemas + immutable record shape, not via a separate compliance service. Per ADR-031, VAT accrual is a lifecycle-materialised GL posting, not a PHP VAT service.

This is one of ten T3 capability changes; this proposal scopes only the invoice-level VAT + kassakoppeling shape.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-invoice-vat-kassakoppeling`); extends `InvoiceLine` with `vatRate` + `vatAmount` + `serviceCategory` fields; declares `VATAuditRecord` (kassakoppeling compliance); adds 2 manifest entries (VAT by Period, VAT Reconciliation).
- [ ] Project: openregister — no source changes; consumes `x-openregister-lifecycle` for VAT accrual materialisation.
- [ ] Project: belastingdienst-gateway — no source changes; external audit trail export for regulatory filing (T3 task, separate change).

## Scope

### In Scope

- One new capability spec (`bookkeeping-invoice-vat-kassakoppeling`) — see the `specs/` folder.
- VAT rate differentiation on `InvoiceLine` with fields: `vatRate` (enum: 21, 9, 6, 0), `vatAmount` (computed), `serviceCategory` (enum: product, service, exempt).
- Kassakoppeling audit trail via `VATAuditRecord` (invoice number, line, rate, amount, issue date, payment date, settlement period) — immutable, capturing full lifecycle.
- Automated VAT accrual: on invoice issuance, materialises balanced GL posting (debit AR control, credit VAT payable GL account) per invoice-total VAT by rate bucket.
- VAT settlement tracking: tie VAT accrual to tax period (monthly / quarterly per administration settings).
- Validation: reject invoices where line-item service category forbids the selected VAT rate (e.g., 21% on exempt-goods service).

### Out of Scope

- **Implementation code** — spec-only change. PHP VAT services, Vue components, controllers, tests are deliberately not in this proposal.
- **VAT filing automation** — T3 follow-on (`bookkeeping-vat-btw-filing`).
- **Multi-rate consolidation** — T4 reporting.
- **Cross-border VAT (MOSS)** — T5.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-invoice-vat-kassakoppeling`** — extends `InvoiceLine` with VAT fields, declares the `VATAuditRecord` immutable audit register, specifies VAT accrual on invoice-issue lifecycle transition, and defines validation + settlement-period binding.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-VAT-*` for traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions (lifecycle materialisation, audit-trail pattern), existing T1 GL posting infra, and invoice entity from T2 AR core.

## Impact

- `lib/Settings/shillinq_register.json` — extends `InvoiceLine` with 3 new fields (`vatRate`, `vatAmount`, `serviceCategory`); declares new `VATAuditRecord` schema (immutable); declares VAT accrual lifecycle action on `ARInvoice.issued`.
- `src/manifest.json` — adds 2 navigation entries (VAT by Period, VAT Reconciliation) + their pages.
- No new PHP services (VAT accrual is lifecycle materialisation per ADR-031).
- No new Vue components.

## Cross-Project Dependencies

- **T1 general ledger** — depends on `add-shillinq-bookkeeping-foundation` for GL account posting infra.
- **T2 AR core** — depends on `add-shillinq-accounts-receivable-core` for invoice entity + lifecycle.

## Risks

- **Kassakoppeling detail level (R1)**: Belastingdienst kassakoppeling guidance evolves; spec captures the audit fields known today (rate, amount, lifecycle event, settlement period). Mitigated by declaring `VATAuditRecord` additively — extra fields can be added without breaking the immutable history.
- **Reverse-charge VAT scope (R2)**: B2B intra-EU reverse-charge (0% VAT on invoice, customer self-accounts) is deferred to T5 (`bookkeeping-btw-oss-eu`). Spec's `vatRate=0 / serviceCategory=exempt` covers domestic exemption but not the reverse-charge flag.
- **Payment-date vs issue-date accrual (R3)**: Current spec accrues VAT on `issue` (accrual basis). Cash-basis administrations (small traders) are out of scope; revisit if a customer requests it. Mitigated by audit-trail immutability — old records survive policy changes.
- **Rounding thresholds (R4)**: Banker's rounding at integer-cent precision (REQ-VAT-007) matches Belastingdienst guidance for SMB; very-high-volume administrations may need an explicit fiscal-advisor review before relying on the per-line aggregation.
- **Existing VAT*Service PHP classes (R5)**: `lib/Service/VATCalculationService.php` and `lib/Service/VATReturnService.php` already exist for OTHER capabilities (`invoice-from-time-and-expense` BillableInvoice + `bookkeeping-vat-btw-filing` periodic returns). This spec does NOT extend them; the new VAT accrual flow is declarative-only per ADR-031. Mitigated by the Task-1 anti-pattern note.
- **GL bucket misconfiguration (R6)**: If `VATGLAccounts` is missing or duplicated, the lifecycle precondition blocks issuance with actionable guidance per REQ-VAT-008 (no silent posting drift).

## Open Questions

1. **Kassakoppeling detail level**: Should `VATAuditRecord` capture payment-method (PIN/cash/bank)? Current scope is date/rate/amount only. Clarify with Belastingdienst gateway design.
2. **Reverse-charge VAT (B2B exempt)**: Is B2B reverse-charge (0% VAT, 0% accrual) in-scope, or T5? Current proposal assumes all B2C domestic; answer determines REQ-VAT-004.
3. **Payment-date VAT accrual**: Should VAT accrue on issue (current) or on payment (cash-basis)? Dutch SMB typically accrues on issue; confirm administration setting.
4. **Rounding thresholds**: Define rounding rule for per-line VAT amounts (round-to-nearest vs round-down vs banker's rounding). Spec settles on banker's rounding (`roundTiesToEven`) at integer-cent precision per REQ-VAT-007.
5. **Override approval workflow**: REQ-VAT-002bis records `createdBy` for traceability; should overrides above a revenue threshold also require dual approval (creator + reviewer)? Deferred to a follow-up requirement.

## Rollback

- Remove `VATAuditRecord`, `ServiceCategoryOverride`, and `VATGLAccounts` schemas from `lib/Settings/shillinq_register.json`.
- Revert the `ARInvoice.lines[]` items extension (remove `vatRate`, `vatAmount`, `serviceCategory` fields).
- Remove VAT accrual lifecycle action + service-category precondition from the `ARInvoice.issue` transition.
- Remove the 2 manifest entries (`VATByPeriod`, `VATReconciliation`) and their page declarations.
- Remove the seed data file `lib/Data/VAT/nl_vat_rates_2026.json`.
- Revert the additions to `openspec/architecture/adr-000-data-model.md` (InvoiceLine extension + the three new schemas).
- Down direction is non-destructive: existing `VATAuditRecord` rows remain queryable for forensic audit, but no new entries are generated and the aggregation query is removed from the manifest.
