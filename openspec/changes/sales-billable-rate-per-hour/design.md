# Design: sales-billable-rate-per-hour

Read at shillinq development `ed143e2f9`, humaniq development and hydra
ADR-107 on 2026-10-07. Shillinq is not a canvas app; no board draws this
screen, so the project page follows the existing `ProjectDetail` widgets.

## Decisions

### D1. The rate lives in shillinq and is resolved when the hour is read

ADR-107 decision 6 puts the hour in humaniq; decision 4 gives humaniq the cost
rate. A sell rate depends on the client, the project and the person's role on
that project, all of which shillinq holds (`engagement.customerId`,
`ProjectAssignment`, the rate card family). Putting a sell rate on humaniq's
`TimeEntry` would make humaniq store a price it cannot compute. Alternative
rejected: a `rate` property on `TimeEntry` filled by the person logging time.
Nobody logging time knows the client price, and two rates on one hour (cost and
sell) in humaniq is the confusion ADR-107 decision 4 forbids for cost alone.

### D2. One rate card family: the one the user edits

The Rate cards page edits `RateCardTemplate`, `RateCardVersion` and
`RateSchedule`. The resolver reads `RateCard` and `RateRecord`. This change
makes the edited family the one that prices: `ProjectAssignment.rateCardId`
names a `RateCardTemplate`. `RateRecord` becomes what REQ-RATE-007 says it is,
the audit record of a resolution, written by the resolver and never read as a
rate source. The `RateCard` path stays only for `BillableInvoice` records that
already name a `RateCard` id (a read for old drafts, no new writes).

### D3. Tier order, matched on what an hour carries

Per REQ-RATE-005, first match wins:

| Tier | `RateSchedule.entityId` matched against |
|---|---|
| user | the hour's `personId` |
| role | `ProjectAssignment.billingRoleId` (new, nullable) |
| project | the hour's `projectId` |
| client | `engagement.customerId` of the project |
| blended | `entityId` null |

Only schedules of the `RateCardVersion` whose window contains the hour's date,
with `status` `active` and the date inside the schedule's own window, count.
`unit` `hourly` multiplies by hours; `daily` by hours divided by 8 (the
`capacityHoursPerWeek` of the assignment divided by 5 when set). `monthly` and
`fixedPrice` schedules are not hour rates and are skipped. Volume brackets are
out of scope; a schedule with brackets uses its base `rate`.

### D4. No rate is an answer, not EUR 100

`fallbackRate()` goes. The resolver returns `{status: "no-rate", reason}` with
one of: no assignment for this person on this project, the assignment names no
rate card, no version effective on the date, no schedule matches. The page shows
the reason; `draftInvoice()` refuses a ticked hour that has no rate with HTTP 422
and a message naming the person and the date. A refusal from an
`OCSController` must not pass through `OCSMiddleware` as HTTP 200 (the app's
invoice controller is a plain `Controller`; keep it that way).

### D5. The project page shows rated hours

`ProjectDetail` gains a `project-billable-hours` widget after `project-hours`:
one row per hour from `BillableHoursSource::forProject(projectId, from, to)`
with person, date, hours, rate, tier, value and "Billed on <invoice number>" or
"Not billed", and a footer with unbilled hours, unbilled value and hours without
a rate. Default period: the current month, with a month picker. When no hours
source is bound, the widget says hours are not available because the hours app
is not connected, the same message `sales-time-and-expense-billing` uses. The
widget is a `type: "custom"` widget inside the existing detail page (gate 69
refuses a new custom page) backed by `GET /api/v1/projects/{id}/billable-hours`.

### D6. The assignment shows its rate

`ProjectAssignment.recognisedRate` is filled by the resolver for the
assignment's role on today's date, so the "Hours per assignment" list on the
project page shows the rate each person bills at. It is a display snapshot,
refreshed when the assignment or its rate card version changes; an invoice line
never reads it.

## Data

- `ProjectAssignment.billingRoleId`: string, nullable, title "Billing role",
  the `RateSchedule.entityId` of a role tier.
- `ProjectAssignment.rateCardId`: description changes to name `RateCardTemplate`.
- `RateRecord`: unchanged schema; written by `BillableRateService` with
  `userId`, `roleId`, `projectId`, `clientId`, `lookupDate`, `resolvedTier`,
  `resolvedScheduleId`, `resolvedRate`, `resolvedUnit`.
- `BillableInvoiceLine.rateApplied`: unchanged; filled from the resolution.

## Example data (seed)

Project "Renovatie Kade 12" (customer Woningcorporatie Het Anker), rate card
"Standaard 2026": blended EUR 85, role senior EUR 120, user Alice EUR 130,
client Het Anker EUR 95. Assignments: Alice (role senior), Bram (role senior),
Chris (no role), Dana (no assignment). October 2026 hours: Alice 6 h, Bram
4.5 h, Chris 2 h, Dana 1 h. Expected: Alice EUR 130 user tier, Bram EUR 120
role tier, Chris EUR 95 client tier, Dana no rate (no assignment).

## Open questions

- Should a person with hours but no assignment be priced at the project or
  client tier instead of "no rate"? This design says no: billing someone who is
  not on the project is more likely a booking error than a price question.
