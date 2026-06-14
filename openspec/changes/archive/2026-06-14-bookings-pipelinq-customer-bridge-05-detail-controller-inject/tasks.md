# Tasks — Member 05: detail controller injection

Sourced from the giant's Phase 2 (modify booking detail controller).

## Controller wiring

- [x] Extend the booking detail controller for route `/bookings/{id}`
- [x] If `booking.pipelinqContactId` is set, call `getContact($externalId)`
- [x] If set, also call `getKlantbeeld($externalId)`
- [x] Pass profile + history to the detail view
- [x] Set `contactError` (sanitised message) when adapter calls fail
- [x] Pass a "not linked to pipelinq" flag when `pipelinqContactId` is null
- [x] Add cache busting (e.g. `?nocache=1`) for dev/test

## Tests

- [x] Test linked booking injects profile + history
- [x] Test unlinked booking shows the "not linked" path
- [x] Test adapter failure sets `contactError` and still renders detail
