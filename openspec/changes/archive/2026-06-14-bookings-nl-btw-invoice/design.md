# Design — Invoice VAT + Kassakoppeling Shape

## Context

Dutch SMB invoicing must differentiate VAT rates per service/product type and maintain audit trails compatible with Belastingdienst kassakoppeling (cash register linkage) rules. Invoices are the entry point for VAT accrual into the GL. Per ADR-022, audit compliance is achieved via immutable records + declarative lifecycle, not via a separate VAT service. Per ADR-031, VAT GL posting is lifecycle-materialised, not imperative code.

The change is **spec-only**. Implementation lands later through `opsx-apply` and the standard Hydra pipeline; this doc explains *why* the shape is what it is.

## Goals

- Express invoice-level VAT + kassakoppeling audit as **declarative metadata** — extended schema + immutable audit record + lifecycle materialisation — per ADR-031.
- Differentiate VAT rate (21%, 9%, 6%, 0%) at line-item granularity, with service-vs-product categorization per Dutch best practice.
- Materialise VAT accrual automatically on invoice issuance into GL control account per rate bucket.
- Capture kassakoppeling-compliant audit trail (immutable `VATAuditRecord`) for every invoice line through its lifecycle (issued → paid or written-off).
- Make the spec a **SMB bookkeeper-readable contract** — invoice VAT flow recognisable end-to-end (service/product selection → rate assignment → VAT accrual → settlement period tracking).

## Non-Goals

- No PHP VAT service class (`VATService.php`).
- No VAT filing automation — T3 follow-on `bookkeeping-vat-btw-filing`.
- No cross-border VAT (MOSS / B2B reverse-charge) — T5.
- No multi-rate consolidation or reporting dashboards — T4.

## Decisions

### D1 — InvoiceLine carries per-line VAT rate + service-category

Each line item on an invoice specifies:
- `vatRate`: one of 21 (standard), 9 (reduced services), 6 (books/media), 0 (exempt/export)
- `serviceCategory`: enum (product, service, exempt) — gates VAT rate validity
- `vatAmount`: computed from `bankerRound(lineAmount × vatRate / 100)` in integer cents per ADR-022 money rule

Segregation by service/product allows Dutch VAT audit to distinguish product taxability from service exemptions. No aggregate roll-up; each line is atomic.

All monetary fields (`lineAmount`, `vatAmount`) MUST be stored as integer cents. ADR-022 forbids floating-point money; the schema declares both as `integer` with `description` calling out the cent encoding.

### D2 — Kassakoppeling compliance via immutable VATAuditRecord

`VATAuditRecord` is an append-only, immutable schema capturing:
- Invoice reference (number, date)
- Line item (sequence, description, amount)
- VAT rate applied
- VAT amount accrued
- Payment date (null until paid)
- Settlement period (maand/kwartaal/jaar)
- Lifecycle event (issued, paid, written-off, reversed)

Immutability ensures Belastingdienst audits never see a modified record. One record per line per lifecycle event.

### D3 — VAT accrual is a lifecycle-materialised GL posting

On `ARInvoice.issued`, a materialised GL transaction fires (no PHP service):
- Debit `ARInvoice.amount` to customer-facing GL account
- Credit `VATPayable` GL account, bucketed by rate (21%, 9%, 6%, 0%)

One balanced posting per invoice, with line-by-line VAT buckets rolled up. Audit trail captured automatically by GL transaction schema.

### D4 — Service-category validation gates VAT rate

Precondition on `ARInvoice.issued`: for each line, `serviceCategory` must permit `vatRate`. Examples:
- `product` + 21% → allowed
- `service` + 9% → allowed (Dutch reduced rate for services)
- `service` + 21% → rejected (rare; must be explicitly overridden with reason)
- `exempt` + any non-zero rate → rejected

No hardcoded rules; configuration table per administration allows exceptions with audit-trail reason.

### D5 — VAT settlement period binding

`VATAuditRecord` captures settlement period at the time of invoice issue. If administration settings change (e.g., monthly → quarterly filing), old records remain unchanged; only new invoices bind to the new period. This allows historical audit trail to remain immutable across administration reconfigurations.

### D6 — Rounding per Dutch fiscal standard

Per-line VAT amount: banker's rounding (IEEE 754 `roundTiesToEven`) at integer-cent precision. When the fractional remainder is exactly 0.5 cent, the result rounds to the nearest even cent. Invoice-total VAT: sum of per-line VAT amounts with no additional rounding adjustment line. This matches Belastingdienst decimal standard and is consistent with the ADR-022 integer-cent money rule (no float drift).

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Invoice line item structure | T2 `ARInvoice` + `InvoiceLine` | Extend `InvoiceLine` with `vatRate`, `vatAmount`, `serviceCategory` |
| VAT rate enum & validation | None (new) | New schema fields + precondition on issue |
| Kassakoppeling audit trail | OR `x-openregister-audit` (ADR-022) | New immutable `VATAuditRecord` schema; one record per line per event |
| VAT accrual GL posting | T1 `JournalEntry` materialisation pattern (ADR-031) | Lifecycle materialisation on `ARInvoice.issued` |
| Settlement period tracking | T3 `bookkeeping-tax-period` (separate T3 spec) | Foreign key to TaxPeriod; immutable at issue time |
| Service-category validation | None (new) | Precondition logic; configuration table per administration |
| Manifest navigation | T1 manifest pattern | 2 entries (VAT by Period, VAT Reconciliation) + their pages |

**Net new code in implementation cycle**: 1 schema extension (`InvoiceLine`) + 1 immutable schema (`VATAuditRecord`) + 1 lifecycle materialisation (VAT accrual) + 1 precondition (service-category gate) + 2 manifest entry pairs. No PHP service class.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Per-line VAT rate selection | Declarative (schema fields) | Simple data capture |
| Service-category validation | Declarative (precondition predicate) | Pure business rule |
| VAT accrual GL posting | Lifecycle materialisation (T1 pattern) | Deterministic; no procedural logic |
| Kassakoppeling audit trail | Append-only schema (immutable records) | Regulatory compliance; no modification after issue |
| Settlement period binding | Immutable at issue time | Audit trail remains valid even if administration settings change |

No service class authored in this envelope (VAT accrual is lifecycle materialisation per ADR-031).

## Seed Data

Dutch standard VAT rates (read-only, deployed with app):

```json
{
  "name": "NL Standard (21%)",
  "rate": 21,
  "category": "product",
  "validFrom": "2026-01-01"
}
```

```json
{
  "name": "NL Reduced Services (9%)",
  "rate": 9,
  "category": "service",
  "validFrom": "2026-01-01"
}
```

```json
{
  "name": "NL Books/Media (6%)",
  "rate": 6,
  "category": "product",
  "validFrom": "2026-01-01"
}
```

```json
{
  "name": "Exempt / Export (0%)",
  "rate": 0,
  "category": "exempt",
  "validFrom": "2026-01-01"
}
```

Administration-configurable: service-category exceptions (e.g., allow 21% on a specific service with reason).

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| VAT rate changing mid-year (Belastingdienst announcement) | Pin rates by `effectiveFrom` date; new invoices use new rate; old records immutable |
| Settlement period mismatch (monthly vs quarterly filing) | Bind at issue time; allow reconfiguration; generate compliance report per period |
| Per-line VAT rounding vs invoice-total rounding discrepancy | Apply banker's rounding per-line; sum per-line totals; no invoice-level rounding adjustment |
| Service-category exceptions require audit trail | Store exception reason in precondition block; captured by GL audit on invoice post |
| VATAuditRecord grows large with many invoices | Partition by period + year; archive old records per archiefwet retention policy (T3 task) |
| Lifecycle materialisation failure leaves invoice in inconsistent state | Transactional wrapping: issue only succeeds if GL posting succeeds (database-level atomicity) |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched: `InvoiceLine` schema extended with 3 fields (`vatRate`, `vatAmount`, `serviceCategory`); new `VATAuditRecord` schema declared.
2. `src/manifest.json` is patched with 2 new menu entries + their pages (VAT by Period, VAT Reconciliation).
3. VAT accrual lifecycle materialisation declared on `ARInvoice.issued` (lifecycle action block + GL posting template).
4. Seed VAT rate records inserted into `TaxRate` (read-only, 4 standard NL rates).

Down-direction: registers are non-destructive — reverting removes the new fields + schema, nullifying all `VATAuditRecord` entries (immutable records remain for audit, but no new entries generated).

## Implementation Notes

- **GL account mapping (RGS baseline)**: Administration must configure the four `VATPayable*` GL accounts via `VATGLAccounts`. The installer seeds RGS (Referentie Grootboekschema) defaults: account `2020` = Belastingdienst VAT payable 21% (`WBeBoB`), `2021` = 9% (`WBeBoL`), `2022` = 6% (`WBeBoM`, historical), `2023` = 0% / exempt (`WBeBoN`). The admin can override during setup via `Settings > Accounting > Tax Configuration`; the form validates that all four account numbers exist in `Account` for the administration and are unique. Once an invoice is issued, the credit account number used is persisted in the `GLTransaction.lines[]` row — subsequent reconfiguration of `VATGLAccounts` does NOT rewrite historical GL postings.
- **Timezone handling**: All dates (issue, payment, settlement) are computed in the administration's local timezone; the `eventDate` on `VATAuditRecord` is stored as UTC ISO 8601 (`Z`) for audit immutability.
- **Currency**: All amounts (`lineAmount`, `vatAmount`, `totalAmount`) in invoice currency. T2 / T3 scope is EUR only; multi-currency lands in T5.
- **Precondition failure**: Both new preconditions on `ARInvoice.issue` (`vat-service-category-gate` and `vat-gl-accounts-configured`) emit bilingual (nl + en) `messageTemplate` strings with `{{lineSequence}}`, `{{serviceCategory}}`, `{{vatRate}}` interpolation. The GL-accounts precondition additionally carries a `deepLink` to `/index.php/apps/shillinq/settings/tax-configuration` so the UI can jump straight to the admin panel; API clients see the message text only.
- **Rate-bucket reassignment safety**: When a `VATGLAccounts` reassignment happens after invoices have been issued in a period, the historical `VATAuditRecord` rows remain unchanged (immutable per ADR-022). The new bucket configuration applies to subsequent issuances only; the period's `vatByPeriod` aggregation continues to summarise the audit records correctly because the aggregation groups by `(administrationId, settlementPeriod)` and reads the immutable per-line `vatAmount` rather than re-resolving GL accounts.
- **6% rate retention**: The 6% rate listed in `nl_vat_rates_2026.json` is historical (replaced by 9% in 2019). It is retained so that historical invoices and international administrations that still use 6% remain queryable, and the `vatRate` enum stays stable across spec versions. New NL administrations default to 9% for reduced-rate services.
