# Delta: notification-booking-email-templates

## ADDED Requirements

### Requirement: A booking reminder mail leaves at its configured time (REQ-BET-011)

The reminder job SHALL render the reminder template for each appointment that reaches its reminder time and SHALL send it through Nextcloud's mailer, recording the send on the appointment.

#### Scenario: A reminder goes out a day before

- **GIVEN** a reminder template set to 24 hours before and an appointment on 3 December at 10:00
- **WHEN** the reminder job runs on 2 December after 10:00
- **THEN** the customer receives the reminder and the appointment records it
