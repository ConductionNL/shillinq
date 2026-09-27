# Design: people-hours-budget

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

- **Estimate.** `ProjectAssignment` (`lib/Settings/shillinq_register.json`, around line 6218) has `projectId`, `personId`, `estimatedHours`, `capacityHoursPerWeek`, `startDate`, `endDate`, `rateCardId`, `recognisedRate`, `state` and a calculated `utilization`. It carries no logged hours and no notifications.
- **Hours today.** `UrenRegistratie` has `projectId`, `projectAssignmentId`, `personId`, `hours`, `date` and a calculated `utilizationPercent`.
- **Hours tomorrow.** The open change `hours-to-humaniq` (ADR-107 decision 6) makes humaniq's `TimeEntry` the only hour record and repoints shillinq's hour readers, keeping the ledger, WBSO and urencriterium paths on the old read until the new one is proven. It leaves open whether shillinq derives a cost line per `TimeEntry` or reads humaniq's `allocationKey`.
- **Pages.** `ProjectDetail` (`src/manifest.json:2246`) and `Utilisatie` (`src/manifest.json:8355`) show the estimate.

## Goals / Non-Goals

**Goals**
- Logged against estimated hours per assignment and per project, current within seconds of a booking.
- A warning at 80 and at 100 percent to the project owner.

**Non-Goals**
- Money budgets, blocking.

## Decisions

### D1. Logged hours are kept on the assignment

`ProjectAssignment.loggedHours` (number) is written by
`lib/Listener/AssignmentHoursListener.php` whenever an hour record that
references the assignment is created, updated or deleted: it re-sums that
assignment's hours. The listener listens to OpenRegister object events on
the hour record of the read path `hours-to-humaniq` settles on
(`UrenRegistratie` until then), so it moves with that change instead of
being rewritten.

Alternative considered: an `x-openregister-aggregations` sum on the
assignment. Rejected: after `hours-to-humaniq` the hours sit in another
app's register, which a declared aggregation on this schema cannot reach.

### D2. The percentage and the warnings are declared

`hoursUsedPercent` is an `x-openregister-calculations` field
(`loggedHours / estimatedHours * 100`, null when no estimate).
`x-openregister-notifications` on `ProjectAssignment` sends to the project's
owner when `hoursUsedPercent` crosses 80 and when it crosses 100; each
threshold records `hoursWarnedAt80` and `hoursWarnedAt100` so it fires once
until the estimate changes.

### D3. The project shows the total

`ProjectDetail` gets a card with estimated, logged and remaining hours per
assignment and in total; the projects index gets an "over budget" filter on
`hoursUsedPercent` over 100 for any assignment.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Used percentage | Declarative: `x-openregister-calculations` | Derived field. |
| Warnings at 80 and 100 percent | Declarative: `x-openregister-notifications` (ADR-031 dialect) | Notification on a field condition. |
| Logged hours | Imperative, an object-event listener (ADR-078) | Sums records of another schema, later of another app. |

## Seed Data

Adviesbureau Van Dijk, project "Herinrichting Wmo-loket" for Gemeente
Voorbeeld: assignment of consultant a.bakker estimated at 120 hours with 90
hours logged (75 percent); a 6-hour booking takes it to 96 hours (80
percent) and sends the first warning to the project owner j.devries.

## Risks / Trade-offs

- [The read path of `hours-to-humaniq` is not decided yet] → the listener names its source in one constant and the repair step reads the same source; switching sources is a one-line change and a rerun of the repair.

## Migration Plan

A repair step sums existing hours into `loggedHours` per assignment.

## Open Questions

None.
