# Delta: bookings-self-service-widget

## ADDED Requirements

### Requirement: The widget books from another website (REQ-WSW-011)

The widget API SHALL answer the CORS preflight and SHALL allow the origins registered on the widget key, so the embedded widget books from that website.

#### Scenario: A booking from the customer website

- **GIVEN** a widget key registered for https://kapsalon.example
- **WHEN** a visitor on that site picks a slot and books
- **THEN** the appointment is created and the visitor sees the confirmation
