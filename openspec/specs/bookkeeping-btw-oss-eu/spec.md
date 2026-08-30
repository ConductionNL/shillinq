---
status: done
---

# Spec: bookkeeping-btw-oss-eu

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md` (T1 GL),
`../add-shillinq-accounts-receivable-core/specs/bookkeeping-accounts-receivable-core/spec.md` (Invoice + Counterparty),
`../add-shillinq-vat-btw-filing/specs/bookkeeping-vat-btw-filing/spec.md` (BTW-aangifte excludes OSS accounts)

## Purpose

This specification defines the requirements for bookkeeping btw oss eu in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude pure backend/compliance: BTW OSS EU filing — not browser-testable


### REQ-OSS-001: Destination-country VAT rate resolution at invoice creation

When an invoice is being created for a counterparty whose `country` is an EU member state other than NL and whose `customerType` is `b2c`, the system MUST resolve the applicable VAT rate from the `EuVatRate` table for that country, effective on the invoice date, and the rate category determined by the invoice line's product/service category.

#### Scenario: Standard rate resolved for German B2C line

- **GIVEN** a B2C counterparty in Germany and a product line categorised as `standard`
- **WHEN** the bookkeeper saves the invoice with date 2026-06-15
- **THEN** the line MUST be persisted with `appliedVatRate: 19.00`, `appliedRateCategory: standard`, and `tedbRateVersion` set to the EuVatRate row id that supplied the rate.

#### Scenario: Irish reduced rate resolved instead of Dutch rate

- **GIVEN** a B2C counterparty in Ireland and a line categorised as `reduced1` (e.g. books)
- **WHEN** the invoice is saved with date 2026-06-15
- **THEN** the line MUST be persisted with `appliedVatRate: 9.00` (Irish reduced) and NOT the Dutch 9%.

#### Scenario: Save blocked when no rate covers the date

- **GIVEN** the `EuVatRate` table has no entry covering the invoice date for the destination country
- **WHEN** the bookkeeper tries to save the invoice
- **THEN** the save MUST be blocked with error `oss.rate.missing` and a hint to refresh TEDB or contact support.

### REQ-OSS-002: EUR 10,000 threshold monitoring and opt-in

The system MUST maintain a per-tenant running counter of calendar-year B2C-to-EU turnover (excluding NL domestic and excluding B2B intra-community supplies) and MUST warn the bookkeeper as the threshold is approached and breached.

#### Scenario: Non-blocking warning as threshold approaches

- **GIVEN** the tenant has no OssRegistration and the running counter is EUR 9,200
- **WHEN** an invoice of EUR 900 to a French consumer is being saved
- **THEN** the system MUST show a non-blocking warning that the EUR 10,000 threshold will be crossed within EUR 100 and prompt to start OSS enrolment.

#### Scenario: Save blocked when threshold is crossed

- **GIVEN** the tenant has no OssRegistration and the running counter is EUR 9,800
- **WHEN** an invoice of EUR 500 to a Spanish consumer is saved
- **THEN** the system MUST block the save with error `oss.threshold.crossed` until the bookkeeper either registers for OSS, marks the registration as `voluntaryBelowThreshold`, or splits the invoice.

#### Scenario: No warning when OSS already active

- **GIVEN** the tenant has an active OssRegistration
- **WHEN** the threshold is crossed
- **THEN** no warning or block fires because OSS is already in effect.

### REQ-OSS-003: Ledger segregation for OSS turnover and VAT

OSS turnover and OSS VAT-payable MUST be booked to dedicated per-country grootboekrekeningen that are separate from domestic NL VAT accounts.

#### Scenario: Per-country accounts auto-created on first sale

- **GIVEN** a tenant with an active OssRegistration that has never sold to Italy before
- **WHEN** the first invoice to an Italian consumer is posted
- **THEN** the system MUST auto-create accounts `8210 Omzet OSS IT` and `1525 BTW af te dragen OSS IT` from the template and book the journal entry against them.

#### Scenario: Journal credit splits turnover and VAT

- **GIVEN** an OSS invoice posting
- **WHEN** the journal is written
- **THEN** the credit MUST split into the OSS turnover account (excl. VAT) and the OSS VAT account (VAT portion), and the OSS VAT account MUST NOT appear on the regular Dutch BTW-aangifte produced by `bookkeeping-vat-btw-filing`.

### REQ-OSS-004: Quarterly OSS aangifte generation

The system MUST be able to produce a draft `OssReturn` for any closed quarter within 5 seconds, aggregating all OSS-eligible invoices and credit notes from that quarter by destination country and rate category.

#### Scenario: Draft return aggregates closed quarter

- **GIVEN** Q2 2026 is closed and contains 47 OSS invoices across 6 countries
- **WHEN** the bookkeeper opens the OSS-aangifte screen for 2026-Q2
- **THEN** a draft `OssReturn` MUST be generated showing one line per (country, rateCategory) with taxable base, VAT rate, and VAT amount, and the totals MUST equal the sum of `appliedVatRate * lineAmountExclVat` from the underlying invoices.

#### Scenario: Credit note nets against original in same period

- **GIVEN** a credit note dated 2026-05-10 that refers to an invoice dated 2026-04-03
- **WHEN** the Q2 2026 OSS return is generated
- **THEN** both the original invoice and the credit note MUST appear in the same period and the net VAT MUST reflect the credit.

### REQ-OSS-005: OSS return submission format

The generated payload MUST conform to the Belastingdienst OSS upload specification (XSD `OSS_VAT_Return_v1.x` or the current CSV template), including the seller OSS-identifier, period in YYYY-Qn format, ISO 3166-1 alpha-2 country codes, amounts in euros with two decimals, and the seller's IBAN for refund routing.

#### Scenario: Finalised file validates against XSD

- **GIVEN** a draft `OssReturn` is being finalised
- **WHEN** the bookkeeper clicks "Genereer aangifte-bestand"
- **THEN** the system MUST produce a downloadable file that validates against the current Belastingdienst OSS XSD and MUST archive a copy on the OssReturn record.

#### Scenario: Finalisation refused without valid registration

- **GIVEN** the OssRegistration is missing or inactive
- **WHEN** finalisation is attempted
- **THEN** the system MUST refuse with `oss.registration.invalid`.

### REQ-OSS-006: Reverse-charge B2B explicitly excluded

B2B intra-community supplies MUST NOT enter the OSS pipeline; they MUST continue to flow through the ICP / reverse-charge path of `bookkeeping-icp-opgaaf`.

#### Scenario: Validated B2B routes to ICP not OSS

- **GIVEN** a counterparty in Belgium with `customerType: b2b` and a validated BE VAT-ID
- **WHEN** an invoice is saved
- **THEN** the invoice MUST carry 0% VAT with reverse-charge text, MUST NOT increment the OSS threshold counter, and MUST appear on the ICP-opgaaf rather than the OSS return.

#### Scenario: Missing VAT-ID forces B2C reclassification

- **GIVEN** a counterparty in Belgium with `customerType: b2b` but no validated VAT-ID
- **WHEN** an invoice is saved
- **THEN** the system MUST treat the sale as B2C for VAT purposes (charge BE VAT via OSS) and warn the bookkeeper that the missing VAT-ID forced the reclassification.

### REQ-OSS-007: Audit trail of applied rate

Every OSS invoice line MUST preserve a complete audit trail of the rate that was applied, even after the TEDB table is updated.

#### Scenario: Historic rate survives TEDB rate change

- **GIVEN** an invoice posted on 2026-06-15 with `appliedVatRate: 19.00` and `tedbRateVersion` pointing to EuVatRate row id 412
- **WHEN** Germany later changes its standard rate to 20% and the TEDB refresh creates row id 538 covering 2027-01-01 onwards
- **THEN** the historic invoice MUST still resolve to the 19% applied at the time and the link to row 412 MUST remain intact.

#### Scenario: Filed-period invoice shows originally applied rate

- **GIVEN** an OSS return that was filed and accepted by the Belastingdienst
- **WHEN** the bookkeeper later opens any invoice in that period
- **THEN** the displayed VAT rate MUST be the one originally applied, regardless of subsequent TEDB updates.

### REQ-OSS-008: Consolidated payment reconciliation

When the bookkeeper records the consolidated euro payment to the Belastingdienst for an OSS return, the system MUST reconcile that payment against the OssReturn and update its status to `paid`, and MUST allow registration of the per-country distribution confirmation that comes back from the OSS portal.

#### Scenario: Matched payment moves return to paid

- **GIVEN** a submitted OSS return for 2026-Q2 with total VAT payable of EUR 4,732.18
- **WHEN** the bookkeeper matches a bank transaction of exactly EUR 4,732.18 to the Belastingdienst IBAN against the return
- **THEN** the OssReturn status MUST move to `paid` and the bank line MUST be flagged as reconciled.

#### Scenario: Per-country distribution confirmation stored

- **GIVEN** the Belastingdienst returns a per-country distribution confirmation showing DE EUR 1,802, FR EUR 1,440, IT EUR 1,490.18
- **WHEN** the bookkeeper uploads the confirmation
- **THEN** the OssReturn MUST store the per-country distribution and surface any discrepancy with the originally declared per-country totals.

### REQ-OSS-009: Voluntary opt-in below threshold

A tenant MUST be able to register for OSS voluntarily before crossing EUR 10,000 (Article 369a Directive 2006/112/EC), and the system MUST then route all qualifying sales through OSS regardless of turnover level.

#### Scenario: Voluntary opt-in routes sales through OSS

- **GIVEN** a tenant with running counter EUR 2,000 and no OssRegistration
- **WHEN** the bookkeeper enables OSS in settings and provides an OSS-identifier with effective date 2026-07-01
- **THEN** every B2C-to-EU invoice from 2026-07-01 onwards MUST apply destination-country VAT and feed the OSS return.

#### Scenario: Voluntary registration cannot be disabled mid-binding

- **GIVEN** voluntary registration is active
- **WHEN** the bookkeeper attempts to disable OSS mid-quarter
- **THEN** the system MUST block the action and explain that voluntary OSS registration binds the seller for at least the current and following two calendar years (Article 369a paragraph 3).

### REQ-OSS-010: Correction-return workflow

The system MUST support OSS corrections by creating a new `OssReturn` of type `correction` linked to the original period, never by amending a submitted return in place.

#### Scenario: Correction created as new linked return

- **GIVEN** an accepted Q1 2026 OSS return and a newly discovered EUR 200 invoice that should have been in Q1
- **WHEN** the bookkeeper triggers a correction
- **THEN** a new OssReturn of type `correction` MUST be created with `correctsPeriod: 2026-Q1`, included in the next regular OSS filing window (per the 3-year correction window under Article 61), and the original return MUST remain untouched in the archive.

### REQ-OSS-011: TEDB rate lookup and freshness

The system MUST resolve destination-country VAT rates from the `EuVatRate` table, which is seeded from the EU Commission's Taxes in Europe Database (TEDB) v3 on install and kept fresh through periodic refresh.

#### Scenario: Rate resolved from seeded data

- **GIVEN** the TEDB is seeded at application install
- **WHEN** an invoice is created on the same date as the seed
- **THEN** the rate MUST be resolved from the seeded data.

#### Scenario: Stale TEDB warning offers refresh

- **GIVEN** the TEDB was last refreshed on 2026-05-01
- **WHEN** an invoice is created on 2026-05-20 for a country where the rate changed on 2026-05-15
- **THEN** the system MUST warn the bookkeeper that the TEDB may be stale and offer manual refresh.

### REQ-OSS-012: OSS registration schema and status transitions

The `OssRegistration` record MUST capture all required enrolment details and enforce status transitions.

#### Scenario: Active registration enables OSS eligibility

- **GIVEN** an OSS-identifier issued by the Belastingdienst on 2026-07-01
- **WHEN** the bookkeeper saves the registration with status `active` and destination countries [DE, FR, IT]
- **THEN** the registration MUST be stored and all subsequent B2C invoices to those countries MUST be OSS-eligible.

#### Scenario: Deregistration disables eligibility and resets counter

- **GIVEN** a registration with status `active`
- **WHEN** the bookkeeper marks it `deregistered` with an effective date 2026-12-31
- **THEN** all invoices from 2027-01-01 onwards MUST NOT be OSS-eligible and the threshold counter MUST reset.

### REQ-OSS-013: OSS return states and transitions

The `OssReturn` lifecycle MUST enforce valid state transitions and preserve audit trails.

#### Scenario: Draft transitions to submitted

- **GIVEN** an OssReturn in state `draft`
- **WHEN** the bookkeeper submits it
- **THEN** the state MUST transition to `submitted` and the system MUST record the submission timestamp and the Belastingdienst kenmerk.

#### Scenario: Rejected return allows correction

- **GIVEN** an OssReturn in state `submitted`
- **WHEN** the Belastingdienst rejects it
- **THEN** the state MUST transition to `rejected` and the bookkeeper MUST be able to trigger a correction return.

#### Scenario: Accepted return becomes paid on match

- **GIVEN** an OssReturn in state `accepted`
- **WHEN** a payment is recorded and matched
- **THEN** the state MUST transition to `paid`.

### REQ-OSS-014: Invoice ossContext population and validation

Every OSS-eligible invoice MUST have its `ossContext` populated with all required fields at save time.

#### Scenario: ossContext populated at save time

- **GIVEN** an invoice for a B2C counterparty in France with date 2026-06-15
- **WHEN** the invoice is saved
- **THEN** `ossContext.destinationCountry` MUST be "FR", `ossContext.appliedVatRate` MUST be the French standard rate (20%), and `ossContext.ossReportingPeriod` MUST be "2026-Q2".

#### Scenario: ossContext unaffected by TEDB refresh

- **GIVEN** an invoice with `ossContext.appliedVatRate` set
- **WHEN** the TEDB is refreshed and the rate changes
- **THEN** the invoice's `tedbRateVersion` MUST remain unchanged and the invoice MUST still display the originally applied rate.

### REQ-OSS-015: B2C vs B2B routing at invoice time

The system MUST fork invoice processing on the `customerType` field to route B2B to reverse-charge and B2C to OSS.

#### Scenario: B2C routed to OSS

- **GIVEN** a counterparty with `customerType: b2c` in Spain
- **WHEN** an invoice is created
- **THEN** the system MUST attempt OSS-rate resolution and populate `ossContext`.

#### Scenario: Validated B2B routed to reverse-charge

- **GIVEN** a counterparty with `customerType: b2b` in Spain and a validated ES VAT-ID
- **WHEN** an invoice is created
- **THEN** the system MUST NOT populate `ossContext`, MUST apply 0% reverse-charge VAT, and MUST route to ICP-opgaaf (not OSS).

#### Scenario: Invalid B2B VAT-ID falls back to OSS

- **GIVEN** a counterparty with `customerType: b2b` in Spain but `vatValidationStatus: invalid`
- **WHEN** an invoice is created
- **THEN** the system MUST issue a warning, treat the invoice as B2C, populate `ossContext`, and route to OSS.

### REQ-OSS-016: OSS return archive and 10-year retention

The system MUST archive every filed OSS return (XML/CSV payload) and preserve it for the 10-year record retention (bewaarplicht) period.

#### Scenario: Payloads retained for ten years

- **GIVEN** an OssReturn submitted on 2026-06-30
- **WHEN** the filing is completed
- **THEN** the XML and CSV payloads MUST be stored on the OssReturn record and MUST NOT be deleted until 2036-06-30.

#### Scenario: Correction and original retained independently

- **GIVEN** an OssReturn of type `correction` referencing an original return from 2024-Q1
- **WHEN** the correction is filed
- **THEN** both the original and the correction payloads MUST be retained for their respective 10-year periods.

