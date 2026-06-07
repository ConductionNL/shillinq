# Tasks — Member 08: lifecycle events + auth handling

Sourced from the giant's Phase 3 (lifecycle transitions + auth error handling + write-path tests).

## Lifecycle transitions

- [x] `booking.confirmed` → type=booking.confirmed
- [x] `booking.cancelled` → type=booking.cancelled (include cancellation reason if present)
- [x] `booking.completed` → type=booking.completed
- [x] Each transition follows the same publish + retry pattern (member 07)

## Auth error handling

- [x] On 401 Unauthorized: log ERROR "Invalid pipelinq API token"
- [x] Do NOT retry (auth errors are permanent)
- [x] Send an admin notification if available
- [x] Booking operation still completes

## Tests

- [x] Mock timeline API: 401 unauthorized (no retry)
- [x] Test event payload structure for confirmed / cancelled / completed
- [x] Test circuit-breaker behaviour on the write path
- [x] Test logging at each step
