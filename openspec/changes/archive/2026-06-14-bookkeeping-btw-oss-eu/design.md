# Design — BTW One-Stop-Shop (OSS) cross-border EU

## Context

Under Council Directive 2006/112/EC (Articles 358a–369x) and Council Implementing Regulation (EU) 282/2011, the Union One-Stop-Shop (OSS) scheme lets a Dutch business charge, collect, declare, and pay foreign VAT on B2C distance sales of goods and tax-exempt electronically-supplied services to consumers in other EU member states without registering for VAT in every destination country.

The scheme works as follows:

1. A Dutch business crosses the EUR 10,000 annual threshold of B2C sales to other EU member states (or voluntarily registers below the threshold per Article 369a).
2. The business then enrols with the Dutch Belastingdienst and receives an OSS-identifier.
3. Every qualifying B2C sale is charged at the consumer's member-state VAT rate (resolved from the EU Commission's TEDB).
4. VAT is collected and declared quarterly in a consolidated aangifte (one return covering all destination countries).
5. A single euro payment is made to the NL Belastingdienst; the Belastingdienst redistributes the foreign portions to the other member states under Council Regulation (EU) 904/2010.

The change is **spec-only**. Implementation lands later through `opsx-apply` and the standard Hydra pipeline; this doc explains *why* the shape is what it is.

## Goals

- Express the entire OSS-EU surface as **declarative metadata** — schemas + ledger templates + manifest entries — per ADR-031.
- Make the spec a **compliant-bookkeeper readable contract** — Dutch SMB OSS flow recognisable end-to-end (threshold monitoring, registration, destination-country rate resolution, quarterly filing, payment reconciliation).
- Segregate OSS turnover and OSS VAT-payable into **dedicated per-country ledger accounts** so they never contaminate the regular NL BTW-aangifte.
- Preserve **audit trail of applied VAT rates** even after TEDB updates, so historic invoices can be traced back to the rate in force on the invoice date.
- Provide **threshold monitoring** with opt-in below EUR 10,000 per Article 369a.
- Enable **quarterly OSS aangifte generation** in the prescribed Belastingdienst XSD/CSV format.
- Enforce **explicit B2B exclusion** — reverse-charge (ICP) path remains separate.

## Non-Goals

- No PHP OSS service, no `OssFilingService.php`.
- No direct submission to Belastingdienst Digipoort/SBR — manual upload through Mijn Belastingdienst Zakelijk (future OpenConnector integration).
- No multi-currency handling — all OSS amounts are in EUR.
- No VAT exemption workflows — separate capability.

## Decisions

### D1 — OSS is a parallel sub-ledger pipeline that segregates by destination country

Symmetric to the domestic BTW pipeline: OSS turnover (8xxx accounts) and OSS VAT-payable (1xxx accounts) are booked to **per-country dedicated accounts** that are never merged with domestic NL accounts. This segregation is enforced at the **chart-of-accounts level** — the first invoice to a destination country triggers auto-creation of `8xxx Omzet OSS {country}` and `1xxx BTW af te dragen OSS {country}` accounts from templates. The regular BTW-aangifte builder has a **hard assertion** excluding the `1525 BTW af te dragen OSS *` family from rubrieken 3a/3b/4a/4b.

### D2 — VAT-rate resolution happens at invoice-creation time from TEDB

When an invoice is being created for a B2C counterparty in an EU member state other than NL, the system **resolves the applicable VAT rate** from the `EuVatRate` table effective on the invoice date, using the invoice line's product/service category (standard, reduced 1, reduced 2, super-reduced, zero) as the key. The resolved rate is persisted in `ossContext` along with a **reference to the EuVatRate row** (tedbRateVersion), creating an audit trail that survives subsequent TEDB updates. If no rate is found for the invoice date and destination country, the save is **blocked** with error `oss.rate.missing`.

### D3 — Threshold monitoring gates registration; voluntary opt-in binds for 3 years

A tenant with no `OssRegistration` maintains a running `OssThresholdCounter` of B2C-to-EU turnover. When the counter approaches EUR 10,000, a **warning** fires. When crossed, the save is **blocked** until the bookkeeper either:
- Registers for OSS (involuntary at threshold breach),
- Marks the registration as `voluntaryBelowThreshold` (can happen before threshold), or
- Splits the invoice.

Once **voluntary registration** is active (below or at threshold), the bookkeeper cannot disable OSS mid-quarter; the system blocks the action and explains the 3-year lock-in per Article 369a paragraph 3.

### D4 — OssReturn models quarterly aangifte as a separate record per filing period

An `OssReturn` represents a single quarterly aangifte: the period (YYYY-Qn), the `OssRegistration` it belongs to, the status (`draft` / `submitted` / `accepted` / `rejected` / `corrected`), line items grouped by destination country and rate category, total VAT payable, the Belastingdienst kenmerk after submission, and the archived XML/CSV payload (bewaarplicht 10 years).

**Corrections** are modelled as separate `OssReturn` records of type `correction` linked to the original period (e.g., `correctsPeriod: 2026-Q1`), never as in-place amendments. This preserves the integrity of the filed return and complies with Article 61 Directive 2006/112/EC.

### D5 — Consolidated payment reconciliation links bank transaction to OssReturn

When the bookkeeper records a bank payment to the Belastingdienst IBAN, the system matches it against the `OssReturn` total and transitions the return to `paid`. The Belastingdienst later returns a per-country distribution confirmation (via the OSS portal or API); the bookkeeper can register this confirmation, and the `OssReturn` stores the per-country breakdown so reconciliation is closed and any discrepancies are surfaced.

### D6 — B2B intra-community supplies explicitly route to reverse-charge, never OSS

On invoice creation, the fork happens at `customerType` (`b2b` / `b2c`):
- **B2C**: OSS eligibility check (destination country EU non-NL?). If yes, resolve destination-country VAT rate; populate `ossContext`; increment `OssThresholdCounter`.
- **B2B with valid VAT-ID**: Reverse-charge path (0% VAT, route to `bookkeeping-icp-opgaaf`).
- **B2B without validated VAT-ID**: Treat as B2C, charge destination-country VAT via OSS, warn the bookkeeper that the missing VAT-ID forced the reclassification.

The `bookkeeping-icp-opgaaf` spec owns the B2B reverse-charge path and is out of scope here.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Invoice + destination country + customer type | T2 `bookkeeping-accounts-receivable-core` (Invoice, Counterparty) | Extend Invoice.ossContext; fork on Counterparty.customerType at invoice creation |
| VAT-rate resolution + lookup | EU TEDB public database | Seed `EuVatRate` from TEDB v3 REST endpoint on install; manual refresh capability; future weekly refresh via OpenConnector |
| Threshold monitoring aggregation | OR `x-openregister-aggregations` | `OssThresholdCounter` computed from journal; precondition guard on invoice save |
| Ledger segregation by country | T1 chart-of-accounts + templates | Auto-create per-country accounts `8xxx Omzet OSS {country}` + `1xxx BTW af te dragen OSS {country}` on first invoice to each country |
| Materialised GL posting (on invoice issue) | T1 `JournalEntry` materialisation pattern | Same balanced-posting pattern as Invoice.issue in `bookkeeping-accounts-receivable-core` |
| Quarterly aangifte generation | Belastingdienst OSS XSD / CSV template | `OssReturn` GROUP BY (destinationCountry, rateCategory); generate XSD/CSV from template |
| Payment reconciliation | T2 `bookkeeping-bank-reconciliation` | Bank transaction match against OssReturn.totalVatPayable; operator confirms; lifecycle transition to `paid` |
| Audit trail (rate applied) | T1 `bookkeeping-audit-trail` pattern | Automatic on Invoice.create; tedbRateVersion preserves the EuVatRate row id that supplied the rate |
| Manifest navigation | T1 manifest pattern | 2 entries (OSS Registration, OSS Returns) + their pages |
| B2B reverse-charge exclusion | T2 `bookkeeping-icp-opgaaf` | Explicit fork on Counterparty.customerType; B2B with valid VAT-ID routes to ICP, not OSS |

## Schema Design

### OssRegistration

Captures the seller-side enrolment in the OSS scheme:
- **ossIdentifier** (string, required): Belastingdienst-issued OSS identifier (e.g., "DE123456789", "NL123456789.01")
- **effectiveDate** (date, required): Date the registration became effective
- **homeMemberState** (string, required): Always "NL" for Shillinq tenants
- **destinationCountries** (array of strings, required): ISO 3166-1 alpha-2 codes (e.g., ["DE", "FR", "IT"])
- **registrationStatus** (enum, required): "active" / "voluntaryBelowThreshold" / "deregistered" / "excluded" / "pending"
- **voluntaryBelowThreshold** (boolean, optional): true if opted in before EUR 10,000
- **lockInPeriodEndDate** (date, optional): 3-year lock-in end date (Article 369a) if voluntaryBelowThreshold
- **administrationId** (string, required): FK to Administration

### EuVatRate

Mirrors the European Commission's TEDB:
- **countryCode** (string, required): ISO 3166-1 alpha-2 (e.g., "DE")
- **rateCategory** (enum, required): "standard" / "reduced1" / "reduced2" / "superReduced" / "parking" / "zero"
- **ratePercentage** (number, required): VAT rate as decimal (e.g., 19.00, 9.00)
- **validFrom** (date, required): Rate effective date
- **validUntil** (date, optional): Rate expiry date; null = still in effect
- **cnCpaCode** (string, optional): CN/CPA code range where reduced rate applies
- **tedbSource** (string, optional): TEDB version / refresh date for traceability
- **administrationId** (string, required): FK to Administration (allows per-admin TEDB variants if needed)

### OssThresholdCounter

Per-tenant running counter (derived view, recomputed from journal):
- **administrationId** (string, required): FK to Administration
- **calendarYear** (integer, required): The fiscal year being tracked
- **totalB2cEuTurnover** (number, required): Running sum of B2C sales to other EU member states (excl. NL, excl. B2B)
- **byQuarter** (object, optional): Breakdown by quarter { "2026-Q1": 2500, "2026-Q2": 1800, ... }
- **byCountry** (object, optional): Breakdown by destination country { "DE": 3200, "FR": 1100, ... }
- **thresholdBreachedDate** (date, optional): Date EUR 10,000 was crossed (if applicable)

### OssReturn (Quarterly Aangifte)

Represents a single quarterly OSS aangifte:
- **periodYear** (integer, required): Fiscal year (e.g., 2026)
- **periodQuarter** (enum, required): "Q1" / "Q2" / "Q3" / "Q4"
- **registrationId** (string, required): FK to OssRegistration
- **type** (enum, required): "regular" / "correction"
- **correctsPeriod** (string, optional): "2026-Q1" format if type is "correction"
- **status** (enum, required): "draft" / "submitted" / "accepted" / "rejected" / "corrected"
- **lineItems** (array of object, required): Per-destination-country, per-rate-category lines
  - Each line: { countryCode, rateCategory, taxableBase, vatRate, vatAmount }
- **totalTaxableBase** (number, required): Sum of taxable bases
- **totalVatAmount** (number, required): Sum of VAT amounts (in EUR)
- **belastingdienstKenmerk** (string, optional): Confirmation reference after submission
- **xmlPayload** (string, optional): Archived XSD-compliant XML for bewaarplicht
- **csvPayload** (string, optional): Archived CSV export for bewaarplicht
- **administrationId** (string, required): FK to Administration

### OssPayment

Consolidated payment to the Belastingdienst:
- **ossReturnId** (string, required): FK to OssReturn
- **paymentDate** (date, required): Date payment was made
- **bankTransactionId** (string, optional): FK to bank transaction (for reconciliation)
- **amount** (number, required): Payment amount in EUR
- **ibanFrom** (string, required): Company IBAN
- **ibanTo** (string, required): Belastingdienst IBAN
- **perCountryDistribution** (object, optional): Confirmation from Belastingdienst { "DE": 1802, "FR": 1440, ... }
- **reconciliationStatus** (enum, required): "pending" / "reconciled" / "discrepancy"
- **administrationId** (string, required): FK to Administration

### Invoice Extension (ossContext)

Populated when destination country is EU non-NL and customer is B2C:
- **destinationCountry** (string, required): ISO 3166-1 alpha-2
- **appliedVatRate** (number, required): The VAT rate that was applied (e.g., 19.00)
- **appliedRateCategory** (enum, required): standard / reduced1 / reduced2 / superReduced / zero
- **tedbRateVersion** (string, required): FK to EuVatRate.id (audit trail)
- **ossEligible** (boolean, required): true if this invoice counts toward OSS threshold
- **ossReportingPeriod** (string, required): "2026-Q2" format (for aangifte generation)

## Ledger Account Templates

Auto-created per destination country on first OSS invoice:

- **8xxx Omzet OSS {country}** (e.g., "8210 Omzet OSS IT"): Revenue account for turnover to the destination country
- **1xxx BTW af te dragen OSS {country}** (e.g., "1525 BTW af te dragen OSS IT"): Liability account for VAT payable to the destination country

The chart-of-accounts builder has logic to auto-create these templates if they do not already exist. They are never merged with the domestic `8100 Omzet` or `1500 Te betalen BTW` accounts.

## Data Flow

1. **Threshold Monitoring**: Journal entries for B2C sales to EU non-NL members increment `OssThresholdCounter`. As EUR 10,000 is approached, a warning fires. When crossed, invoice save is blocked unless the tenant is already OSS-registered or marks `voluntaryBelowThreshold`.

2. **Invoice Creation**: If destination country is EU non-NL and customer is B2C:
   - System resolves VAT rate from `EuVatRate` table (effective on invoice date, matching rate category).
   - Populates `Invoice.ossContext` with rate, rate category, tedbRateVersion, ossReportingPeriod.
   - Materialises balanced GL posting to the per-country OSS accounts (8xxx debit, 1xxx credit).
   - If OSS-registered, increments `OssThresholdCounter`.

3. **Quarterly Aangifte Generation**: For a closed quarter, the system aggregates all OSS-eligible invoices and credit notes by destination country and rate category, generates an `OssReturn` (status: `draft`), computes totals, and produces the XSD/CSV payload.

4. **Filing & Submission**: Bookkeeper reviews the draft return, corrects if needed, and submits it (status: `submitted`). The Belastingdienst acknowledges with a kenmerk.

5. **Payment Reconciliation**: Bookkeeper records the bank payment; system matches it to the `OssReturn` and transitions to `paid`. Belastingdienst returns per-country distribution confirmation, which the bookkeeper registers.

6. **Corrections**: If an invoice is discovered post-filing, a correction `OssReturn` is created with `correctsPeriod` reference, filed separately, and the original return remains intact.
