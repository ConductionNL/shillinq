# bookkeeping-vat-btw-filing Specification (delta)

## Purpose

VAT tariffs are in the register on every install, and every VAT
calculation uses the tariff valid on the document's date. From shillinq
matrix row `tax-vat-calc`.

## ADDED Requirements

### Requirement: The VAT tariffs are seeded and can be extended (REQ-TVRD-001)

On setup and on every repair run, shillinq SHALL load the statutory Dutch
VAT tariffs into the `VatTariff` register from the seed file in the
repository, without duplicating existing records. A tariffs page under
Belastingen SHALL list every tariff with its rate, category, return box and
dates, SHALL let an administrator add a tariff with its own code and dates,
and MUST NOT allow a statutory tariff to be changed or deleted.

#### Scenario: A new install has the five tariffs

- GIVEN a fresh install after the setup wizard
- WHEN an administrator opens the VAT tariffs page
- THEN it lists high 21 percent, low 9 percent, zero 0 percent, exempt and reverse charge, each marked statutory

#### Scenario: An administrator adds a future rate

- GIVEN the VAT tariffs page
- WHEN an administrator adds tariff mid-12 at 12 percent effective from 2027-01-01
- THEN the page lists it beside the statutory tariffs
- AND a repair run leaves it in place

### Requirement: VAT is calculated per line from the tariff valid on the document date (REQ-TVRD-002)

Every VAT calculation SHALL take a tariff code per line and use the rate of
the `VatTariff` with that code whose dates contain the document date. A
line with an unknown or expired tariff code MUST be refused with the code
and the date named; shillinq MUST NOT fall back to a default rate. The
legacy line codes BTW21, BTW9, BTW0 and VERLEGD SHALL resolve to the seeded
high, low, zero and reverse-charge tariffs.

#### Scenario: A time and expense invoice mixes two rates

- GIVEN approved hours of EUR 1,000.00 at tariff high and a book of EUR 50.00 at tariff low, invoiced on 2026-10-05
- WHEN the bookkeeper generates the invoice
- THEN the invoice shows VAT EUR 210.00 on the hours and EUR 4.50 on the book

#### Scenario: A retired rate is refused

- GIVEN a line with a tariff whose effective period ended on 2018-12-31
- WHEN an invoice dated 2026-10-05 is generated with it
- THEN generation is refused with a message naming the tariff and the date
