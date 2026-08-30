# Spec Delta: Goods Receipt Note

## ADDED Requirements

### Requirement: Record goods receipt with line-level quantities and stock mutation

The system SHALL allow a magazijn-medewerker to create a
`GoodsReceiptNote` linked to one or more POs, capturing per-line
quantities (quantity_received, quantity_accepted, quantity_rejected) with
a rejection_reason (schade, verkeerd_product, expired, niet_besteld,
other), carrier, delivery-note reference, and delivery-condition photos.
On acceptance the system SHALL update the PO lifecycle to
`partial_received` (or `fully_received` when complete), SHALL credit
inventory for quantity_accepted at the PO line gl_account, SHALL NOT
decrement inventory for rejected quantities, and SHALL fire the GR/IR
clearing posting (implemented in member 09).

#### Scenario: Partial receipt of 180 of 200 chairs

- **GIVEN** a magazijn-medewerker receives 180 of 200 ordered office chairs against PO-2026-0003
- **WHEN** they create a GoodsReceiptNote via the mobile app with a GoodsReceiptLine (quantity_received=180, quantity_accepted=180, quantity_rejected=20, rejection_reason="short_shipped")
- **THEN** the system updates the PO status to "partial_received", credits inventory for 180 units at the PO-line gl_account, fires a GR/IR clearing posting, and allows upload of delivery-condition photos

#### Scenario: Rejected quantity does not mutate inventory

- **GIVEN** a GoodsReceiptLine with quantity_received=50, quantity_accepted=40, quantity_rejected=10
- **WHEN** the GRN is accepted
- **THEN** inventory is credited for 40 units only and the 10 rejected units are recorded with a rejection_reason but do not mutate stock
