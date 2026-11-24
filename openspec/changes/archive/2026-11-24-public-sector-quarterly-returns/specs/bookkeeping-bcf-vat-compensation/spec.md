# bookkeeping-bcf-vat-compensation Specification (delta)

## Purpose

The BCF claim is computed on its page. From shillinq matrix rows `pub-bcf`, `pub-fido`.

## ADDED Requirements

### Requirement: A claim is computed for its quarter (REQ-BCF-010)

The app SHALL compute a BCF claim for its quarter from the VAT posted on compensable accounts and SHALL show the breakdown per account on the claim page.

#### Scenario: A controller computes the third quarter claim

- GIVEN EUR 21,000 of VAT posted in 2026-Q3 on accounts mapped as compensable at 100 percent and EUR 4,200 on an account at 50 percent
- WHEN the controller chooses compute on the 2026-Q3 claim
- THEN the claim shows EUR 23,100 compensable
- AND the breakdown lists both accounts
