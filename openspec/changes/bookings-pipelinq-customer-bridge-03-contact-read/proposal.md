---
kind: code
depends_on: [bookings-pipelinq-customer-bridge-02-http-adapter-core]
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

# Proposal: bookings-pipelinq-customer-bridge-03-contact-read

Member 3 of 11 in the `bookings-pipelinq-customer-bridge` chain
(ADR-032). Predecessor:
`bookings-pipelinq-customer-bridge-02-http-adapter-core`. Successor:
`bookings-pipelinq-customer-bridge-04-klantbeeld-read`.

This member implements the **Contact read path**:
`PipelinqContactAdapter::getContact($externalId)` plus the 5-minute
per-contact cache and its invalidation. It consumes the adapter
transport core from member 02.

## Why

The booking detail view needs the customer profile (name, contact
details, address) to surface context. Caching per contact for 5
minutes avoids N+1 API calls when the same booking detail is reopened
in a session, while keeping data fresh enough.

## What Changes

- Implement `getContact($externalId)` — GET `/api/v1/contacts/{id}`,
  3s timeout, parse JSON, return Contact shape (legalName, email,
  phone, address, kvkNumber).
- Implement the Contact cache: 5-minute TTL per `externalId`, key
  `pipelinq:contact:{externalId}`, with a `clearCache()` invalidation.
- Cache graceful-degradation: serve a still-valid cached Contact when
  pipelinq is unavailable.

## Out of Scope (this member)

Klantbeeld history (04), controller wiring (05), UI (06).
