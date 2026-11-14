# Delta: notification-booking-sms-reminders

## ADDED Requirements

### Requirement: A reminder SMS goes to the configured provider at its send time (REQ-SMS-021)

A scheduled job SHALL hand each due SMS reminder to the provider configured on the channel. With no provider configured the channel SHALL show that it is not connected instead of reporting a send.

#### Scenario: A reminder SMS is handed to the provider

- **GIVEN** an SMS channel with a configured provider and an appointment whose reminder is due
- **WHEN** the job runs
- **THEN** the provider receives the message and the appointment records the send
