# Spec Delta: GR/IR Clearing and GL Posting

## ADDED Requirements

### Requirement: Materialise balanced GR/IR clearing and settlement postings

On goods-receipt acceptance the system SHALL materialise a balanced GL
posting debiting the PO-line gl_account (Inventory or expense) and
crediting the GR/IR clearing account for the line amount. On
ThreeWayMatch approval the system SHALL materialise a second balanced
posting debiting the GR/IR clearing account and crediting Accounts
Payable plus VAT Payable. Both postings SHALL preserve cost_center and
project_code from the PO line, SHALL be linked to the ThreeWayMatch
record, and the GR/IR clearing account saldo SHALL reconcile to zero at
period-end.

#### Scenario: Clearing on GRN accept, settlement on approval

- **GIVEN** a GoodsReceiptNote is accepted for 180 units at PO-line gl_account=1200
- **WHEN** the GRN transitions to "accepted"
- **THEN** the system materialises DR 1200 (Inventory) / CR 2910 (GR/IR Clearing) for the line amount; and when the invoice is subsequently approved, a second posting DR 2910 / CR 4400 (AP) + 2100 (VAT) settles the clearing, both inheriting cost_center from the PO line

#### Scenario: GR/IR saldo reconciles to zero at period-end

- **GIVEN** all GRNs in a period have matching approved invoices
- **WHEN** the period-end GR/IR reconciliation runs
- **THEN** the GR/IR clearing account saldo sums to zero with no dangling goods-in-transit
