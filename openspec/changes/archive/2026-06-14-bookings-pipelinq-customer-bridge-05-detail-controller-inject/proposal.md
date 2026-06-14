---
kind: code
depends_on: [bookings-pipelinq-customer-bridge-04-klantbeeld-read]
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

# Proposal: bookings-pipelinq-customer-bridge-05-detail-controller-inject

Member 5 of 11 in the `bookings-pipelinq-customer-bridge` chain
(ADR-032). Predecessor:
`bookings-pipelinq-customer-bridge-04-klantbeeld-read`. Successor:
`bookings-pipelinq-customer-bridge-06-profile-card-ui`.

This member wires the **booking detail controller** to inject customer
profile + history into the detail response. It calls the member-03/04
read paths and degrades gracefully on failure. No UI markup yet — that
is member 06.

## Why

The read adapters are useless until a route surfaces their data to the
detail view. This member is the seam between transport and
presentation: it decides when to call pipelinq (only when
`pipelinqContactId` is set), assembles the profile + history payload,
and converts adapter errors into a `contactError` the view can render.

## What Changes

- Extend the booking detail controller for route `/bookings/{id}`.
- When `booking.pipelinqContactId` is set, call `getContact()` and
  `getKlantbeeld()` and pass both to the view.
- Set `contactError` when the adapter calls fail; never block the
  booking detail render.
- Surface "Customer not linked to pipelinq" when `pipelinqContactId`
  is null.

## Out of Scope (this member)

Profile-card / history markup + CSS (06).
