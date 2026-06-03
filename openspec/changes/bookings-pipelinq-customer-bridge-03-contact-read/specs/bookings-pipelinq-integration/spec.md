# Spec Delta: bookings-pipelinq-integration (member 03 — Contact read)

## ADDED Requirements

### Requirement: The adapter SHALL load a pipelinq Contact by externalId

The adapter SHALL fetch a Contact via GET
`/api/v1/contacts/{externalId}` and return its profile fields
(legalName, email, phone, address, kvkNumber). A 404 SHALL be treated
as an expected "not found" outcome without logging an error.

#### Scenario: Contact found in pipelinq

- **GIVEN** a booking with `pipelinqContactId` set and pipelinq
  reachable
- **WHEN** the adapter loads the Contact
- **THEN** GET `/api/v1/contacts/{externalId}` SHALL be called
- **AND** the response SHALL include legalName, email, phone, address
- **AND** the Contact SHALL be cached locally with a 5 minute TTL.

#### Scenario: Contact not found in pipelinq

- **GIVEN** a booking with an unknown `pipelinqContactId`
- **WHEN** the request returns 404
- **THEN** the adapter SHALL return a "not found" result
- **AND** no error SHALL be logged (404 is expected).

#### Scenario: Malformed Contact response

- **GIVEN** pipelinq returns 200 with malformed JSON
- **WHEN** parsing fails
- **THEN** the adapter SHALL return a fallback result
- **AND** SHALL log a WARNING with the raw response
- **AND** SHALL NOT retry (client-side error, not transient).

### Requirement: The adapter SHALL cache Contact data with a bounded TTL

The adapter SHALL cache each Contact for 5 minutes keyed by
`externalId`, expose a manual `clearCache()` invalidation, and serve a
still-valid cached Contact when pipelinq is unavailable.

#### Scenario: Contact cache expires after 5 minutes

- **GIVEN** a Contact cached with a 5 minute TTL
- **WHEN** 5 minutes elapse
- **THEN** the next load SHALL fetch a fresh Contact from pipelinq.

#### Scenario: Manual cache invalidation

- **GIVEN** a cached Contact
- **WHEN** an admin clears the pipelinq cache
- **THEN** all cached Contact entries SHALL be deleted
- **AND** subsequent loads SHALL fetch fresh data.

#### Scenario: Cache degrades gracefully when pipelinq is down

- **GIVEN** a Contact cached locally and pipelinq unavailable
- **WHEN** a booking detail loads and the cache entry is still valid
- **THEN** the cached Contact SHALL be served with no API call.
