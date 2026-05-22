---
status: draft
---
# ICP-opgaaf voor intra-community supplies

## Purpose

When a Dutch business sells goods or services to a VAT-registered business in another EU member state, the supply is zero-rated for Dutch VAT under the intra-community supply (ICP, intracommunautaire prestatie) rules. The recipient self-accounts for VAT in their own member state through the reverse-charge mechanism. To keep the system fraud-resistant the Belastingdienst requires the Dutch seller to file a separate ICP-opgaaf (the Dutch equivalent of the EU recapitulative statement / EC Sales List) that lists, per buyer VAT-identification number, the total value of supplies in the reporting period. The data is exchanged across all member states through the VIES network so that each destination tax authority can cross-check whether the local buyer correctly declared the reverse-charge VAT.

The ICP-opgaaf is governed by Articles 262-271 of the VAT Directive (2006/112/EC) and the Dutch implementing rules in Wet OB 1968 Article 37a. Filing is generally quarterly but switches to monthly when intra-community supplies of goods exceed EUR 50,000 in any quarter (the so-called drempel-versnelling). Late, missing, or incorrect filings carry administrative fines up to EUR 5,514 per return (2026 boetekader). Crucially, the Belastingdienst checks the totals on the ICP-opgaaf against rubriek 3b of the regular BTW-aangifte; any mismatch triggers a control letter.

Today shillinq has no ICP capability at all. A bookkeeper handling a Dutch SME that exports to Belgian, German, or French businesses has to maintain a parallel Excel sheet, manually paste totals into the Belastingdienst portal each quarter, and hope that the VAT-IDs they entered on invoices were actually valid on the date of supply. There is no VIES validation of buyer VAT-IDs (so an invalid VAT-ID is silently zero-rated and the seller becomes liable for the missing VAT plus penalty), no automatic switch from quarterly to monthly when the EUR 50,000 threshold is breached, and no reconciliation between the journal entries and the ICP totals.

This change introduces a complete ICP pipeline: VIES-backed VAT-ID validation captured at the moment a supply is invoiced (with the validation timestamp preserved as evidence of good faith), a separate ICP ledger view that aggregates qualifying invoices by buyer VAT-ID and supply type (goods L, services S, triangulation T), automatic period selection between quarterly and monthly based on the goods-supply threshold, SBR-conformant XML generation that matches the Digipoort schema currently used for the BTW-aangifte so a single connector can deliver both, and a reconciliation guard that prevents submission when the ICP total diverges from rubriek 3b of the same period's BTW-aangifte by more than rounding tolerance. The result is an end-to-end pipeline that lets a Dutch SME safely sell cross-border to EU businesses without paper-shuffling and without exposure to ICP penalty risk.

## Data Model

A new `IcpSupply` schema captures each intra-community supply as a first-class object distinct from the invoice line that produced it. Fields are `id`, `invoiceId`, `invoiceLineId`, `supplyDate` (the date that determines the reporting period under Article 64 of the Directive), `buyerVatId` (canonical form: country code + national number with no spaces), `buyerCountry` (ISO 3166-1 alpha-2), `supplyType` (`L` = goods, `S` = services, `T` = triangulation onward-supply), `amountExclVat` (euros, two decimals, rounded half-even), `viesValidationId` (foreign key to the validation event that proved the VAT-ID was active on `supplyDate`), and `reportedInOpgaafId` (nullable, set when the supply is included in a submitted IcpOpgaaf).

A new `ViesValidation` schema records every call made to the VIES SOAP/REST endpoint at ec.europa.eu/taxation_customs/vies: `id`, `vatId`, `validationTimestamp`, `validUntil` (typically `validationTimestamp + 1 day` because VIES does not guarantee future validity), `valid` (boolean), `name` (the name returned by VIES, may be empty for countries that don't disclose), `address` (idem), `requestId` (the VIES consultation number, which is the formal proof of validation for Belastingdienst audit purposes per Implementing Regulation 282/2011 Article 18). VIES outages are recorded with `valid: null` and an explicit `outage: true` flag so the system can fall back to the seller's good-faith defence while still attempting revalidation on a schedule.

A new `IcpOpgaaf` schema represents a single filing: `period` (YYYY-Qn or YYYY-MM), `periodicity` (`quarterly` / `monthly`), `status` (`draft` / `submitted` / `accepted` / `rejected` / `corrected`), `lines` (aggregated by `buyerVatId` and `supplyType` with sum of `amountExclVat`), `totalGoods`, `totalServices`, `totalTriangulation`, `belastingdienstKenmerk`, `submittedAt`, and `xmlPayload` (archived for the 10-year bewaarplicht).

The existing `Counterparty` schema gains `vatId`, `vatIdValidatedAt`, `vatIdValidUntil`, and `vatIdValidationStatus` (`valid` / `invalid` / `unchecked` / `vies_outage`). The existing `Invoice` schema gains an `icpContext` sub-object set whenever the counterparty is B2B in another EU member state with a validated VAT-ID: `treatAsIcp` (boolean), `supplyType`, `viesValidationId`.

A `PeriodicitySwitch` log records every transition between quarterly and monthly filing with the trigger amount, the trigger quarter, and the effective period from which monthly filing kicks in.

The chart of accounts gains `8190 Omzet ICP goederen`, `8195 Omzet ICP diensten`, `8196 Omzet ICP driehoekstransacties`, all zero-rated, and a journal posting helper that splits intra-community turnover into the correct account based on `supplyType`.

## Requirements

### REQ-ICP-001 VIES validation at invoice creation

When an invoice is being created for a B2B counterparty in another EU member state, the system MUST consult VIES for the buyer's VAT-ID and persist a `ViesValidation` record before the invoice can be saved as `treatAsIcp: true`.

- GIVEN a counterparty with `vatId: BE0123456789`, WHEN the bookkeeper saves an invoice with supplyDate 2026-06-15, THEN the system MUST call VIES, persist a `ViesValidation` with `requestId` from the VIES response, set the invoice's `icpContext.viesValidationId`, and zero-rate the invoice.
- GIVEN VIES returns `valid: false` for the supplied VAT-ID, WHEN the bookkeeper attempts to save with `treatAsIcp: true`, THEN the save MUST be blocked with error `icp.vatid.invalid` and a hint to either correct the VAT-ID or invoice with NL BTW.
- GIVEN VIES is unreachable (HTTP 5xx or SOAP fault `MS_UNAVAILABLE`) and a recent valid `ViesValidation` (less than 30 days old) exists for the same VAT-ID, WHEN the invoice is saved, THEN the system MUST attach the prior validation, set `vatIdValidationStatus: vies_outage`, and queue a revalidation job.

### REQ-ICP-002 Periodicity auto-switch on EUR 50,000 goods threshold

The system MUST monitor cumulative intra-community supplies of goods (supplyType `L` and `T`) per calendar quarter and MUST switch the filing periodicity from quarterly to monthly starting the month following the quarter in which the EUR 50,000 threshold is breached, in line with Article 263 paragraph 1bis of the Directive and Article 32 Uitvoeringsregeling OB.

- GIVEN current periodicity is quarterly and Q1 2026 cumulative goods supplies reach EUR 49,800, WHEN a EUR 300 goods invoice to a Belgian B2B buyer is posted on 2026-03-28, THEN the system MUST record a `PeriodicitySwitch` from `quarterly` to `monthly` effective 2026-04-01 and notify the bookkeeper.
- GIVEN periodicity has just switched to monthly, WHEN the bookkeeper opens the ICP screen, THEN the screen MUST show "Maandelijkse aangifte verplicht vanaf 2026-04 wegens overschrijding EUR 50.000 leveringen goederen in 2026-Q1".
- GIVEN periodicity is monthly and four consecutive quarters of goods supplies stay below EUR 50,000, WHEN the next calendar year begins, THEN the system MUST offer to switch back to quarterly with explicit bookkeeper confirmation.

### REQ-ICP-003 Aggregation per buyer VAT-ID and supply type

A draft `IcpOpgaaf` MUST aggregate qualifying `IcpSupply` rows by `buyerVatId` and `supplyType`, producing one line per unique combination, sorted by buyer VAT-ID.

- GIVEN 12 invoices to BE0123456789 (goods) and 3 invoices to BE0123456789 (services) in 2026-Q2, WHEN the Q2 ICP draft is generated, THEN it MUST contain exactly two lines: BE0123456789/L with the sum of the 12 goods invoices and BE0123456789/S with the sum of the 3 service invoices.
- GIVEN a credit note in 2026-Q2 against a 2026-Q1 invoice, WHEN the Q2 ICP draft is generated, THEN the credit MUST appear as a negative line in Q2 (per the time-of-supply rule of Article 64 paragraph 2), not as a correction to Q1.

### REQ-ICP-004 Reconciliation with BTW-aangifte rubriek 3b

Before an `IcpOpgaaf` can be submitted, the system MUST verify that its total equals rubriek 3b of the same period's submitted or draft BTW-aangifte within EUR 1 rounding tolerance.

- GIVEN a draft 2026-Q2 ICP-opgaaf with total EUR 87,450.12 and a submitted 2026-Q2 BTW-aangifte with rubriek 3b EUR 87,450.12, WHEN the bookkeeper clicks "Verzend ICP-opgaaf", THEN submission MUST proceed.
- GIVEN a draft 2026-Q2 ICP-opgaaf with total EUR 87,450.12 and the BTW-aangifte's rubriek 3b shows EUR 87,200.00, WHEN submission is attempted, THEN the system MUST block with error `icp.reconciliation.mismatch` and present a drill-down listing the EUR 250.12 of supplies present in one return but not the other.
- GIVEN the BTW-aangifte for the same period does not yet exist, WHEN ICP submission is attempted, THEN the system MUST block with `icp.btw.missing`.

### REQ-ICP-005 SBR/Digipoort XML generation

The generated ICP payload MUST conform to the Belastingdienst SBR taxonomy entrypoint for ICP (`bd-rpt-icp-2026.xsd`, currently aligned with NT18) and MUST be deliverable through the same Digipoort PreFill / Aanleveren channel used for the BTW-aangifte.

- GIVEN a draft IcpOpgaaf with two buyer lines, WHEN the bookkeeper finalises it, THEN the produced XBRL instance MUST validate against the current NT taxonomy, MUST include the BSN/RSIN of the filer, the period in `gl-cor:periodIdentifier` format, and one `bd-i:IntracommunautairePrestatieMutatieSpecificatie` per line.
- GIVEN the SBR taxonomy validator reports any error, WHEN finalisation is attempted, THEN submission MUST be blocked and the validator output MUST be shown verbatim to the bookkeeper.

### REQ-ICP-006 Triangulation handling

Triangulation transactions (ABC-levering where the Dutch seller is the B-party using the simplification of Article 141) MUST be tagged `supplyType: T` and MUST be reported on a separate line in the ICP-opgaaf, never merged with regular goods supplies.

- GIVEN an invoice flagged as `triangulation: true` with seller NL, intermediate B (this tenant), and consumer C in another member state, WHEN the supply is captured, THEN `IcpSupply.supplyType` MUST be `T` and the C-party VAT-ID MUST be the one validated against VIES, not the original A-party.
- GIVEN a 2026-Q2 ICP draft containing both regular goods supplies (L) and triangulation (T) to the same buyer VAT-ID, WHEN the draft is generated, THEN it MUST emit two separate lines: one with supply-code `L`, one with supply-code `T`.

### REQ-ICP-007 Invoice mention of reverse-charge and ICP

Every invoice that is treated as ICP MUST carry a legally required reverse-charge notice and the buyer's VAT-ID on the printed/PDF representation, per Article 226 paragraph 11a of the VAT Directive.

- GIVEN an invoice with `treatAsIcp: true`, WHEN the PDF is rendered, THEN it MUST display the buyer VAT-ID, the seller NL VAT-ID, the text "BTW verlegd / VAT reverse-charged" in the invoice language, and the supply-type indication (goods or services).
- GIVEN an invoice that the system treats as ICP but where the buyer VAT-ID is missing, WHEN PDF rendering is attempted, THEN the rendering MUST fail loud with `icp.invoice.vatid.missing` to prevent the seller from issuing a non-compliant invoice.

### REQ-ICP-008 Correction workflow

Corrections to a submitted IcpOpgaaf MUST be filed as a separate `IcpOpgaaf` of type `correction` referencing the original period, never as an in-place amendment.

- GIVEN a submitted 2026-Q1 ICP-opgaaf and a newly discovered EUR 1,200 service supply that belonged in Q1, WHEN the bookkeeper triggers a correction, THEN a new `IcpOpgaaf` of type `correction` with `correctsPeriod: 2026-Q1` MUST be created containing only the corrective lines (positive or negative).
- GIVEN a correction is being prepared for a buyer VAT-ID that has since become invalid in VIES, WHEN the correction is generated, THEN the system MUST attach the original `ViesValidation` from the time of supply (preserving the good-faith evidence) rather than calling VIES anew.

### REQ-ICP-009 VIES outage fallback and revalidation

The system MUST distinguish between definitive VIES rejections and transient VIES outages, and MUST schedule revalidation of any `vies_outage` invoices on a daily job until a definitive result is obtained.

- GIVEN an invoice was saved on 2026-06-15 with `vatIdValidationStatus: vies_outage`, WHEN the daily revalidation job runs on 2026-06-16 and VIES returns `valid: true`, THEN the invoice's status MUST flip to `valid`, a new `ViesValidation` MUST be linked, and the bookkeeper MUST receive an inbox notification.
- GIVEN the daily revalidation job has failed to obtain a definitive VIES answer for more than 14 calendar days, WHEN the next attempt also yields outage, THEN the system MUST escalate to the bookkeeper with an actionable warning to manually verify the buyer or to reclassify the invoice as NL-BTW liable.

### REQ-ICP-010 Audit-trail export for Belastingdienst inspection

For any submitted IcpOpgaaf the system MUST be able to produce, within 10 seconds, an inspection bundle containing the XBRL payload, the Belastingdienst kenmerk, the underlying journal lines, the source invoices, and the ViesValidation records that were in force at supply time.

- GIVEN an accepted 2026-Q2 IcpOpgaaf with 47 underlying supplies, WHEN the bookkeeper clicks "Exporteer voor inspectie", THEN a ZIP MUST be produced containing the original XBRL, a CSV of all 47 supplies with their VIES request IDs, and PDFs of all source invoices.

## Standards & Sources

- Council Directive 2006/112/EC, Articles 138 (exemption for intra-community supplies), 141 (triangulation simplification), 226 (invoice content), 262-271 (recapitulative statement).
- Council Implementing Regulation (EU) 282/2011, Article 18 (status of the customer and VAT-ID verification).
- Wet op de omzetbelasting 1968, Article 37a; Uitvoeringsregeling OB 1968, Article 32.
- Belastingdienst Boetebesluit OB 2026, paragraph on opgaaf intracommunautaire prestaties.
- Logius SBR Nederlands Taxonomie NT18, entrypoint `bd-rpt-icp` (current version on sbr-nl.nl/taxonomieen).
- VIES SOAP service WSDL at ec.europa.eu/taxation_customs/vies/checkVatService.wsdl (and REST proxy at ec.europa.eu/taxation_customs/vies/rest-api).
- ViDA-package decisions of 11 March 2025 on the move to near-real-time digital reporting (out of scope for this brief, but the SBR + per-invoice IcpSupply data model is forward-compatible).

## Cross-app integration

- `bookkeeping-vat-btw-filing` owns the rubriek 3b total that REQ-ICP-004 reconciles against; the integration is a read of the BTW-aangifte total for the period plus a write of a reconciliation event back to the BTW-aangifte audit log.
- `bookkeeping-btw-oss-eu` shares the EU-supply decision point with this brief; the fork is `customerType b2b + valid VAT-ID -> ICP path` vs `customerType b2c -> OSS path` (REQ-OSS-006 mirrors this on the OSS side).
- `bookkeeping-accounts-receivable-core` provides Invoice and Counterparty; this brief extends both.
- `bookkeeping-chart-of-accounts` provides the ledger account templates 8190/8195/8196.
- OpenConnector hosts the VIES connector (REQ-ICP-001, REQ-ICP-009) and the Digipoort SBR submission connector (REQ-ICP-005), reusing the same Digipoort credentials and PKIoverheid certificate that the BTW-aangifte connector already uses.
- `nldesign` provides the warning banner pattern for the periodicity-switch notification (REQ-ICP-002).
- OpenRegister hosts the IcpSupply, ViesValidation, IcpOpgaaf, and PeriodicitySwitch schemas; the audit-trail export (REQ-ICP-010) leverages OpenRegister's file-attached-to-object capability to bundle XBRL + CSV + invoice PDFs.

## Target users

Primary user is the bookkeeper of a Dutch SME (industrial supplier, software company, consultancy) that issues B2B invoices to clients in other EU member states. Secondary users are the SME owner who needs assurance that every cross-border invoice is legally compliant and that the company is not silently accruing VAT liability through invalid VAT-IDs, the external accountant who files ICP-opgaven for multiple clients and benefits from the reconciliation guard, and the Belastingdienst inspector who can request the inspection bundle of REQ-ICP-010 during a fiscal audit and trace every reported euro back to a source invoice and a contemporaneous VIES validation.
