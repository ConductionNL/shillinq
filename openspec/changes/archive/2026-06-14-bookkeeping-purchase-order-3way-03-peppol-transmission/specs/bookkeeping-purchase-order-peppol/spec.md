# Spec Delta: Purchase Order Peppol Transmission

## ADDED Requirements

### Requirement: Transmit approved PO via Peppol with PDF+email fallback

The system SHALL transmit an approved `PurchaseOrder` to its supplier as
a UBL 2.1 Order document conforming to Peppol BIS Ordering 3.0, submitting
via the openconnector Peppol Access Point and recording the returned
`peppol_message_id` (URN format) and `peppol_sent_at` timestamp. When the
supplier is not a Peppol participant, the system SHALL fall back to PDF +
email transmission and SHALL record `peppol_fallback_reason`. In both
cases the PO SHALL transition to lifecycle state `sent`, and the system
SHALL NOT allow transmission of a PO whose approval chain is incomplete.

#### Scenario: Peppol-registered supplier receives a UBL Order

- **GIVEN** an approved PO and a supplier with Peppol participant ID 0192:1234567890
- **WHEN** the Inkoper triggers "Send PO"
- **THEN** the system transforms the PO to a UBL Order, submits it to the openconnector Peppol Access Point, records peppol_message_id (URN) and peppol_sent_at, and transitions the PO to `sent`

#### Scenario: Non-Peppol supplier falls back to PDF+email

- **GIVEN** an approved PO and a supplier that is not a Peppol participant
- **WHEN** the Inkoper triggers "Send PO"
- **THEN** the system sends the PO as PDF + email, records peppol_fallback_reason "supplier_not_peppol_participant", and transitions the PO to `sent`
