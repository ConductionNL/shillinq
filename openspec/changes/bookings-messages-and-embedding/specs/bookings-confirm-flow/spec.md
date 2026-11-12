# Delta: bookings-confirm-flow

## ADDED Requirements

### Requirement: The confirmation request reaches the customer by email (REQ-BCF-013)

When an appointment waits for confirmation, the system SHALL send the customer an email with the confirm link and the ICS file through Nextcloud's mailer. The send SHALL be recorded on the appointment, and a failed send SHALL show on the pending confirmations page.

#### Scenario: A self-booked appointment sends the request

- **GIVEN** a customer books a session for 3 December at 10:00 that needs confirmation
- **WHEN** the appointment is created
- **THEN** the customer receives an email with the confirm link and the appointment shows the request as sent
