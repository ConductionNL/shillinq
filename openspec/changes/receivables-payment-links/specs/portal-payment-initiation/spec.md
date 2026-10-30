# portal-payment-initiation Specification (delta)

## Purpose

The portal's Pay now reaches a real checkout and the portal shows the payment.
From portaliq matrix row `cmp-cas-pay`, which names shillinq's provider binding
as its missing half.

## ADDED Requirements

### Requirement: The portal pay action opens a live checkout (REQ-RPL-005)

When portaliq forwards the `pay` action for a customer's or parent's own open
invoice, shillinq SHALL obtain a checkout through the integriq-backed payment
adapter, SHALL store the checkout link on the request, and SHALL return the
checkout URL to the portal. The portal's deferred answer SHALL remain only for
an instance without a live provider.

#### Scenario: A resident pays a tax assessment in the portal

- GIVEN a resident signed in to the portal with an open invoice of EUR 312.00 for afvalstoffenheffing 2026
- WHEN they press Pay now
- THEN the portal opens an iDEAL checkout for EUR 312.00
- AND after payment the portal shows the invoice as paid with the confirmation summary

### Requirement: A signed pay link is accepted from the portal's guest page (REQ-RPL-006)

The initiation endpoint SHALL accept, besides a portal subject, a pay token
signed for one invoice that portaliq forwards from its guest page, and SHALL
open the checkout for that invoice only. A token for another administration or
with a broken signature MUST be refused.

#### Scenario: A customer without a portal account pays from the invoice mail

- GIVEN a customer without a portal account who received invoice 2026-0412 by mail
- WHEN they follow the pay link and the portal's guest page forwards it
- THEN an iDEAL checkout for invoice 2026-0412 opens
