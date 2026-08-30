---
kind: code
depends_on: [bookings-pipelinq-customer-bridge-03-contact-read]
chain:
  - bookings-pipelinq-customer-bridge-01-config-contact-link
  - bookings-pipelinq-customer-bridge-02-http-adapter-core
  - bookings-pipelinq-customer-bridge-03-contact-read
  - bookings-pipelinq-customer-bridge-04-klantbeeld-read
  - bookings-pipelinq-customer-bridge-05-detail-controller-inject
  - bookings-pipelinq-customer-bridge-06-profile-card-ui
  - bookings-pipelinq-customer-bridge-07-timeline-publish-core
  - bookings-pipelinq-customer-bridge-08-lifecycle-events
  - bookings-pipelinq-customer-bridge-09-async-retry
  - bookings-pipelinq-customer-bridge-10-integration-e2e-tests
  - bookings-pipelinq-customer-bridge-11-docs-observability
---

# Proposal: bookings-pipelinq-customer-bridge-04-klantbeeld-read

Member 4 of 11 in the `bookings-pipelinq-customer-bridge` chain
(ADR-032). Predecessor:
`bookings-pipelinq-customer-bridge-03-contact-read`. Successor:
`bookings-pipelinq-customer-bridge-05-detail-controller-inject`.

This member implements the **klantbeeld transaction-history read
path**: `PipelinqContactAdapter::getKlantbeeld($externalId, $limit)`
with limit/offset pagination. Consumes the Contact read path (03) and
the transport core (02).

## Why

The customer-360 value comes from showing recent transaction history
alongside the profile. A 5-entry rolling window with pagination keeps
the detail view light while letting an operator drill deeper.

## What Changes

- Implement `getKlantbeeld($externalId, $limit = 5)` — GET
  `/api/v1/contacts/{id}/klantbeeld` with `limit` (default 5, max 100)
  and `offset` query params.
- Parse the transactions array → objects with date, description,
  amount, currency, status.
- No local caching (transactions are immutable; cache only within the
  session), with graceful handling of empty history and klantbeeld
  outage while Contact succeeds.

## Out of Scope (this member)

Controller wiring (05), UI rendering of the history (06).
