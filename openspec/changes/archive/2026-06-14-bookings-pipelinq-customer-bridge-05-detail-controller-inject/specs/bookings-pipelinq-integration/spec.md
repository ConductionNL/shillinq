# Spec Delta: bookings-pipelinq-integration (member 05 — detail controller)

## ADDED Requirements

### Requirement: The booking detail controller SHALL inject customer profile and history when a contact is linked

The booking detail controller SHALL, when `booking.pipelinqContactId`
is set, call the Contact and klantbeeld read paths and pass the
profile and transaction history to the detail view.

#### Scenario: Linked booking loads profile and history

- **GIVEN** a booking with `pipelinqContactId` set and pipelinq
  reachable
- **WHEN** the detail route is requested
- **THEN** the controller SHALL call `getContact` and `getKlantbeeld`
- **AND** SHALL pass the profile and history to the view.

#### Scenario: Unlinked booking shows no profile

- **GIVEN** a booking with `pipelinqContactId` unset
- **WHEN** the detail route is requested
- **THEN** the controller SHALL pass a "not linked to pipelinq" flag
- **AND** SHALL NOT call the pipelinq adapter.

### Requirement: The booking detail SHALL render even when pipelinq fails

The controller SHALL never block the booking detail render on a
pipelinq failure; adapter errors SHALL be converted into a
view-renderable `contactError` with a user-safe message.

#### Scenario: Adapter failure degrades gracefully

- **GIVEN** a linked booking and the pipelinq adapter raises an error
- **WHEN** the detail route is requested
- **THEN** the controller SHALL set `contactError` with a sanitised
  message
- **AND** the booking detail SHALL still render.
