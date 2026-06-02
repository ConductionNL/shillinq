# Design — Member 05: detail controller injection

## Scope

The booking detail controller seam: call the read adapters when a
contact link exists, assemble the payload, translate failures into a
view-renderable `contactError`.

## Behaviour

- Route `/bookings/{id}` (or the app's equivalent detail route).
- If `booking.pipelinqContactId` is set:
  - call `getContact($externalId)` (member 03),
  - call `getKlantbeeld($externalId)` (member 04),
  - pass both to the view.
- On adapter failure, set `contactError` with a user-safe message
  (never the raw upstream body); booking detail still renders.
- If `pipelinqContactId` is null, pass a "not linked" flag.

## Decisions carried from the giant

- **D2/D4** — the detail view is a read-only consumer of pipelinq
  customer data; the controller never mutates pipelinq.

## Security (ADR-005)

- The detail route follows the booking app's existing auth posture; no
  new public endpoint is introduced. Error messages are sanitised
  (no internal/upstream detail leaked to the response).
