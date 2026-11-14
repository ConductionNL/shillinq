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

## Built (2026-09-30): where the code differs from the decisions above

- **The project.** `ProjectDetail` and the projects list read the `engagement` schema, and `ProjectAssignment.projectId` points at it there (the planninq wording on the field is older than the page). The project owner is `engagement.responsibleUser`. `AssignmentHours` copies it onto the assignment as `projectOwner`, with the project name as `projectTitle`, because a declared recipient can only read a field of the object that changed.
- **Warnings (D2).** Each notification is an `updated` trigger on its warned flag going from false to true (`hoursWarnedAt80`, `hoursWarnedAt100`), so it is sent once. A flag stays true while the hours fall back; a changed estimate clears the flags in one write and sets them again in a second, so a still-crossed threshold warns again for the new estimate.
- **Totals and filter (D3).** The listener also writes `estimatedHoursTotal`, `loggedHoursTotal`, `remainingHoursTotal` and `hoursOverBudget` on the project; the over-budget filter on the projects list reads `hoursOverBudget`.
- **Project page.** `ProjectDetail` rendered fields plus two `relatedLists` that no component draws, so the assignments were never on the page. It is now a widget grid: project, hours budget, hours per assignment (estimated, logged, remaining, used), revenue recognition and WIP history.
- **Deletion.** OpenRegister may still return a deleted hour while `ObjectDeletedEvent` is handled, so the listener leaves that hour's id out of the sum.
