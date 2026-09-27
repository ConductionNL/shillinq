# invoice-from-time-and-expense Specification (delta)

## Purpose

A user generates a time-and-expense invoice by ticking the unbilled hours and
pass-through expenses the page lists, and a recharged expense carries its
markup and the right VAT treatment. From shillinq matrix rows
`sal-time-to-invoice` and `exp-reinvoice`.

## ADDED Requirements

### Requirement: The generate page lists unbilled hours to tick (REQ-STEB-001)

`BillableInvoiceGenerate` SHALL list the approved, billable hours on the chosen
project in the chosen period that no `draft` or `posted` `BillableInvoice`
references, each with person, date, hours, the rate the chosen rate card
resolves and the amount, and SHALL let the user tick hours instead of typing
ids. The page MUST NOT offer a free-text id field for hours.

#### Scenario: A project manager bills a month of hours

- GIVEN 12.5 approved hours on project Renovatie Kade 12 in October 2026 and rate card Standaard 2026 at EUR 95 an hour
- WHEN the project manager opens the Generate invoice page, chooses customer Woningcorporatie Het Anker, the project and October 2026
- THEN the page lists the hours with a total of EUR 1,187.50
- AND ticking them and choosing Save as draft writes one invoice line for them

#### Scenario: Billed hours are not offered again

- GIVEN the October hours already sit on draft invoice 2026-T-0031
- WHEN the project manager opens the Generate invoice page for the same project and month
- THEN the hours list is empty and says every hour in the period is already on an invoice

#### Scenario: Without an hours source the page says so

- GIVEN no hours source is connected on the instance
- WHEN the project manager opens the Generate invoice page
- THEN the hours list shows that hours are not available because the hours app is not connected, not a total of zero

### Requirement: The generate page lists unbilled pass-through expenses to tick (REQ-STEB-002)

`BillableInvoiceGenerate` SHALL list the pass-through receipts, mileage and per
diem items linked to the chosen customer whose claim is approved or posted and
that no `draft` or `posted` `BillableInvoice` references, each with cost,
markup and amount, and SHALL let the user tick items. Items MAY come from
several claims and one claim's items MAY be billed on different invoices.

#### Scenario: A consultant's travel and parking are recharged

- GIVEN a posted claim of S. de Vries with a pass-through train receipt of EUR 42.80 and a parking receipt of EUR 12.50 for Woningcorporatie Het Anker
- WHEN the project manager chooses that customer on the Generate invoice page
- THEN both receipts are listed with their cost, markup and amount
- AND a reimbursable receipt on the same claim is not listed

### Requirement: A recharged expense line carries its cost, markup and VAT treatment (REQ-STEB-003)

A billed expense item SHALL produce one invoice line whose amount is the cost
plus the locked markup, with a `recharge` record of cost, markup rate, markup
amount and VAT treatment. The line SHALL take the VAT rate of the invoice's main
supply, unless the user marks the item as a disbursement paid in the client's
name, in which case the line MUST be outside the VAT base and carry the
mention "Verschotten, buiten de btw-grondslag".

#### Scenario: A train ticket bought at 9 percent is recharged at 21 percent

- GIVEN the train receipt with a cost of EUR 42.80 excluding VAT, bought at 9 percent VAT, and a 10 percent markup rule, on an invoice whose hours are at 21 percent
- WHEN the invoice is drafted
- THEN the line shows EUR 47.08 at 21 percent VAT, with cost EUR 42.80 and markup EUR 4.28

#### Scenario: A land registry extract is a disbursement

- GIVEN a receipt of EUR 3.70 for a land registry extract, ticked as a disbursement
- WHEN the invoice is drafted
- THEN the line shows EUR 3.70 without VAT and the mention that it is outside the VAT base
