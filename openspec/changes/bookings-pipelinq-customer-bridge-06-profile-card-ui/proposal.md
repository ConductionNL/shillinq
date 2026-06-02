---
kind: code
depends_on: [bookings-pipelinq-customer-bridge-05-detail-controller-inject]
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

# Proposal: bookings-pipelinq-customer-bridge-06-profile-card-ui

Member 6 of 11 in the `bookings-pipelinq-customer-bridge` chain
(ADR-032). Predecessor:
`bookings-pipelinq-customer-bridge-05-detail-controller-inject`.
Successor: `bookings-pipelinq-customer-bridge-07-timeline-publish-core`.

This member renders the **customer profile card + transaction-history
section** in the booking detail view, consuming the payload the
controller (05) injects. This completes the read path's user-facing
half.

## Why

The profile card is where the integration's value lands for the
operator: name, contact details, KvK, address, and a recent
transaction list, inline in the booking detail. It is read-only (edits
happen in pipelinq) and resilient (clear fallbacks when data is
missing or unavailable).

## What Changes

- Render the customer profile card: org legal name / person name,
  email (mailto), phone (tel), KvK, address, and a link to the
  pipelinq Contact (new tab).
- Render the transaction-history section: up to 5 recent transactions
  with "Load more" pagination.
- Fallback UI for not-found / unavailable / empty / null-link states,
  with missing optional fields omitted (no empty labels).
- Read-only affordance ("Edit customer details in pipelinq").

## Out of Scope (this member)

Timeline write path (07+).
