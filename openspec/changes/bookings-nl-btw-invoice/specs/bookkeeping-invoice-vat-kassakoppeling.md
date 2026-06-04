# Spec: Invoice VAT + Kassakoppeling Compliance

**Status:** Proposed
**Scope:** shillinq
**Tier:** T3 (operational + NL regulatory)
**Depends on:** `bookkeeping-general-ledger` (T1), `bookkeeping-accounts-receivable-core` (T2)

## Overview

This spec extends Shillinq's invoice capability with per-line VAT (BTW) rate differentiation and kassakoppeling-compliant audit trails. Dutch SMB and government must file VAT monthly/quarterly; each invoice line must carry a rate (21%, 9%, 6%, 0%) and a service-category (product/service/exempt) that gates VAT applicability. On invoice issuance, VAT accrual materialises into the GL control account. An immutable `VATAuditRecord` captures every lifecycle event (issued, paid, written-off, reversed) for Belastingdienst audit.

## Requirements

### REQ-VAT-001: InvoiceLine extended with per-line VAT fields

**Requirement:** Every line item on an invoice (`InvoiceLine`) carries three new fields:

- `vatRate` (enum): one of 21, 9, 6, 0 (percentage). Defaults to administration's standard rate (typically 21).
- `vatAmount` (decimal): computed as `ROUND(lineAmount × vatRate / 100, 2)` using banker's rounding.
- `serviceCategory` (enum): one of "product", "service", "exempt". Default "product". Determines VAT rate applicability (see REQ-VAT-002).

**Rationale:** Dutch VAT law requires invoices to show VAT rate per line. Service/product distinction allows audit-trail filtering by category.

#### Scenario: Standard product invoice line

```
GIVEN an administration in the Netherlands with standard VAT rate of 21%
WHEN a user creates an invoice line for 1 × "Server installation (service)" @ €1000
AND selects serviceCategory="service"
THEN the system auto-suggests vatRate=9 (reduced service rate)
AND computes vatAmount = ROUND(1000 × 9 / 100, 2) = €90.00
AND stores the line with these values
```

#### Scenario: Books with reduced rate

```
GIVEN an administration with book-retailing business
WHEN a user creates an invoice line for 10 × "Beginner's Dutch" @ €15
AND selects serviceCategory="product"
THEN user can manually select vatRate=6 (books)
AND system computes vatAmount = ROUND(150 × 6 / 100, 2) = €9.00
```

### REQ-VAT-002: Service-category validation gates VAT rate

**Requirement:** Before `ARInvoice.issue`, the system validates every line:

- `serviceCategory="product"` permits `vatRate ∈ {0, 6, 21}`.
- `serviceCategory="service"` permits `vatRate ∈ {0, 9, 21}`.
- `serviceCategory="exempt"` permits only `vatRate = 0`.

If validation fails, invoice issuance is blocked with an error message citing the failed line and permitted rates.

**Rationale:** Prevents operator error (e.g., 21% on a tax-exempt service). Exceptions (rare) stored in an administration-configurable override table with audit-trail reason.

#### Scenario: Invalid rate rejected

```
GIVEN an invoice with a line: serviceCategory="service", vatRate=21%
WHEN the user attempts to issue the invoice
THEN the system rejects it with: "Line 2: Service category 'service' does not permit 21% VAT. Check admin settings for service-category overrides."
AND the invoice remains in draft state
```

#### Scenario: Override recorded

```
GIVEN the same invalid line
WHEN the user clicks "Override" and enters reason "Special consulting agreement"
THEN the system records an exception rule in `ServiceCategoryOverride`:
  serviceCategory="service", vatRate=21%, reason="Special consulting agreement"
AND the invoice issues successfully
AND an audit-trail entry is created linking the override to the invoice
```

### REQ-VAT-003: VAT accrual GL posting on invoice issuance

**Requirement:** When `ARInvoice` transitions to state `issued`, a balanced GL transaction materialises automatically:

- **Debit** (e.g., account 1200 AR control): sum of all line amounts (before VAT)
- **Credit** (by VAT rate bucket):
  - 21% VAT lines → GL account `VATPayable21` (configurable, default 2020)
  - 9% VAT lines → GL account `VATPayable9` (configurable, default 2021)
  - 6% VAT lines → GL account `VATPayable6` (configurable, default 2022)
  - 0% VAT lines → GL account `VATPayable0` (configurable, default 2023)

Each bucket sums the `vatAmount` of all lines in that bucket. One GL transaction per invoice, with sub-entries per bucket.

**Rationale:** VAT is a liability accrued on invoice date per Dutch tax law (accrual basis). Materialising at issue time ensures GL balances match invoice register immediately.

#### Scenario: Mixed-rate invoice GL posting

```
GIVEN an invoice with:
  - Line 1: product @ €100 + 21% VAT = €121, vatAmount = €21
  - Line 2: service @ €200 + 9% VAT = €218, vatAmount = €18
WHEN the invoice is issued
THEN the system creates a GL transaction:
  Debit 1200 (AR control): €300.00
  Credit 2020 (VATPayable21): €21.00
  Credit 2021 (VATPayable9): €18.00
  [balanced: 300 = 21 + 18 + 261 remainder to opposite AR line]
```

(Note: This example assumes a simplified 1-to-3 posting; actual posting structure per T1 `JournalEntry` materialisation pattern.)

### REQ-VAT-004: Immutable VATAuditRecord for kassakoppeling compliance

**Requirement:** Every invoice line generates one immutable `VATAuditRecord` per lifecycle event (issued, paid, written-off, reversed). The record is append-only and captures:

| Field | Type | Description |
|-------|------|-------------|
| `invoiceNumber` | string | Invoice identifier (e.g., "2026-001234") |
| `invoiceDate` | date | Invoice issuance date |
| `lineSequence` | integer | Line number within invoice |
| `lineDescription` | string | Item description (immutable copy at time of issue) |
| `lineAmount` | decimal | Line amount before VAT (immutable copy) |
| `vatRate` | integer | VAT rate applied: 21, 9, 6, 0 |
| `vatAmount` | decimal | VAT amount accrued: immutable copy at time of event |
| `serviceCategory` | enum | "product", "service", or "exempt" (immutable copy) |
| `lifecycleEvent` | enum | "issued", "paid", "written_off", "reversed" |
| `eventDate` | datetime | When the lifecycle event occurred |
| `paymentDate` | date | (null until paid; populated on payment) |
| `settlementPeriod` | foreign key | Reference to `TaxPeriod` (month/quarter/year) at time of issue |
| `administrationId` | foreign key | Which business entity this invoice belongs to |

Once created, no field is modified; old records remain for audit even if invoice is later reversed.

**Rationale:** Kassakoppeling requires an immutable, tamper-proof audit trail per cash-register transaction (here, per invoice line). Belastingdienst audits inspect these records; modification after the fact is regulatory non-compliance.

#### Scenario: Complete audit trail for a paid invoice

```
GIVEN an invoice issued 2026-05-15 with a service line (€200 + 9% VAT)
WHEN the invoice is issued
THEN VATAuditRecord #1 is created:
  invoiceNumber: "2026-001234"
  invoiceDate: 2026-05-15
  lineSequence: 1
  lineDescription: "Website hosting - May 2026"
  lineAmount: 200.00
  vatRate: 9
  vatAmount: 18.00
  serviceCategory: "service"
  lifecycleEvent: "issued"
  eventDate: 2026-05-15T14:32:00Z
  paymentDate: null
  settlementPeriod: [May 2026 TaxPeriod]

WHEN payment is received on 2026-05-20
AND the invoice transitions to paid
THEN VATAuditRecord #2 is created (same data, except):
  lifecycleEvent: "paid"
  eventDate: 2026-05-20T09:15:00Z
  paymentDate: 2026-05-20

The first record remains unchanged (immutable).
```

### REQ-VAT-005: Settlement period binding at invoice issuance

**Requirement:** When `ARInvoice.issue` materialises the VAT accrual, the system binds the invoice to a `TaxPeriod` (month/quarter/year) based on:

1. Invoice issuance date
2. Administration's current tax-filing period setting (monthly/quarterly/annual)

The `VATAuditRecord` stores an immutable reference to this period. If the administration later reconfigures to a different filing period, the old invoice's records remain bound to their original period.

**Rationale:** Audit trail must remain internally consistent even after administration settings change. Tax filing follows the period rules in effect at issuance.

#### Scenario: Period binding survives admin reconfiguration

```
GIVEN an administration configured for monthly VAT filing
AND an invoice issued on 2026-05-15
WHEN VAT accrual happens
THEN settlementPeriod = "May 2026 (monthly)"

LATER, WHEN administration reconfigures to quarterly filing (effective 2026-07-01)
AND a new invoice is issued on 2026-07-10
THEN the new invoice's settlementPeriod = "Q3 2026 (quarterly)"
AND the old May invoice's settlementPeriod remains "May 2026"
AND VAT filing logic queries by the bound period (no data migration)
```

### REQ-VAT-006: VAT accrual GL buckets per rate

**Requirement:** The VAT accrual GL posting (REQ-VAT-003) buckets VAT payable by rate into separate GL accounts:

- `VATPayable21` (configurable, default GL account 2020)
- `VATPayable9` (configurable, default GL account 2021)
- `VATPayable6` (configurable, default GL account 2022)
- `VATPayable0` (configurable, default GL account 2023)

Administration can reassign these GL accounts during setup; the system validates that all four are configured and unique.

**Rationale:** Tax filings often segregate VAT by rate for reporting. Separate GL accounts allow drill-down and reconciliation per rate during period-end close.

#### Scenario: Admin configures VAT GL accounts

```
GIVEN a new administration in Shillinq
WHEN the admin goes to Settings > Accounting > Tax Configuration
THEN a form shows:
  VAT Payable 21%: [dropdown] default 2020 (Belastingdienst VAT payable - 21%)
  VAT Payable 9%: [dropdown] default 2021 (Belastingdienst VAT payable - 9%)
  VAT Payable 6%: [dropdown] default 2022 (Belastingdienst VAT payable - 6%)
  VAT Payable 0%: [dropdown] default 2023 (Belastingdienst VAT payable - 0% / exempt)

The admin selects existing GL accounts or confirms defaults.
```

### REQ-VAT-007: Rounding per Dutch fiscal standard

**Requirement:** VAT amounts are computed and stored with the following rounding rules:

- **Per-line VAT amount**: `vatAmount = ROUND(lineAmount × vatRate / 100, 2)` using banker's rounding (round-to-nearest-even).
- **Invoice total VAT**: sum of per-line `vatAmount` values; no invoice-level rounding adjustment.
- **GL posting amounts**: same as per-line sums (no rounding difference between invoice and GL).

**Rationale:** Dutch fiscal standard (Belastingdienst) expects banker's rounding per line; no "rounding adjustment" line. This prevents audit discrepancies.

#### Scenario: Rounding applies correctly

```
GIVEN an invoice line: €33.33 (service, 9% VAT)
WHEN VAT is computed
THEN vatAmount = ROUND(33.33 × 9 / 100, 2) = ROUND(3.0, 2) = €3.00

GIVEN another line: €33.34 (service, 9% VAT)
WHEN VAT is computed
THEN vatAmount = ROUND(33.34 × 9 / 100, 2) = ROUND(3.0006, 2) = €3.00 (banker's rounding: .5 → nearest even)

GIVEN a third line: €33.35 (service, 9% VAT)
WHEN VAT is computed
THEN vatAmount = ROUND(33.35 × 9 / 100, 2) = ROUND(3.0015, 2) = €3.00 (banker's rounding)

Invoice total VAT = €3.00 + €3.00 + €3.00 = €9.00
(no "rounding adjustment" line in GL posting)
```

### REQ-VAT-008: Precondition failure blocks invoice issuance with guidance

**Requirement:** If any precondition fails (service-category validation, VAT GL accounts not configured, settlement period not set, etc.), invoice issuance is blocked. The error message:

1. Names the specific failure (e.g., "Service category 'repair' does not permit 21% VAT").
2. Provides actionable guidance (e.g., "Check admin settings for service-category overrides" or "Configure VAT GL accounts in Tax Configuration").
3. Allows navigation to the relevant admin panel (if GUI) or logs the error for API clients.

**Rationale:** Prevents silent invoice creation with invalid VAT; operator sees clear instruction on how to resolve.

#### Scenario: Admin not configured

```
GIVEN a new administration with no VAT GL accounts configured
WHEN a user attempts to issue an invoice
THEN the system blocks issuance with:
  "Cannot issue invoice: VAT GL accounts not configured. Go to Settings > Accounting > Tax Configuration to assign VAT GL accounts."
AND a hyperlink (if in Web UI) jumps to the tax configuration page
```

### REQ-VAT-009: VAT-by-period aggregation for compliance reporting

**Requirement:** The system provides an aggregation query: `VATByPeriod(administrationId, periodId)` returning:

| Field | Value |
|-------|-------|
| `totalNetAmount` | Sum of all line amounts (before VAT) in the period |
| `totalVAT21` | Sum of all `vatAmount` where `vatRate = 21` |
| `totalVAT9` | Sum of all `vatAmount` where `vatRate = 9` |
| `totalVAT6` | Sum of all `vatAmount` where `vatRate = 6` |
| `totalVAT0` | Sum of all `vatAmount` where `vatRate = 0` |
| `totalGrossAmount` | `totalNetAmount + (totalVAT21 + totalVAT9 + totalVAT6 + totalVAT0)` |
| `invoiceCount` | Number of distinct invoices in the period |
| `recordCount` | Number of `VATAuditRecord` entries (may exceed invoiceCount if invoice is reversed and reissued) |

This aggregation appears in two manifest entries: "VAT by Period" (index) and "VAT Reconciliation" (detail per period).

**Rationale:** Monthly/quarterly VAT filings require these totals broken down by rate. Dashboard enables SMB bookkeeper to cross-check invoice totals before filing.

#### Scenario: May 2026 VAT reconciliation

```
GIVEN an administration with three invoices issued in May 2026:
  - Invoice A: €100 product (21% VAT = €21)
  - Invoice B: €200 service (9% VAT = €18)
  - Invoice C: €300 exempt (0% VAT = €0)

WHEN the bookkeeper views "VAT by Period" > "May 2026"
THEN the detail page shows:
  Period: May 2026 (monthly)
  Total Net Amount: €600.00
  Total VAT 21%: €21.00
  Total VAT 9%: €18.00
  Total VAT 6%: €0.00
  Total VAT 0%: €0.00
  Total Gross Amount: €639.00
  Invoice Count: 3
  Record Count: 3 (no reversals)
```

### REQ-VAT-010: Manifest entries for VAT by Period and Reconciliation

**Requirement:** The manifest declares two new navigation entries:

1. **VAT by Period** (index page): Lists all tax periods for the administration, grouped by filing frequency (monthly/quarterly/annual). Shows summary totals per period (net, VAT by rate, gross). Links to detail page.

2. **VAT Reconciliation** (detail page): Shows full breakdown for a single period: list of invoices, line-by-line VAT audit records, GL account balances for the period, and a "Ready for Filing" checklist.

**Rationale:** Operator-facing navigation for compliance task. Manifest entries follow T1 pattern (index + detail pages).

#### Scenario: Navigation shows May VAT summary

```
GIVEN a bookkeeper in the app
WHEN they click "VAT by Period" in the sidebar
THEN they see:
  | Period | Net | VAT 21% | VAT 9% | VAT 6% | VAT 0% | Gross |
  | May 2026 | €600.00 | €21.00 | €18.00 | €0.00 | €0.00 | €639.00 |
  | June 2026 | €450.00 | €15.00 | €25.50 | €3.00 | €0.00 | €493.50 |

WHEN they click "May 2026"
THEN they see the detail page with line-by-line audit records + GL balances + filing checklist
```

## Dependencies

- **T1 `bookkeeping-general-ledger`**: Requires `JournalEntry` materialization pattern for VAT accrual GL posting.
- **T2 `bookkeeping-accounts-receivable-core`**: Requires `ARInvoice` + `InvoiceLine` entities to extend.
- **T3 `bookkeeping-tax-period` (sibling T3 spec)**: Requires `TaxPeriod` entity for settlement period binding.

## Open Questions

1. **Reverse-charge VAT (B2B intra-EU)**: Should 0% VAT on B2B EU services follow reverse-charge, or is this T5?
2. **Payment-date vs issue-date VAT accrual**: Current spec assumes accrual on issue (accrual basis). Should cash-basis option exist for small traders?
3. **Rounding tie-breaker in banker's rounding**: Confirm .5 rounds to nearest even (e.g., 0.5 → 0, 1.5 → 2) per Dutch fiscal standard.
4. **Service-category exception override workflow**: Should exceptions require approval, or is admin-recorded reason sufficient?
5. **VAT on invoice discounts/credits**: How does VAT apply if a credit note is issued? (E.g., full VAT reversal, or proportional?)

## Acceptance Criteria

- ✓ All REQ-VAT-001 through REQ-VAT-010 scenarios pass manual testing.
- ✓ VAT accrual GL posting matches invoice totals (no rounding discrepancies).
- ✓ `VATAuditRecord` is immutable (read-only after creation; auditable via audit-trail schema).
- ✓ Settlement period binding is correct; old records survive admin reconfigurations.
- ✓ Precondition failures provide clear, actionable error messages.
- ✓ VAT-by-period aggregation matches manual calculation (100% accuracy on sample invoices).
- ✓ Bookkeeper persona (SMB owner) can complete a full VAT filing workflow using the two manifest entries.
