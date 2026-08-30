# Design — Member 08: lifecycle events + auth handling

## Scope

Extend the lifecycle handler to confirmed/cancelled/completed and add
auth-error handling. Reuses the member-07 publish core.

## Behaviour

- `booking.confirmed` — same metadata as created.
- `booking.cancelled` — include cancellation reason in metadata if
  present.
- `booking.completed` — published when the booking is marked complete.
- Each follows the member-07 publish + retry + circuit-breaker
  pattern; failures defer to member-09 async retry.

## Auth-error handling (giant Risk + REQ)

- On 401 Unauthorized: log ERROR "Invalid pipelinq API token; check
  config", do NOT retry (permanent), send an admin notification if
  enabled. The booking operation still completes.

## Security (ADR-005)

- 401 handling never echoes the token; admin notification references
  the config location, not the secret value.
