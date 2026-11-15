# shillinq-invoice-quick-draft Specification (delta)

## Purpose

A user starts a quick invoice draft from every page of shillinq. From shillinq
matrix row `sal-quick-draft`.

## ADDED Requirements

### Requirement: The quick draft opens from every page of the app (REQ-IQD-009)

Every shillinq page SHALL offer a Create invoice action that opens the
quick-draft modal over that page. Saving or cancelling the modal SHALL leave the
user on the page they started from. On a customer's detail page the modal SHALL
start with that customer selected.

#### Scenario: A bookkeeper drafts an invoice from the purchase invoices page

- GIVEN a bookkeeper on the purchase invoices page
- WHEN they choose Create invoice and save a draft for Bakkerij De Korenaar B.V.
- THEN a draft sales invoice for Bakkerij De Korenaar B.V. exists
- AND the bookkeeper is still on the purchase invoices page

#### Scenario: The customer page starts the draft for its customer

- GIVEN a bookkeeper on the detail page of customer Gemeente Voorbeeld
- WHEN they choose Create invoice
- THEN the quick-draft modal opens with Gemeente Voorbeeld selected as the customer

#### Scenario: Cancelling keeps the page

- GIVEN a bookkeeper on the bank reconciliation page who opened Create invoice
- WHEN they cancel the modal
- THEN no invoice is saved and the bank reconciliation page is shown as before
