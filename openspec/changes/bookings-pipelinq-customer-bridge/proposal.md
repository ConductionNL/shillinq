# Proposal: bookings-pipelinq-customer-bridge

`kind: integration` — bridge between Nextcloud booking app and pipelinq
customer management. Fetches customer profile + transaction history
from pipelinq klantbeeld 360, displays enriched context in booking
detail view, and publishes booking lifecycle events to pipelinq
timeline.

## Summary

Enable the **booking detail view to load and display customer profile
+ history from pipelinq klantbeeld 360** as part of the booking
management feature set. This change declares the integration touchpoints
(read pipelinq Contact + klantbeeld; write booking lifecycle events to
pipelinq timeline), the UI data contract for customer profile display,
and the API client adapters for cross-app communication.

The feature surfaces customer context (name, contact details, past
bookings, transaction history) inline in the booking detail view,
reducing context switching and accelerating booking decision-making.
Booking lifecycle events (created, confirmed, cancelled, completed) are
automatically published to the pipelinq timeline for CRM visibility.

## Motivation

Bookings without customer context lack actionable intelligence for
decision-making, service delivery coordination, and customer lifecycle
tracking. A 21/21 competitor scan confirms klantbeeld enrichment is
table-stakes for booking systems.

Pipelinq is the authoritative customer management system; integrating
the booking app with pipelinq eliminates data duplication and ensures
booking events appear in the customer 360 view (timeline). Direct API
integration reduces manual data entry and sync overhead.

## Affected Projects

- [x] Project: Nextcloud Bookings app — adds read-only customer profile
  display in booking detail view; implements pipelinq API client for
  Contact + klantbeeld queries and timeline event publishing.
- [x] Project: pipelinq — consumes Contact + klantbeeld REST APIs
  (read); publishes POST endpoints for timeline event ingestion (write).

## Scope

### In Scope

- One new capability spec (`bookings-pipelinq-integration`) —
  see the `specs/` folder.
- Pipelinq API client class (`PipelinqContactAdapter`) supporting
  Contact lookup, klantbeeld profile fetch, and timeline event
  publishing.
- Booking detail view extension with customer profile card (name,
  email, phone, org, address) and transaction history section
  (5-entry rolling window with pagination).
- Booking lifecycle event handler that publishes CREATED, CONFIRMED,
  CANCELLED, COMPLETED events to pipelinq timeline.
- Error handling for pipelinq API unavailability (graceful degradation;
  booking operations proceed; profile display shows fallback UI).
- Configuration interface to store pipelinq API endpoint + auth token.

### Out of Scope

- Customer creation or mutation in pipelinq from bookings.
- Pipelinq custom field support beyond standard Contact + klantbeeld.
- Two-way sync or conflict resolution for customer metadata.
- Real-time sync of pipelinq contact changes back to booking list.
- Historical backfill of past bookings to pipelinq timeline.

## Approach

One new capability spec (`bookings-pipelinq-integration`) with two
feature clusters:

**Read-path** — REQ-BPC-001 to REQ-BPC-003 define Contact lookup,
klantbeeld fetch, and display logic. Graceful fallback when pipelinq
is unavailable.

**Write-path** — REQ-BPC-004 to REQ-BPC-006 define booking lifecycle
event publishing (CREATED, CONFIRMED, CANCELLED, COMPLETED) and error
handling.

Each requirement follows RFC 2119 conduction-schema (`REQ-BPC-NNN`,
`#### Scenario:` with 4 hashtags, GIVEN/WHEN/THEN).

## New Dependencies

- **pipelinq API** — HTTP/REST, OpenAPI 3.0. Minimal required API:
  - `GET /api/v1/contacts/{id}` — fetch Contact
  - `GET /api/v1/contacts/{id}/klantbeeld` — fetch klantbeeld profile
  - `POST /api/v1/timeline` — publish timeline event
- **guzzlehttp/guzzle** (^7.0) or **symfony/http-client** — HTTP client
  library (consumes existing Nextcloud stack).

## Impact

- `src/Service/PipelinqContactAdapter.php` — new class implementing
  Contact + klantbeeld queries, timeline event publishing, error
  handling and retry logic.
- `src/Controller/BookingDetailController.php` — extends existing
  detail route to inject customer profile data via adapter.
- `src/Setting/PipelinqConfig.php` — new settings interface for
  pipelinq endpoint + API token.
- `templates/booking-detail-with-profile.php` (or extend existing) —
  customer profile card + transaction history UI.
- `tests/Service/PipelinqContactAdapterTest.php` — adapter unit tests
  with mocked pipelinq responses.
- `lib/Settings/Config.php` — new optional `pipelinq_api_endpoint` and
  `pipelinq_api_token` keys.

## Cross-Project Dependencies

- **pipelinq** — HTTP API (Contact, klantbeeld, timeline endpoints).
  Assumed stable and reachable; integration follows REST + JSON
  contracts.
- **Nextcloud core** — `IConfig`, `ILogger`, `IHTTPClientService`,
  existing routing and exception handling (ADR-005).

## Risks

### Risk 1: pipelinq API unavailability

**Severity**: Medium
**Mitigation**: Implement circuit breaker pattern. When pipelinq is
unreachable for 3+ consecutive requests, fallback to showing "Customer
data unavailable" placeholder. Booking creation / confirmation proceeds
without blocking. Timeline event publishing uses async queue (if
available) or best-effort HTTP with timeout.

### Risk 2: Contact/klantbeeld sync staleness

**Severity**: Low
**Mitigation**: Cache pipelinq Contact data for 5 minutes per contact
ID. Klantbeeld (read-only history) is immutable; no refresh needed
until next session. If staleness is critical, a manual "Refresh" button
can trigger cache invalidation.

### Risk 3: Timeline event payload mismatch

**Severity**: Low
**Mitigation**: Define pipelinq timeline event contract in ADR / spec.
Booking events publish a fixed JSON schema:
```json
{ "type": "booking.created", "externalId": "...", "timestamp": "...", "metadata": {...} }
```
Pipelinq rejects mismatched payloads with HTTP 422; adapter logs the
error and retries with exponential backoff up to 3 times.

## Rollback Strategy

Backward-compatible integration. To roll back: disable pipelinq
integration flag in settings, or remove `PipelinqContactAdapter` and
the customer profile card from the detail template. Existing bookings
remain unchanged. Timeline events published during the feature window
are immutable on pipelinq; no reverse sync needed.

## Open Questions

1. **Klantbeeld field surface area** — Confirm which klantbeeld fields
   are available and stable. Are transaction history + payment method
   part of the standard schema, or custom fields?
2. **Timeline event retention** — Does pipelinq keep timeline events
   indefinitely, or is there a purge policy? Affects historical
   visibility in pipelinq UI for long-lived bookings.
3. **Authentication model** — Is pipelinq auth token human-readable or
   an opaque client credential? Should the integration store it in
   Nextcloud secrets store or plaintext config?
4. **Contact identity** — What attribute uniquely identifies a Contact
   in pipelinq? KvK number, email, or a pipelinq-specific ID? How is
   the booking linked to the contact?
