---
kind: code
depends_on: [hours-to-humaniq, sales-time-and-expense-billing]
---

# Proposal: sales-billable-rate-per-hour

## Summary

Every logged hour on a project gets the billable rate a client pays for it,
resolved from the rate card on the person's project assignment, and the
project page shows each hour with its rate, its value and whether it is billed.
Today an hour on an invoice is priced at a rate the Rate cards page never
feeds, and when nothing matches it is priced at EUR 100 without saying so.

## Motivation

Matrix row **`ppl-time`**, "Track time on projects at billable rates."
(`openspec/parity/capabilities.json`), state specified, rated partial. The row
was linked to humaniq's archived `2026-07-14-time-entry-capture`, which shipped
the hour with a project and a billable flag but no rate. The re-rating of
2026-10-07 named two missing parts: a rate on the hour, and the hand-off of
rated hours into billing. The hand-off is the open change
`sales-time-and-expense-billing`. This change is the rate.

Competitors rate the row yes: Exact Online ("Factureer uren en andere diensten
altijd tegen het juiste tarief"), Moneybird, and Odoo with per-person rates on a
project (`addons/sale_timesheet/models/project_sale_line_employee_map.py:8`).

### Where the rate belongs: shillinq, not humaniq

The billable rate is a sales price to a client. ADR-107 (hydra
`openspec/architecture/adr-107-money-and-effort-ownership.md`) gives humaniq the
hour (decision 6) and the composed **cost** rate an hour costs the employer
(decision 4). It gives nothing about a sell price to humaniq. Shillinq already
owns the sell price: the rate card family (`openspec/specs/rate-card-management`,
row `sal-rate-cards`, built), `ProjectAssignment.rateCardId` and
`BillableInvoiceLine.rateApplied`. So humaniq's `TimeEntry` keeps no rate. It
carries the hour, the project, the person and the billable flag, and shillinq
prices it when it reads it.

### What the code does now (shillinq development `ed143e2f9`)

- `InvoiceGenerationService::loadTimeEntries()` (`lib/Service/InvoiceGenerationService.php:529`)
  asks for a `resourceType` that `UrenRegistratie` does not have, so every hour
  resolves as `consultant`.
- `RateCardResolver::resolveRate()` (`lib/Service/RateCardResolver.php:55`)
  looks up `RateRecord` by `rateCardId` and `resourceType`, two properties
  `RateRecord` does not declare, then `RateCard.hourlyRate`, then
  `fallbackRate()` at :228: EUR 100, logged as a warning, shown to nobody.
- The Rate cards page (`RateCards`, `/beheer/rate-cards`) edits the other
  family: `RateCardTemplate`, `RateCardVersion`, `RateSchedule` with the
  user, role, project, client and blended tiers of REQ-RATE-004 and
  REQ-RATE-005. No service reads `RateSchedule`; only
  `RateScheduleOverlapGuard` touches it.
- `ProjectAssignment` already carries `rateCardId` and `recognisedRate`, and
  nothing fills `recognisedRate`.

A user who sets Alice's rate at EUR 130 on the Rate cards page therefore bills
her at EUR 100.

## Affected projects

- [ ] Project: `shillinq`: a tier resolver over `RateSchedule`, a role on the
  project assignment, a billable-hours card on `ProjectDetail`, the end of the
  silent fallback.

## Scope

### In scope

- Resolving the rate of an hour from the rate card on the person's assignment
  to the project, by the tier order of REQ-RATE-005.
- A billing role per assignment, so the role tier can match.
- Recording each resolution as a `RateRecord` (REQ-RATE-007).
- An hour without a rate shown as "No rate" and refused on an invoice draft.
- A Billable hours card on the project page with rate, tier, value and billed
  state per hour, and the unbilled value in total.
- The Generate invoice page taking the rate per hour instead of one rate card
  for the whole invoice.

### Out of scope

- Where hours are recorded and approved: humaniq, through `hours-to-humaniq`.
- The tick list of unbilled hours on the Generate invoice page:
  `sales-time-and-expense-billing`.
- The employer cost of an hour: ADR-107 decision 4, humaniq.
- Retiring the `RateCard` and `RateRecord` lookup path for rate cards made
  before this change. design.md keeps it as a read for old invoices only.

## Approach

`BillableRateService` takes an hour (person, project, date), finds the
assignment, the assignment's `RateCardTemplate`, the version effective on the
date and the first matching `RateSchedule` by tier, and returns the rate, the
unit, the tier and the schedule, or "no rate". `BillableHoursSource` (from
`sales-time-and-expense-billing`) attaches that answer to each hour it lists.
Details are in design.md.

## Risks

- **Old invoices change price.** They do not: `BillableInvoiceLine.rateApplied`
  is a snapshot and this change reads it, never re-resolves it.
- **Projects without a rate card stop billing.** Intended. An hour with no
  rate is refused with the person and date named, instead of billed at EUR 100.
  The project page lists the hours without a rate before anyone drafts an
  invoice.

## Rollback

Revert the PR. The resolver returns to the `RateCard` path and its fallback;
`billingRoleId` stays on assignments, unused.
