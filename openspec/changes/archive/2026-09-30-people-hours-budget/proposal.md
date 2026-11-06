---
kind: code
depends_on: [hours-to-humaniq]
---

# Proposal: people-hours-budget

## Summary

A project manager budgets hours per person on a project and wants a warning
when the logged hours approach or pass that budget. Shillinq records the
estimate per assignment and shows it, but nothing compares it with the hours
logged. This change keeps the logged hours per assignment up to date from
wherever hours are booked and warns the project owner at 80 and 100 percent.

## Motivation

One row of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), decided `build`
by the OpenSpec pass of 2026-09-27.

**`ppl-hours-budget`**, "Budget hours per project and be warned when they
run over." Rated partial, built. Matrix evidence:
"ProjectAssignment.estimatedHours (lib/Settings/shillinq_register.json:6218)
records an estimate per person per project, shown on ProjectDetail and
Utilisatie; nothing warns when logged hours exceed it." Note: "Estimate
recorded, no overrun warning." Tender demand:
https://www.tenderned.nl/aankondigingen/overzicht/416109. Two competitors
rate it yes:

- exact-online: https://www.exact.com/nl/producten/projectadministratie/features-en-prijzen, "Gebudgetteerde uren & kosten per project bewaken".
- odoo: odoo/odoo@19.0 `addons/hr_timesheet/models/project_project.py:34` `allocated_hours`, `:32` `remaining_hours` and `:33` `is_project_overtime` with a filter for projects over budget.

## Affected Projects

- [ ] Project: `shillinq`: logged hours kept on the assignment and the project, and two warnings.

## Scope

### In Scope

- `ProjectAssignment.loggedHours` and `hoursUsedPercent`, and a project total.
- Keeping `loggedHours` current from the hours source that `hours-to-humaniq` establishes.
- Notifications to the project owner at 80 and 100 percent, once per threshold.
- An over-budget filter on the projects list and the figures on the project page.

### Out of Scope

- Cost budgets in money; `budget-projection-engine` and the project cost aggregation cover those.
- Blocking hours beyond the budget; the tender row asks for a warning.

## Approach

The used percentage and the thresholds are declared on the assignment; the
logged hours are summed when an hour is booked. Details are in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/shillinq_register.json` `ProjectAssignment` (by a `register.d` fragment): fields, calculation, notifications.
- `lib/Listener/`: one listener on hour bookings.
- `src/manifest.json` `ProjectDetail`, `Utilisatie` and the projects index.

## Cross-Project Dependencies

- humaniq: after `hours-to-humaniq`, an hour is a humaniq `TimeEntry` with a domain object reference. This change reads the hour through whichever read path that change decides (a shillinq cost line per `TimeEntry`, or the `allocationKey` payload) and needs no humaniq change of its own.

## Risks

### Risk 1: Hours booked before the release
**Severity:** Low. **Mitigation:** a repair step sums the existing hours once per assignment.

## Rollback Strategy

Remove the listener and the notifications; the fields stay.

## Open Questions

None.
