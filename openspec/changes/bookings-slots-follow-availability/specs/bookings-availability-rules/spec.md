# Delta: bookings-availability-rules

## ADDED Requirements

### Requirement: Offered slots follow the availability rules, breaks and constraints (REQ-BAR-011)

The system SHALL offer a resource's slots for a date only inside the windows
of its active availability rules for that weekday whose effective window
holds the date. A slot that overlaps a break of the resource SHALL NOT be
offered. The active booking constraints of the resource SHALL narrow the
slots: no slot starts inside the minimum notice, and no slot lies beyond the
maximum days ahead. A resource without any active rule SHALL keep its own
opening and closing time.

#### Scenario: A staff member works mornings only on Wednesday

- **GIVEN** stylist Sanne with an active rule Monday to Friday 09:00 to 17:00 and an active rule Wednesday 09:00 to 12:30
- **WHEN** a customer opens the booking widget for a 45 minute haircut with Sanne on Wednesday 2 December 2026
- **THEN** the last slot offered starts at 11:45 and no afternoon slot is offered

#### Scenario: A lunch break is not offered

- **GIVEN** a break for Sanne every day from 12:00 to 12:30
- **WHEN** a customer opens the booking widget for a 30 minute service with Sanne on Thursday 3 December 2026
- **THEN** no slot between 12:00 and 12:30 is offered

#### Scenario: The minimum notice keeps today's next hour free

- **GIVEN** a booking constraint of two hours minimum notice on Sanne's services
- **WHEN** a customer opens the widget at 10:10 on Friday 4 December 2026
- **THEN** the first slot offered that day starts at 12:30 or later
