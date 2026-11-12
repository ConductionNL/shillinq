---
kind: code
---

# Proposal: bookings-slots-follow-availability

## Summary

An office sets opening hours per staff member or resource, adds breaks and
sets booking constraints such as a minimum notice. Shillinq keeps those as
`AvailabilityRule`, `ResourceBreak` and `BookingConstraint` records, but no
PHP reads them: `lib/Service/SlotService.php` offers slots from the
resource's single opening and closing time and the existing appointments.
This change makes the offered slots follow the active rules, the breaks and
the constraints.

## Motivation

Capability row `bok-availability` ("Set opening hours and availability per
staff member or resource") was marked built. The verify round of 12 Nov 2026
(decision 389) found that the rules are kept but never applied, so the row
goes back to specified with this change as its plan.

## What changes

- `SlotService` reads the active availability rules of the resource for the
  weekday and date, inside their effective window, instead of the single
  opening window.
- Breaks of the resource are taken out of the offered slots.
- Booking constraints (minimum notice, maximum days ahead) narrow the slots.

## Affected projects

- shillinq only.
