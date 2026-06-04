---
status: draft
---
# BTW One-Stop-Shop (OSS) cross-border EU

## Purpose

The Dutch Belastingdienst, together with all other EU member-state tax authorities, operates the Union One-Stop-Shop (OSS) scheme that lets a Dutch business charge, collect, declare and pay foreign VAT on B2C distance sales of goods and on TBE/electronically-supplied services to consumers in other EU member states without having to register for VAT in every individual destination country. The scheme replaced the old country-by-country distance-sales thresholds on 1 July 2021 with a single EU-wide annual threshold of EUR 10,000 (turnover in goods + TBE services to all other EU member states combined, excluding domestic NL turnover). Below that threshold a Dutch SME charges Dutch BTW as usual and reports it on the regular omzetbelasting return; above it, every B2C sale must carry the consumer-country VAT rate and must be declared on a quarterly OSS return filed through Mijn Belastingdienst Zakelijk. The Belastingdienst receives one consolidated payment in euros from the seller and redistributes the foreign portions to the other member states under Council Regulation (EU) 904/2010.

Today a Dutch MKB bookkeeper using shillinq cannot produce a compliant invoice for a Berlin or Madrid consumer: the system applies the standard Dutch 21% rate regardless of recipient country, and there is no mechanism to flag a sale as falling under OSS, to look up the destination-country VAT rate, to segregate OSS turnover from domestic turnover in the general ledger, or to produce the quarterly OSS aangifte. The bookkeeper either has to manually override the rate per invoice (with high error risk and no audit trail of which rate applied on which date for which country) or accept that shillinq is unusable the moment a webshop or service business crosses the EUR 10,000 threshold. This capability brings shillinq in line with the Belastingdienst OSS workflow, removes the manual workarounds that block adoption by webshops and digital-service providers, and protects users from the substantial penalties that follow from charging the wrong VAT rate or missing the quarterly OSS filing deadline.

This change adds a dedicated OSS pipeline that runs alongside (not inside) the existing domestic BTW pipeline. It introduces destination-country VAT-rate resolution at invoice time, threshold monitoring with a clear opt-in moment when the cumulative B2C-to-EU turnover crosses EUR 10,000, ledger segregation so that OSS turnover and OSS VAT-payable show up in dedicated grootboekrekeningen and never contaminate the regular BTW-aangifte, quarterly OSS aangifte generation in the prescribed XML/CSV upload format with per-country totals, and reconciliation of the consolidated OSS payment to NL Belastingdienst. B2B intra-community supplies remain on the reverse-charge path (covered by `bookkeeping-icp-opgaaf`) and are explicitly excluded from OSS.

## Data Model

A new `OssRegistration` schema captures the seller-side enrolment in the Union scheme: the Belastingdienst-issued OSS-identifier, the date the registration became effective, the home member state (always NL for shillinq tenants), the list of member states the seller actively sells into, and the current registration status (active / voluntarily deregistered / excluded by tax authority / pending). A tenant that has not yet crossed the threshold has no OssRegistration; a tenant that has opted in voluntarily below the threshold gets one with `voluntaryBelowThreshold: true`.

A new `EuVatRate` schema mirrors the European Commission's Taxes in Europe Database (TEDB) and stores per-country, per-period VAT rates broken down by category (standard, reduced 1, reduced 2, super-reduced, parking, zero). Each row carries `countryCode` (ISO 3166-1 alpha-2), `rateCategory`, `ratePercentage`, `validFrom`, `validUntil`, and the CN/CPA code ranges where the reduced rate applies. The table is seeded from TEDB on install and refreshed weekly through a scheduled job; manual edits are forbidden because applying a hand-typed rate to an invoice would defeat the audit-trail purpose.

An `OssThresholdCounter` per tenant tracks the running calendar-year sum of B2C turnover to other EU member states, with breakdown by quarter and by destination country. The counter is recomputed from the journal whenever an invoice is booked, credited, or voided, so it is always a derived view rather than a stored running total that can drift.

The existing `Invoice` schema gains an `ossContext` sub-object populated whenever the destination country is an EU member state other than NL and the customer is a B2C consumer: `destinationCountry`, `appliedVatRate`, `appliedRateCategory`, `tedbRateVersion` (a reference to the EuVatRate row that produced the rate, so an audit trail survives even after TEDB updates the rate), `ossEligible` (boolean), and `ossReportingPeriod` (the YYYY-Qn the invoice will land in). The existing `customerType` field on the counterparty (`b2b` / `b2c`) drives the OSS-vs-ICP fork.

A new `OssReturn` schema represents a single quarterly aangifte: the period, the registration it belongs to, the status (`draft` / `submitted` / `accepted` / `rejected` / `corrected`), the line items grouped by destination country and rate category with taxable amount and VAT amount, the total VAT payable in EUR, the Belastingdienst kenmerk after submission, and the generated XML/CSV payload archived for the 10-year bewaarplicht. Corrections to prior periods are modelled as separate `OssReturn` records of type `correction` referencing the original period; OSS rules forbid in-place amendment after submission.

A new `OssPayment` schema captures the consolidated euro payment to the Belastingdienst, links to the bank transaction that settled it, and stores the per-country distribution that the Belastingdienst confirms back through the OSS portal so reconciliation is closed.

The chart of accounts gains two ledger-account templates per active destination country: `8xxx Omzet OSS {country}` for turnover and `1xxx BTW af te dragen OSS {country}` for payable VAT, auto-created the first time an invoice is booked to that country and never merged with the domestic `1500 Te betalen BTW` account.

## Requirements

### REQ-OSS-001 Destination-country VAT rate resolution at invoice creation

When an invoice is being created for a counterparty whose `country` is an EU member state other than NL and whose `customerType` is `b2c`, the system MUST resolve the applicable VAT rate from the `EuVatRate` table for that country, effective on the invoice date, and the rate category determined by the invoice line's product/service category.

- GIVEN a B2C counterparty in Germany and a product line categorised as `standard`, WHEN the bookkeeper saves the invoice with date 2026-06-15, THEN the line MUST be persisted with `appliedVatRate: 19.00`, `appliedRateCategory: standard`, and `tedbRateVersion` set to the EuVatRate row id that supplied the rate.
- GIVEN a B2C counterparty in Ireland and a line categorised as `reduced_1` (e.g. books), WHEN the invoice is saved with date 2026-06-15, THEN the line MUST be persisted with `appliedVatRate: 9.00` (Irish reduced) and NOT the Dutch 9%.
- GIVEN the `EuVatRate` table has no entry covering the invoice date for the destination country, WHEN the bookkeeper tries to save the invoice, THEN the save MUST be blocked with error `oss.rate.missing` and a hint to run the TEDB refresh job.

### REQ-OSS-002 EUR 10,000 threshold monitoring

The system MUST maintain a per-tenant running counter of calendar-year B2C-to-EU turnover (excluding NL domestic and excluding B2B intra-community supplies) and MUST warn the bookkeeper as the threshold is approached and breached.

- GIVEN the tenant has no OssRegistration and the running counter is EUR 9,200, WHEN an invoice of EUR 900 to a French consumer is being saved, THEN the system MUST show a non-blocking warning that the EUR 10,000 threshold will be crossed within EUR 100 and prompt to start OSS enrolment.
- GIVEN the tenant has no OssRegistration and the running counter is EUR 9,800, WHEN an invoice of EUR 500 to a Spanish consumer is saved, THEN the system MUST block the save with error `oss.threshold.crossed` until the bookkeeper either registers for OSS, marks the registration as `voluntaryBelowThreshold`, or splits the invoice.
- GIVEN the tenant has an active OssRegistration, WHEN the threshold is crossed, THEN no warning or block fires because OSS is already in effect.

### REQ-OSS-003 Ledger segregation for OSS turnover and VAT

OSS turnover and OSS VAT-payable MUST be booked to dedicated per-country grootboekrekeningen that are separate from domestic NL VAT accounts.

- GIVEN a tenant with an active OssRegistration that has never sold to Italy before, WHEN the first invoice to an Italian consumer is posted, THEN the system MUST auto-create accounts `8210 Omzet OSS IT` and `1525 BTW af te dragen OSS IT` from the template and book the journal entry against them.
- GIVEN an OSS invoice posting, WHEN the journal is written, THEN the credit MUST split into the OSS turnover account (excl. VAT) and the OSS VAT account (VAT portion), and the OSS VAT account MUST NOT appear on the regular Dutch BTW-aangifte produced by `bookkeeping-vat-btw-filing`.

### REQ-OSS-004 Quarterly OSS aangifte generation

The system MUST be able to produce a draft `OssReturn` for any closed quarter within 5 seconds, aggregating all OSS-eligible invoices and credit notes from that quarter by destination country and rate category.

- GIVEN Q2 2026 is closed and contains 47 OSS invoices across 6 countries, WHEN the bookkeeper opens the OSS-aangifte screen for 2026-Q2, THEN a draft `OssReturn` MUST be generated showing one line per (country, rateCategory) with taxable base, VAT rate, and VAT amount, and the totals MUST equal the sum of `appliedVatRate * lineAmountExclVat` from the underlying invoices.
- GIVEN a credit note dated 2026-05-10 that refers to an invoice dated 2026-04-03, WHEN the Q2 2026 OSS return is generated, THEN both the original invoice and the credit note MUST appear in the same period and the net VAT MUST reflect the credit.

### REQ-OSS-005 OSS return submission format

The generated payload MUST conform to the Belastingdienst OSS upload specification (XSD `OSS_VAT_Return_v1.x` or the current CSV template), including the seller OSS-identifier, period in YYYY-Qn format, ISO 3166-1 alpha-2 country codes, amounts in euros with two decimals, and the seller's IBAN for refund routing.

- GIVEN a draft `OssReturn` is being finalised, WHEN the bookkeeper clicks "Genereer aangifte-bestand", THEN the system MUST produce a downloadable file that validates against the current Belastingdienst OSS XSD and MUST archive a copy on the OssReturn record.
- GIVEN the OssRegistration is missing or inactive, WHEN finalisation is attempted, THEN the system MUST refuse with `oss.registration.invalid`.

### REQ-OSS-006 Reverse-charge B2B explicitly excluded

B2B intra-community supplies MUST NOT enter the OSS pipeline; they MUST continue to flow through the ICP / reverse-charge path of `bookkeeping-icp-opgaaf`.

- GIVEN a counterparty in Belgium with `customerType: b2b` and a validated BE VAT-ID, WHEN an invoice is saved, THEN the invoice MUST carry 0% VAT with reverse-charge text, MUST NOT increment the OSS threshold counter, and MUST appear on the ICP-opgaaf rather than the OSS return.
- GIVEN a counterparty in Belgium with `customerType: b2b` but no validated VAT-ID, WHEN an invoice is saved, THEN the system MUST treat the sale as B2C for VAT purposes (charge BE VAT via OSS) and warn the bookkeeper that the missing VAT-ID forced the reclassification.

### REQ-OSS-007 Audit trail of applied rate

Every OSS invoice line MUST preserve a complete audit trail of the rate that was applied, even after the TEDB table is updated.

- GIVEN an invoice posted on 2026-06-15 with `appliedVatRate: 19.00` and `tedbRateVersion` pointing to EuVatRate row id 412, WHEN Germany later changes its standard rate to 20% and the TEDB refresh creates row id 538 covering 2027-01-01 onwards, THEN the historic invoice MUST still resolve to the 19% applied at the time and the link to row 412 MUST remain intact.
- GIVEN an OSS return that was filed and accepted by the Belastingdienst, WHEN the bookkeeper later opens any invoice in that period, THEN the displayed VAT rate MUST be the one originally applied, regardless of subsequent TEDB updates.

### REQ-OSS-008 Consolidated payment reconciliation

When the bookkeeper records the consolidated euro payment to the Belastingdienst for an OSS return, the system MUST reconcile that payment against the OssReturn and update its status to `paid`, and MUST allow registration of the per-country distribution confirmation that comes back from the OSS portal.

- GIVEN a submitted OSS return for 2026-Q2 with total VAT payable of EUR 4,732.18, WHEN the bookkeeper matches a bank transaction of exactly EUR 4,732.18 to the Belastingdienst IBAN against the return, THEN the OssReturn status MUST move to `paid` and the bank line MUST be flagged as reconciled.
- GIVEN the Belastingdienst returns a per-country distribution confirmation showing DE EUR 1,802, FR EUR 1,440, IT EUR 1,490.18, WHEN the bookkeeper uploads the confirmation, THEN the OssReturn MUST store the per-country distribution and surface any discrepancy with the originally declared per-country totals.

### REQ-OSS-009 Voluntary opt-in below threshold

A tenant MUST be able to register for OSS voluntarily before crossing EUR 10,000 (Article 369a Directive 2006/112/EC), and the system MUST then route all qualifying sales through OSS regardless of turnover level.

- GIVEN a tenant with running counter EUR 2,000 and no OssRegistration, WHEN the bookkeeper enables OSS in settings and provides an OSS-identifier with effective date 2026-07-01, THEN every B2C-to-EU invoice from 2026-07-01 onwards MUST apply destination-country VAT and feed the OSS return.
- GIVEN voluntary registration is active, WHEN the bookkeeper attempts to disable OSS mid-quarter, THEN the system MUST block the action and explain that voluntary OSS registration binds the seller for at least the current and following two calendar years (Article 369a paragraph 3).

### REQ-OSS-010 Correction-return workflow

The system MUST support OSS corrections by creating a new `OssReturn` of type `correction` linked to the original period, never by amending a submitted return in place.

- GIVEN an accepted Q1 2026 OSS return and a newly discovered EUR 200 invoice that should have been in Q1, WHEN the bookkeeper triggers a correction, THEN a new OssReturn of type `correction` MUST be created with `correctsPeriod: 2026-Q1`, included in the next regular OSS filing window (per the 3-year correction window under Article 61), and the original return MUST remain untouched in the archive.

## Standards & Sources

- Council Directive 2006/112/EC, in particular Articles 358a-369x (the Union scheme).
- Council Implementing Regulation (EU) 282/2011 as amended by 2019/2026 (place-of-supply rules for distance sales of goods and TBE services).
- Council Regulation (EU) 904/2010 on administrative cooperation and combating fraud in the field of VAT (the framework that lets the NL Belastingdienst redistribute OSS payments).
- Belastingdienst publication "One-stop-shopregeling" (mijnbelastingdienst.nl/zakelijk/btw/eu-regeling).
- Belastingdienst XSD for OSS upload (current version on belastingdienst.nl/wps/wcm/connect/.../OSS_VAT_Return).
- European Commission Taxes in Europe Database (TEDB) v3 REST endpoint at ec.europa.eu/taxation_customs/tedb.
- VAT-in-the-Digital-Age (ViDA) package adopted 11 March 2025: monitor for the move of OSS scope expansions to 2027/2028, but no implementation work required in this brief.

## Cross-app integration

- `bookkeeping-vat-btw-filing` owns the regular NL omzetbelasting aangifte. The OSS pipeline must NOT contribute to its rubrieken 3a/3b/4a/4b; the integration point is the chart-of-accounts segregation in REQ-OSS-003 and a hard assertion in the BTW-aangifte builder that excludes the `1525 BTW af te dragen OSS *` family of accounts.
- `bookkeeping-icp-opgaaf` owns the B2B intra-community supply path; REQ-OSS-006 defines the explicit fork at invoice time.
- `bookkeeping-accounts-receivable-core` provides the Invoice and Counterparty schemas that this brief extends with `ossContext` and `customerType`.
- `bookkeeping-chart-of-accounts` provides the template mechanism that this brief uses to auto-create per-country OSS ledger accounts.
- `bookkeeping-bank-connectors` supplies the bank transactions that REQ-OSS-008 reconciles against OSS payments.
- `nldesign` provides the GOV.UK-style warning banner used for the threshold-approaching message in REQ-OSS-002.
- OpenConnector hosts the TEDB rate refresh and the eventual direct submission of the OSS return to the Belastingdienst Digipoort/SBR endpoint when that channel is enabled for OSS (currently manual upload through Mijn Belastingdienst Zakelijk).

## Target users

The primary user is the bookkeeper or accountant of a Dutch SME webshop, SaaS company, or digital-content publisher that sells to consumers across the EU and has either crossed or is about to cross the EUR 10,000 threshold. Secondary users are the SME owner who wants visibility into how much foreign VAT they owe per country, the external accountant who needs to file the quarterly OSS aangifte on behalf of multiple clients, and the Belastingdienst inspector who, in the event of an audit, needs to trace any line on an OSS return back to the originating invoice with the rate that was in force on the invoice date.
