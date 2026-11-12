# Tasks: bookings-slots-follow-availability

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 3. -->

## 1. Slots

- [ ] 1.1 Let `lib/Service/SlotService.php` build each day's windows from the resource's active `AvailabilityRule` records inside their effective window, fall back to the resource's opening time only when it has no rule, and drop slots that overlap a `ResourceBreak` (REQ-BAR-011). Verify: PHPUnit red first for the three scenarios.
- [ ] 1.2 Apply the active `BookingConstraint` minimum notice and maximum days ahead to the slots, for the widget and the staff booking form alike (REQ-BAR-011). Verify: PHPUnit for a slot inside the notice period.

## 2. Live check

- [ ] 2.1 Live: give a resource a Wednesday afternoon off and a lunch break, open the booking widget and see neither offered.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
