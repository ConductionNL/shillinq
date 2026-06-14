# Design — Member 10: integration & E2E tests

## Scope

Integration + end-to-end tests over the assembled chain, using the
member-01 pipelinq mock server.

## Test surfaces (ADR-008)

- **Integration** — booking create → timeline POST asserted; profile
  card renders Contact + klantbeeld; graceful degradation (pipelinq
  5xx → booking saved, event queued, card shows error).
- **E2E (Playwright, UI)** — admin configures endpoint + token and
  "Test Connection" succeeds (persisted across reload); booking
  lifecycle (create → confirm → cancel → complete) triggers all four
  timeline events.
- **API (Newman)** — timeline POST payload contracts per booking state.

## Reuse

- The pipelinq API mock server scaffold declared in member 01.
