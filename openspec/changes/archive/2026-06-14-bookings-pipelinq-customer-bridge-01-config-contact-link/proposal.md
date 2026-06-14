---
kind: config
depends_on: []
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

# Proposal: bookings-pipelinq-customer-bridge-01-config-contact-link

Member 1 of 11 in the `bookings-pipelinq-customer-bridge` chain
(ADR-032). No predecessor — this is the chain head. This member
**declares** the configuration surface and the booking↔contact link
that every later member consumes:

- the pipelinq connection settings (endpoint + secured API token),
- the optional `pipelinqContactId` link on the booking record,
- the admin settings UI for entering + testing the connection,
- the integration-test scaffold (pipelinq API mock) the chain reuses.

Predecessor: none (head of chain).
Successor: `bookings-pipelinq-customer-bridge-02-http-adapter-core`.

## Why

Bookings without customer context lack actionable intelligence for
decision-making and customer-lifecycle tracking. A 21/21 competitor
scan confirms klantbeeld enrichment is table-stakes for booking
systems. Pipelinq is the authoritative customer-management system; the
integration eliminates data duplication and surfaces booking events in
the customer-360 view. Before any adapter, controller or view can run,
the connection config and the booking↔contact link must exist and be
admin-configurable — that is this member's scope.

## What Changes

- Declare pipelinq connection config keys (`pipelinq_api_endpoint`,
  `pipelinq_api_token`) with the token stored in the secrets store.
- Declare the optional `pipelinqContactId` field on the booking record
  (per ADR-001 the booking lives in an OpenRegister-managed schema; the
  field is declared in the register/manifest, not via a raw
  `ALTER TABLE`).
- Add the admin settings UI (endpoint input, masked token input, "Test
  Connection" button + success/error feedback).
- Add the integration-test scaffold (pipelinq API mock server) that
  members 03–10 reuse.

## Out of Scope (this member)

The HTTP adapter, read paths, UI card, timeline publishing, async
retry, end-to-end tests and docs — all carried by members 02–11.
