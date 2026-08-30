# Spec: bookkeeping-icp-opgaaf

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (VAT compliance)
**Depends on:** `../add-shillinq-bookkeeping-foundation/specs/bookkeeping-accounts-receivable-core/spec.md` (Invoice, Counterparty extension),
`../add-shillinq-bookkeeping-foundation/specs/bookkeeping-chart-of-accounts/spec.md` (zero-rated accounts 8190/8195/8196),
`../add-shillinq-bookkeeping-vat/specs/bookkeeping-vat-btw-filing/spec.md` (rubriek 3b read for reconciliation)

## ADDED Requirements

### Requirement: REQ-ICP-001 — VIES validation SHALL be performed at invoice creation time for B2B intra-community supplies

When an invoice is created for a counterparty in another EU member state with `customerType: b2b` and a VAT-ID, and the supply is to be treated as ICP (supplyType `L`, `S`, or `T`), the system MUST call the VIES SOAP/REST endpoint (ec.europa.eu/taxation_customs/vies) and persist a `ViesValidation` record before the invoice can be saved with `icpContext.treatAsIcp: true`. The `ViesValidation` record MUST capture the `requestId` from the VIES response (Belastingdienst audit proof per Implementing Regulation 282/2011 Article 18), the `valid` boolean, the `name` and `address` returned by VIES (if disclosed), and the `validationTimestamp` and `validUntil` (typically `validationTimestamp + 1 day`).

#### Scenario: Successful VIES validation on invoice create

- **GIVEN** a counterparty with `vatId: "BE0123456789"` and `country: "BE"`
- **WHEN** the bookkeeper saves an invoice with `supplyDate: 2026-06-15` and `icpContext.treatAsIcp: true`
- **THEN** the system MUST call VIES, persist a `ViesValidation` with `valid: true` and `requestId` from the response, set the invoice's `icpContext.viesValidationId` to the new validation's UUID, and zero-rate the invoice on the zero-rated GL account for the supply type.

#### Scenario: VIES returns invalid VAT-ID

- **GIVEN** the bookkeeper attempts to save an invoice with `vatId: "BE9999999999"` and `icpContext.treatAsIcp: true`
- **WHEN** VIES returns `valid: false` for the VAT-ID
- **THEN** the save MUST be blocked with error code `icp.vatid.invalid` and a hint to correct the VAT-ID or invoice with NL BTW (non-zero-rated).

#### Scenario: VIES outage with recent valid history

- **GIVEN** VIES is unreachable (HTTP 5xx or SOAP fault `MS_UNAVAILABLE`) at invoice-save time
- **AND** a recent `ViesValidation` record exists for the same VAT-ID with `valid: true` and `validationTimestamp >= today - 30 days`
- **WHEN** the invoice is saved
- **THEN** the system MUST attach the prior `ViesValidation`, set `Counterparty.vatIdValidationStatus: vies_outage`, and create a revalidation task in the job queue for the next scheduled run.

### Requirement: REQ-ICP-002 — Periodicity SHALL auto-switch from quarterly to monthly when cumulative goods supplies exceed EUR 50,000 in any calendar quarter

The system MUST monitor cumulative `IcpSupply` records where `supplyType` is `L` (goods) or `T` (triangulation) per calendar quarter. When the cumulative amount first exceeds EUR 50,000 in a quarter (determined at any point during that quarter), the system MUST create a `PeriodicitySwitch` log entry and switch the filing periodicity to `monthly` effective from the first day of the **following** month, per Article 263 paragraph 1bis of VAT Directive 2006/112/EC and Article 32 Uitvoeringsregeling OB. The switch notification MUST be presented to the bookkeeper immediately.

#### Scenario: Threshold breached mid-Q1 2026

- **GIVEN** current periodicity is `quarterly` and Q1 2026 cumulative goods supplies are EUR 49,800
- **WHEN** a goods invoice for EUR 300 to a Belgian B2B buyer is posted on 2026-03-28 (still in Q1)
- **THEN** the system MUST create a `PeriodicitySwitch` with `status: active`, `switchFrom: quarterly`, `switchTo: monthly`, `triggerDate: 2026-03-28`, `effectiveDate: 2026-04-01` and notify the bookkeeper with a banner: "Maandelijkse aangifte verplicht vanaf 2026-04 wegens overschrijding EUR 50.000 leveringen goederen in 2026-Q1".

#### Scenario: Monthly filings for remainder of year after threshold breach

- **GIVEN** periodicity just switched to monthly at 2026-04-01 following Q1 2026 breach
- **WHEN** the bookkeeper opens the ICP screen in April 2026
- **THEN** the period selector MUST show only monthly options (2026-04, 2026-05, ..., 2026-12), not quarterly; the filings for 2026-Q2, 2026-Q3 MUST NOT be presented as quarter-level aggregations.

#### Scenario: Revert to quarterly after four consecutive quarters below threshold

- **GIVEN** periodicity is monthly and all four quarters of 2025 had goods supplies below EUR 50,000
- **WHEN** the calendar year 2026 begins
- **THEN** the system MUST offer the bookkeeper a confirmation dialog: "Periodicity may revert to quarterly for 2026 based on 2025 thresholds. Confirm to proceed or keep monthly." If confirmed, revert to `quarterly` effective 2026-01-01.

### Requirement: REQ-ICP-003 — IcpSupply records SHALL aggregate by buyerVatId and supplyType, one line per unique combination per period

A draft `IcpOpgaaf` MUST be generated by aggregating all qualifying `IcpSupply` records (where `reportedInOpgaafId` is null or belongs to the target period) grouped by `(buyerVatId, supplyType)`, producing one line per unique combination. Lines MUST be sorted by buyer VAT-ID in ascending order. Debit and credit amounts (from invoices and credit notes respectively) MUST be summed with their sign preserved (negative for credit notes).

#### Scenario: Aggregation of mixed goods and services to same buyer

- **GIVEN** 12 invoices to BE0123456789 with `supplyType: L` (goods) totalling EUR 30,000 and 3 invoices to the same buyer with `supplyType: S` (services) totalling EUR 5,000, all in 2026-Q2
- **WHEN** the Q2 ICP-opgaaf draft is generated
- **THEN** it MUST contain exactly two lines:
  - `buyerVatId: "BE0123456789"`, `supplyType: "L"`, `amountExclVat: 30000.00`
  - `buyerVatId: "BE0123456789"`, `supplyType: "S"`, `amountExclVat: 5000.00`

#### Scenario: Credit note appears in filing period, not original invoice period

- **GIVEN** an invoice in 2026-Q1 to BE0123456789 for EUR 1,000 (goods) and a credit note in 2026-Q2 reversing EUR 500 of the original supply
- **WHEN** the Q2 ICP-opgaaf draft is generated
- **THEN** it MUST show a line for BE0123456789/L with amount EUR -500 (the credit note's time-of-supply per Article 64 paragraph 2); the original invoice's EUR 1,000 appears in the Q1 filing, not Q2.

### Requirement: REQ-ICP-004 — IcpOpgaaf finalization SHALL reconcile total against BTW-aangifte rubriek 3b within EUR 1 tolerance

Before an `IcpOpgaaf` transitions to `finalized` state, the system MUST verify that the filing's total amount equals the corresponding period's BTW-aangifte `rubriek3b` value (both in EUR, two-decimal rounding, half-even). The tolerance is EUR 1 (one euro) to allow for rounding edge cases. If the amounts match within tolerance, finalize succeeds. If they diverge, finalize MUST fail with error code `icp.reconciliation.mismatch` and present a drill-down listing every `IcpSupply` that appears in one return but not the other, showing the buyer VAT-ID, supply type, amount, and the discrepancy reason (missing invoice, wrong period, wrong supply type, etc.).

#### Scenario: Exact match between ICP total and rubriek 3b

- **GIVEN** a draft 2026-Q2 `IcpOpgaaf` with total EUR 87,450.12
- **AND** the 2026-Q2 BTW-aangifte (submitted or draft) shows `rubriek3b: 87,450.12`
- **WHEN** the bookkeeper finalises the ICP-opgaaf
- **THEN** finalization MUST succeed and the status MUST become `finalized`.

#### Scenario: Divergence within EUR 1 tolerance

- **GIVEN** ICP-opgaaf total EUR 87,450.50, rubriek 3b EUR 87,450.00
- **WHEN** the bookkeeper finalises
- **THEN** finalization MUST succeed (EUR 0.50 divergence is within tolerance).

#### Scenario: Divergence exceeds tolerance

- **GIVEN** ICP-opgaaf total EUR 87,450.12, rubriek 3b EUR 87,200.00
- **WHEN** finalization is attempted
- **THEN** it MUST fail with `icp.reconciliation.mismatch`; the drill-down MUST list the EUR 250.12 of supplies in the ICP filing but not reconciled in rubriek 3b, with invoice references.

#### Scenario: BTW-aangifte does not yet exist for the period

- **GIVEN** the bookkeeper attempts to finalize a 2026-Q2 ICP-opgaaf but no 2026-Q2 BTW-aangifte has been created
- **WHEN** finalization is attempted
- **THEN** it MUST fail with error code `icp.btw.missing`.

### Requirement: REQ-ICP-005 — SBR/Digipoort XML generation SHALL conform to `bd-rpt-icp-2026.xsd` (NT18 taxonomy) and be deliverable via existing Digipoort channel

The generated ICP payload MUST be XBRL-instance XML conforming to the Belastingdienst SBR taxonomy entrypoint for ICP (`bd-rpt-icp-2026.xsd`, currently aligned with NT18 on sbr-nl.nl/taxonomieen). The payload MUST include:
- The BSN or RSIN of the filer (from the administration's organization record)
- The period in `gl-cor:periodIdentifier` format (e.g., "2026-Q2" or "2026-06" depending on periodicity)
- One `bd-i:IntracommunautairePrestatieMutatieSpecificatie` element per aggregated line (buyerVatId + supplyType combination)
- Supply-code indicators per XBRL taxonomy (L for goods, S for services, T for triangulation)
- All amounts in EUR to two decimals, half-even rounding

The XML MUST be deliverable through the same Digipoort PreFill / Aanleveren channel already configured for BTW-aangifte submission (no separate Digipoort credentials or certificate required; reuse existing PKIoverheid setup). Upon finalization, the XBRL instance MUST be validated against the current NT18 schema; if validation fails, finalization MUST be blocked and the validator output MUST be shown verbatim to the bookkeeper.

#### Scenario: Valid XBRL generation for 2-line filing

- **GIVEN** a finalized 2026-Q2 `IcpOpgaaf` with two lines:
  - BE0123456789, L, EUR 30,000.00
  - BE0123456789, S, EUR 5,000.00
- **WHEN** the bookkeeper finalises the opgaaf
- **THEN** an XBRL instance MUST be generated that:
  - Validates against `bd-rpt-icp-2026.xsd`
  - Contains `gl-cor:periodIdentifier: "2026-Q2"`
  - Contains two `bd-i:IntracommunautairePrestatieMutatieSpecificatie` elements (one for L, one for S)
  - Includes `bd-i:supplyCode: "L"` and `bd-i:supplyCode: "S"` respectively
  - Archives the XML payload in `IcpOpgaaf.xmlPayload` (for 10-year bewaarplicht)

#### Scenario: Schema validation error blocks finalization

- **GIVEN** the SBR validator reports a missing mandatory element in the composed XBRL
- **WHEN** finalization is attempted
- **THEN** finalization MUST fail with error code `icp.xbrl.validation_error` and the validator message MUST be displayed to the bookkeeper verbatim (e.g., "Missing element bd-i:buyerVatId in line 2").

### Requirement: REQ-ICP-006 — Triangulation transactions (ABC-levering) SHALL be reported on a separate line from regular goods and services

Triangulation transactions, where the Dutch seller is the B-party under Article 141 of the VAT Directive, MUST be flagged on the invoice with `Invoice.icpContext.supplyType: "T"` (distinct from `L` = goods and `S` = services). The C-party's (end consumer's) VAT-ID MUST be validated against VIES and reported on the ICP-opgaaf, not the A-party's (original supplier's) ID. In the aggregation, triangulation lines MUST never be merged with regular goods or services lines, even if the C-party VAT-ID is the same as another buyer's ID; each unique `(buyerVatId, supplyType)` combination is reported separately.

#### Scenario: Triangulation line reported separately from goods line

- **GIVEN** invoices to the same buyer (FR0123456789):
  - One regular goods invoice (supplyType: L) for EUR 10,000
  - One triangulation invoice (supplyType: T) for EUR 5,000
- **WHEN** the 2026-Q2 ICP-opgaaf draft is generated
- **THEN** it MUST contain two separate lines:
  - FR0123456789, L, EUR 10,000.00
  - FR0123456789, T, EUR 5,000.00
  (never merged into a single EUR 15,000 line)

#### Scenario: Triangulation C-party VAT-ID is validated and reported

- **GIVEN** a triangulation invoice with `triangulation: true`, A-party VAT-ID DE1111111111, B-party (this tenant) NL2222222222, C-party FR3333333333
- **WHEN** the invoice is created
- **THEN** the system MUST validate the C-party VAT-ID (FR3333333333) against VIES, not the A-party's; the `IcpSupply` MUST reference `buyerVatId: "FR3333333333"` and the corresponding `ViesValidation.requestId`.

### Requirement: REQ-ICP-007 — Invoice PDF representation of ICP-treated supplies SHALL include reverse-charge notice and buyer VAT-ID

Every invoice where `icpContext.treatAsIcp: true` MUST carry a legally required reverse-charge notice and the buyer's VAT-ID on the printed/PDF representation, per Article 226 paragraph 11a of VAT Directive 2006/112/EC. The invoice PDF MUST display:
- The **buyer VAT-ID** (in canonical form, country code + national number)
- The **seller NL VAT-ID**
- The text **"BTW verlegd / VAT reverse-charged"** in the invoice language (Dutch for NL invoices, English for exports, etc.)
- The **supply-type indication** (goods, services, or triangulation)

If the buyer VAT-ID is missing at PDF-render time, rendering MUST fail loudly with error code `icp.invoice.vatid.missing` to prevent the seller from issuing a non-compliant invoice.

#### Scenario: PDF render succeeds with complete ICP metadata

- **GIVEN** an invoice with `treatAsIcp: true`, buyer VAT-ID "BE0123456789", seller VAT-ID "NL0987654321", supply type "goods"
- **WHEN** the bookkeeper clicks "Download PDF"
- **THEN** the PDF MUST display:
  - "Buyer VAT-ID: BE0123456789"
  - "Seller VAT-ID: NL0987654321"
  - "BTW verlegd" (Dutch) or "VAT reverse-charged" (English, configurable per invoice language)
  - "Supply type: Goods" or equivalent

#### Scenario: PDF render fails on missing buyer VAT-ID

- **GIVEN** an invoice where `treatAsIcp: true` but the buyer VAT-ID is null or empty
- **WHEN** PDF render is attempted
- **THEN** rendering MUST fail with `icp.invoice.vatid.missing`: "Cannot render ICP invoice without buyer VAT-ID; correct counterparty record or reclassify as non-ICP."

### Requirement: REQ-ICP-008 — Corrections to submitted IcpOpgaaf records SHALL be filed as separate "correction" filings, never in-place amendments

Corrections to a previously submitted `IcpOpgaaf` MUST never be applied in-place (e.g., by editing the original filing's lines). Instead, a new `IcpOpgaaf` MUST be created with `type: "correction"`, `correctsPeriod: "<original period>"` (e.g., "2026-Q1"), and `correctionReason` (text). The corrective filing MUST contain only the net-change lines (positive or negative amounts) that fix the original filing. The original `ViesValidation` records from the time of supply MUST be re-attached (not re-queried from VIES) to preserve good-faith evidence per Implementing Regulation 282/2011 Article 18.

#### Scenario: Correction filing for newly discovered supply in original period

- **GIVEN** a submitted 2026-Q1 `IcpOpgaaf` and the bookkeeper discovers a EUR 1,200 service supply that was omitted and belonged in Q1
- **WHEN** the bookkeeper triggers a correction
- **THEN** a new `IcpOpgaaf` MUST be created with:
  - `type: "correction"`
  - `correctsPeriod: "2026-Q1"`
  - `correctionReason: "Omitted service supply to BE0123456789"`
  - One line: BE0123456789, S, EUR 1,200.00 (positive to add to original)
  - The original `ViesValidation` for BE0123456789 (from the time of supply) MUST be attached

#### Scenario: Correction with buyer VAT-ID since invalidated

- **GIVEN** a submitted 2026-Q1 filing and a corrective supply to a buyer whose VAT-ID is now invalid in VIES
- **WHEN** the correction is generated
- **THEN** the system MUST use the original `ViesValidation` from the time of supply (2026-Q1), not attempt a new VIES call; the correction filing preserves the original good-faith evidence.

### Requirement: REQ-ICP-009 — VIES outage fallback and scheduled revalidation SHALL distinguish transient outages from definitive rejections

The system MUST distinguish between a definitive VIES rejection (`valid: false`) and a transient VIES outage (HTTP 5xx, SOAP `MS_UNAVAILABLE`). On outage, the system MUST schedule a daily revalidation job that attempts to obtain a definitive answer. The `ViesValidation` record MUST carry an `outage: true` flag for outage cases, allowing the system to fall back to the seller's good-faith defence while still attempting revalidation on schedule.

#### Scenario: Outage detected and fallback applied

- **GIVEN** VIES is unreachable at invoice-save time
- **AND** a recent `ViesValidation` (< 30 days old) with `valid: true` exists for the VAT-ID
- **WHEN** the invoice is saved
- **THEN** the prior validation is attached, `vatIdValidationStatus: vies_outage` is set, and a revalidation task is queued.

#### Scenario: Revalidation succeeds on next job run

- **GIVEN** an invoice was saved on 2026-06-15 with `vatIdValidationStatus: vies_outage`
- **WHEN** the daily revalidation job runs on 2026-06-16 and VIES returns `valid: true`
- **THEN** a new `ViesValidation` MUST be created with the fresh response, the invoice's `vatIdValidationStatus` MUST become `valid`, and the bookkeeper MUST receive an inbox notification.

#### Scenario: Revalidation fails after 14 days

- **GIVEN** the daily revalidation job has failed to obtain a definitive VIES answer for 14 or more consecutive calendar days
- **WHEN** the next revalidation attempt also yields outage
- **THEN** the system MUST escalate with a warning to the bookkeeper: "VAT-ID BE0123456789 has been unconfirmed for 14 days. Manually verify the buyer or reclassify this invoice as non-ICP liable." The invoice remains in `vies_outage` state pending bookkeeper action.

### Requirement: REQ-ICP-010 — Audit-trail export for Belastingdienst inspection SHALL produce a self-contained bundle in under 10 seconds

For any submitted `IcpOpgaaf`, the system MUST be able to produce, within 10 seconds of request, an inspection bundle (ZIP file or equivalent) containing:
- The original XBRL payload (archived in `IcpOpgaaf.xmlPayload`)
- The Belastingdienst kenmerk (reference number from submission confirmation)
- A CSV of all underlying `IcpSupply` records with their VIES request IDs (the `requestId` from each supply's `ViesValidation` record)
- PDF files of all source invoices (one per supply, retrieved from docudesk via the invoice's `sourceDocumentUri`)
- A manifest listing the bundle contents and creation date

#### Scenario: Export bundle for inspected filing

- **GIVEN** an accepted 2026-Q2 `IcpOpgaaf` with 47 underlying supplies
- **WHEN** the bookkeeper clicks "Exporteer voor inspectie"
- **THEN** within 10 seconds, a ZIP MUST be produced containing:
  - `icp_opgaaf_2026_Q2.xbrl` (the XBRL payload)
  - `kenmerk_2026_Q2_BD123456.txt` (Belastingdienst reference)
  - `supplies.csv` (47 rows with: invoiceRef, buyerVatId, supplyType, amountExclVat, viesRequestId)
  - `invoice_INV-001.pdf`, `invoice_INV-002.pdf`, ..., `invoice_INV-047.pdf` (source invoices)
  - `manifest.txt` (bundle summary, created 2026-05-22 10:30 UTC)

## Standards & Sources

- Council Directive 2006/112/EC, Articles 138 (exemption for intra-community supplies), 141 (triangulation simplification), 226 (invoice content), 262-271 (recapitulative statement).
- Council Implementing Regulation (EU) 282/2011, Article 18 (status of the customer and VAT-ID verification — good-faith defence via timely VIES verification).
- Wet op de omzetbelasting 1968, Article 37a; Uitvoeringsregeling OB 1968, Article 32.
- Belastingdienst Boetebesluit OB 2026, paragraph on opgaaf intracommunautaire prestaties (penalty schedule).
- Logius SBR Nederlands Taxonomie NT18, entrypoint `bd-rpt-icp` and schema `bd-rpt-icp-2026.xsd` (current version on sbr-nl.nl/taxonomieen).
- VIES SOAP service WSDL at ec.europa.eu/taxation_customs/vies/checkVatService.wsdl; REST proxy at ec.europa.eu/taxation_customs/vies/rest-api.
- Implementing Regulation 282/2011, Article 18 (VAT-ID verification status and good-faith defence).
- VAT Directive Article 64 (time of supply and credit-note timing rules).
