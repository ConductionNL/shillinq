## ADDED Requirements

### Requirement: REQ-UMB-005: A sibling app's month MUST become a draft invoice for the customer that carries its reference

Shillinq SHALL answer `InvoiceIngestRequestedEvent` (sourceApp, externalReference, period
`YYYY-MM`, lines with description, quantity and unit price) by drafting one `BillableInvoice`
for the single `CustomerMaster` whose `externalReference` equals the event's reference, in that
customer's administration. Each line SHALL become a `MeterReading` rated by a flat
`UsageRatePlan` at the line's own unit price, and the invoice SHALL be drafted by
`InvoiceGenerationService::draftInvoice()` with the `usage` model. The same sourceApp,
reference and period with the same lines SHALL answer with the invoice already drafted; with
different lines it SHALL be refused. When no customer or more than one customer carries the
reference, or a line has no readable quantity or unit price, shillinq SHALL refuse on the event
with a reason and write nothing.

#### Scenario: A tenant month becomes a draft invoice

- **GIVEN** customer `cust-9001` in administration `adm-1` carries `externalReference` `t-42`
- **WHEN** dossiq raises the event for `t-42`, period `2026-09`, with two priced lines
- **THEN** shillinq SHALL draft one usage invoice for `cust-9001` in `adm-1` with two lines
- **AND** the event SHALL be accepted with that invoice's id and number

@e2e exclude A same-process event between two apps has no browser surface; the listener and service are covered in tests/Unit/Service/InvoiceIngestServiceTest.php and the live pass is task 1.5.

#### Scenario: The same month twice

- **GIVEN** the month above was drafted
- **WHEN** dossiq raises the same event again
- **THEN** the event SHALL be accepted with the same invoice and `duplicated: true`
- **AND** no second invoice SHALL be drafted

@e2e exclude A same-process event between two apps has no browser surface; the listener and service are covered in tests/Unit/Service/InvoiceIngestServiceTest.php and the live pass is task 1.5.

#### Scenario: No customer carries the tenant

- **GIVEN** no `CustomerMaster` carries `externalReference` `t-77`
- **WHEN** dossiq raises the event for `t-77`
- **THEN** the event SHALL be refused naming the reference
- **AND** nothing SHALL be written

@e2e exclude A same-process event between two apps has no browser surface; the listener and service are covered in tests/Unit/Service/InvoiceIngestServiceTest.php and the live pass is task 1.5.
