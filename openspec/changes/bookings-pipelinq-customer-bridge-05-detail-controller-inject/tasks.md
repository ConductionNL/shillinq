# Tasks — Member 05: detail controller injection

Sourced from the giant's Phase 2 (modify booking detail controller).

## Controller wiring

- [ ] Extend the booking detail controller for route `/bookings/{id}`
- [ ] If `booking.pipelinqContactId` is set, call `getContact($externalId)`
- [ ] If set, also call `getKlantbeeld($externalId)`
- [ ] Pass profile + history to the detail view
- [ ] Set `contactError` (sanitised message) when adapter calls fail
- [ ] Pass a "not linked to pipelinq" flag when `pipelinqContactId` is null
- [ ] Add cache busting (e.g. `?nocache=1`) for dev/test

## Tests

- [ ] Test linked booking injects profile + history
- [ ] Test unlinked booking shows the "not linked" path
- [ ] Test adapter failure sets `contactError` and still renders detail
