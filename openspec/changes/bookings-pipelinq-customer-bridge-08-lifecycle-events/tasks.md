# Tasks — Member 08: lifecycle events + auth handling

Sourced from the giant's Phase 3 (lifecycle transitions + auth error handling + write-path tests).

## Lifecycle transitions

- [ ] `booking.confirmed` → type=booking.confirmed
- [ ] `booking.cancelled` → type=booking.cancelled (include cancellation reason if present)
- [ ] `booking.completed` → type=booking.completed
- [ ] Each transition follows the same publish + retry pattern (member 07)

## Auth error handling

- [ ] On 401 Unauthorized: log ERROR "Invalid pipelinq API token"
- [ ] Do NOT retry (auth errors are permanent)
- [ ] Send an admin notification if available
- [ ] Booking operation still completes

## Tests

- [ ] Mock timeline API: 401 unauthorized (no retry)
- [ ] Test event payload structure for confirmed / cancelled / completed
- [ ] Test circuit-breaker behaviour on the write path
- [ ] Test logging at each step
