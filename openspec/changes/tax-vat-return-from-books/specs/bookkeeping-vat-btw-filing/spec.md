# bookkeeping-vat-btw-filing Specification (delta)

## Purpose

The VAT return is prepared from what was booked, output and input tax
alike, checked before filing, corrected per fiscal year when that year is
broken, and filed once for a fiscal unity. From shillinq matrix rows
`tax-vat-prepare`, `tax-return-checks`, `tax-broken-year-vat` and
`tax-fiscal-unity`.

## MODIFIED Requirements

### Requirement: REQ-VBTW-004 — The BTW journal SHALL be derived from period-filtered GL aggregations

The VAT return SHALL be prepared from the posted `GLLine` records dated
within the return's period, summed per VAT return box and per amount kind
(base or VAT) exactly as they were booked, for output tax and input tax
alike. Each posted line with a VAT tariff SHALL carry the return box of its
tariff and its amount kind, set when it was posted. Preparing a return SHALL
store the result on the return as one declaration per box and one line per
contributing ledger line, and that stored snapshot SHALL be the only source
the return page, the return file and the correction check read. No path
MUST derive a return from sales or purchase invoices, and VAT MUST NOT be
recalculated from a rate when preparing a return.

The mapping from tariff to box is the tariff's `section` in the
`VatTariff` register; an operator-added tariff carries its own.

#### Scenario: A quarterly return aggregates the period's postings

- GIVEN Bakkerij De Korenbloem posted in Q3 2026 sales of EUR 10,000 at the low tariff with EUR 900 VAT, and purchases of EUR 4,000 at the high tariff with EUR 840 input VAT and EUR 2,000 at the low tariff with EUR 180 input VAT
- WHEN the bookkeeper presses Prepare return for Q3 2026 on the VAT returns page
- THEN box 1b shows EUR 10,000 and EUR 900, box 5b shows EUR 1,020, and the amount payable is EUR -120
- AND the generated return file shows the same figures

#### Scenario: A correction return references the prior period

- GIVEN a Q1 return was filed and a EUR 500 sale at the high tariff was posted into Q1 afterwards
- WHEN the controller creates a correction that points at the filed Q1 return
- THEN the correction reads the ledger as it stands now and shows a delta of EUR 500 base and EUR 105 VAT in box 1a against the stored snapshot

## ADDED Requirements

### Requirement: Checks run on the VAT return and block filing when they fail (REQ-TVRB-001)

A VAT return SHALL have a checks tab that runs, and shows as passed or
failed with a message, at least these checks: every VAT line has a box;
posted VAT equals base times rate per line within one cent; the period
movement of the VAT accounts equals the return totals; no draft or
unposted document is dated in the period; the previous period's return is
filed; reverse-charge VAT appears both owed and deductible. Submitting the
return MUST be refused while a check marked blocking fails, and the
refusal SHALL name the failing check.

#### Scenario: A controller sees why the return cannot be filed yet

- GIVEN a Q3 2026 return and a posted purchase line with input VAT but no tariff
- WHEN the controller opens the checks tab
- THEN the check every VAT line has a box shows failed and names the transaction
- AND pressing Submit is refused with that check's name

#### Scenario: A clean return passes

- GIVEN a Q3 2026 return with all checks passed
- WHEN the controller presses Submit
- THEN the return shows state submitted

### Requirement: Corrections are grouped by a broken fiscal year (REQ-TVRB-002)

For an administration whose fiscal year is not the calendar year, the VAT
corrections page SHALL group returns and corrections by fiscal year, and a
"Check fiscal year" action SHALL run the correction check for every
accepted return in that fiscal year and total the deltas. The threshold
for correcting in a regular return instead of a separate correction SHALL
apply to that fiscal-year total.

#### Scenario: A foundation checks its 2025/2026 fiscal year

- GIVEN Stichting Voorbeeld with a fiscal year from 1 July 2025 to 30 June 2026 and accepted returns for Q3 2025 to Q2 2026
- WHEN the controller runs Check fiscal year 2025/2026 on the corrections page
- THEN the page shows one group for 2025/2026 with the deltas of its four returns and their total
- AND whether the total is above the threshold

### Requirement: A VAT fiscal unity files one return (REQ-TVRB-003)

Shillinq SHALL keep a VAT fiscal unity of a representative administration,
member administrations, the unity's VAT number and its dates. Preparing a
return for the representative administration in a period the unity covers
SHALL include the posted lines of every member and show a subtotal per
member, and the return file SHALL carry the unity's VAT number. Preparing a
return for a member administration in such a period MUST be refused and
name the unity.

#### Scenario: A holding files for two subsidiaries

- GIVEN a VAT fiscal unity of Holding Voorbeeld B.V. with Voorbeeld Bouw B.V. and Voorbeeld Installatie B.V. from 2026-01-01
- WHEN the bookkeeper of the holding prepares the Q3 2026 return
- THEN the return shows subtotals for the holding and both members and one total
- AND preparing a Q3 2026 return in Voorbeeld Bouw B.V. is refused naming the unity
