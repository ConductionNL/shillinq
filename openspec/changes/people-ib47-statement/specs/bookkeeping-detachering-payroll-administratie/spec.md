# Delta: bookkeeping-detachering-payroll-administratie

## ADDED Requirements

### Requirement: The annual IB47 batch is prepared from the payments to third parties (REQ-DPA-021)

The system SHALL, when the annual IB47 batch of a year is prepared, write one
`IB47Record` per payee marked as an IB47 recipient, holding the sum of that
payee's supplier invoices paid in that year. Preparing the same year again
SHALL update those records and SHALL NOT write a second record for a payee.
The prepared batch SHALL produce the IB47 statement as a downloadable file.

#### Scenario: A freelancer's payments land in the 2026 batch

- **GIVEN** payee J. de Boer marked as an IB47 recipient, with invoices of EUR 1,200 and EUR 800 paid in 2026 and one of EUR 500 paid in 2025
- **WHEN** a bookkeeper prepares the IB47 batch for 2026
- **THEN** the batch lists J. de Boer with EUR 2,000 and the statement file names that amount

#### Scenario: A second prepare writes no duplicate

- **GIVEN** the 2026 batch was prepared and J. de Boer received a further EUR 300 in December
- **WHEN** the bookkeeper prepares the 2026 batch again
- **THEN** the batch still holds one record for J. de Boer, now with EUR 2,300
