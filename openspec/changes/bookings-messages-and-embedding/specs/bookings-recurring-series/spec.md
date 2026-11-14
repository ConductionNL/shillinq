# Delta: bookings-recurring-series

## ADDED Requirements

### Requirement: The booking form offers a repeat option that creates the series (REQ-BRS-004)

The booking form SHALL offer a repeat option with a frequency and an end, and saving it SHALL create the appointment series.

#### Scenario: A weekly series is booked in one go

- **GIVEN** a user books a session on Tuesday 10:00 and sets repeat weekly, 6 times
- **WHEN** the user saves
- **THEN** six appointments appear in the calendar as one series
