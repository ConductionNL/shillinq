# Spec Delta: Purchase Order Core

## ADDED Requirements

### Requirement: Create purchase order with amount-threshold approval chain

The system SHALL create a `PurchaseOrder` after validating the requester
and cost_center budget and generating a CBS-conform `po_number`
server-side. The system SHALL determine the required approval chain from
the PO total: a single approver (Teamleider) below €10,000, two approvers
(Teamleider + Facility Manager) at €10,000 or above, with a procurement
manager added at €50,000 or above. The system SHALL assign an
ApprovalTask to each required approver, notify each via the notification
service, and SHALL NOT allow the PO lifecycle to advance to `sent` until
every approver has approved with a timestamp.

#### Scenario: Two-approver chain for an €18,500 PO

- **GIVEN** an Inkoper creates a PO for €18,500 with cost_center "FAC-2026"
- **WHEN** the system evaluates the approval_chain based on amount thresholds
- **THEN** it identifies two required approvers (Teamleider, Facility Manager), assigns an ApprovalTask to each, notifies both, and blocks the PO from advancing to "sent" until both approve with timestamps

#### Scenario: Send is blocked until the chain is complete

- **GIVEN** a PO whose approval_chain has one of two required approvals signed
- **WHEN** a caller attempts to transition the PO lifecycle to `sent`
- **THEN** the server rejects the transition and the PO remains in `approved`/`draft` state until the second approval is signed
