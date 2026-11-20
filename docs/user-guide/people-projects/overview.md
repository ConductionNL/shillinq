---
sidebar_position: 1
title: People & projects overview
description: Manage pension administration, rate cards, utilisation tracking, and WBSO R&D tax credits in Shillinq.
---

# People & projects

The **People & Projects** section of Shillinq covers employee-related financial administration that sits at the intersection of HR and finance: pension liability accounting, rate management, utilisation reporting, and R&D tax credit tracking.

## Pension administration

Dutch pension obligations follow specific accounting rules (RJ 271 / IAS 19). Shillinq tracks:

- **Pension plans** — the pension schemes your organisation participates in (defined benefit, defined contribution, or multi-employer)
- **Pension valuations** — the actuarial valuations of defined benefit obligations, updated annually
- **Disclosure tables** — the IAS 19 / RJ 271 disclosures required in the annual accounts (present value of obligation, plan assets, net liability, actuarial gains/losses)

Go to **People & Projects → Pension** to manage plans and update valuations.

## Rate cards

**Rate cards** define the billable rates for each employee, role, or service type — used to calculate revenue on time-based projects.

- **Rate cards** — a set of rates valid for a specific period
- **Rate schedules** — planned rate changes in advance

Go to **People & Projects → Rate cards** to configure billing rates.

### How an hour gets its rate

Every hour on a project is priced by the rate card on the person's assignment to that project. Shillinq takes the version of the card that is in effect on the hour's date and uses the first rate that matches, in this order:

| Order | Rate | Matches when |
|---|---|---|
| 1 | Personal rate | the rate names the person who logged the hour |
| 2 | Role rate | the rate names the billing role set on the person's assignment |
| 3 | Project rate | the rate names the project |
| 4 | Client rate | the rate names the project's customer |
| 5 | Standard rate | the rate names nobody: the card's blended rate |

Only active rates whose dates cover the hour count. A day rate is billed per hour at the day rate divided by the working day (8 hours, or the assignment's weekly capacity divided by 5). Monthly and fixed-price rates are not hour rates and are skipped. A rate change in the middle of a month prices each hour by its own date.

### No rate

An hour that no rate prices shows **No rate**, with the reason:

- the person is not assigned to the project;
- the assignment names no rate card;
- the rate card has no version in effect on the hour's date;
- no rate of that version matches the hour.

Shillinq never bills such an hour at a default amount. Drafting an invoice that holds one is refused, naming the person and the date, and no invoice is written. Give the person an assignment, a rate card or a matching rate, then draft again. The rate on a drafted invoice line is kept: changing a rate later does not change a draft.

## Utilisation

The **Utilisatie** (utilisation) dashboard shows what percentage of available capacity is being billed to clients. Track utilisation by:
- Employee
- Team
- Project type

Low utilisation triggers a cost alert (overhead without corresponding revenue).

## Projecten

The **Projecten** page is a project management view showing all active projects, their budgets vs. actuals, and billing status.

On a project's page, the **Billable hours** card lists the hours of the month you pick, each with its person, date, hours, rate, the rate it came from, its value and whether it is billed (**Billed on** the invoice number, or **Not billed**). Below the list are the unbilled hours, their unbilled value and the hours without a rate, so you can fix a missing rate before anyone drafts an invoice. When no hours app is connected, the card says the hours are not available instead of showing zero.

**Hours per assignment** shows each person's billing role and the rate the assignment bills at today. Set the billing role on the assignment to have the role rate apply; the shown rate is refreshed when the role, the rate card or an active rate card version changes.

## WBSO (R&D tax credit)

**WBSO** (*Wet Bevordering Speur- en Ontwikkelingswerk*) is the Dutch R&D tax credit scheme. Companies doing qualifying R&D work can apply for a subsidy on labour costs via the RVO.

Shillinq's WBSO module includes:

### WBSO Tags

Tag specific activities, employees, and hours as WBSO-qualifying. Tags must correspond to the activities approved in your WBSO grant application.

### Activity codes

Each WBSO application covers one or more technical activities, each with a unique code from the RVO. Configure activity codes in Shillinq to link time registrations to the correct WBSO category.

### WBSO Export

The **WBSO Export** generates the periodic declaration (*mededeling*) required by the RVO — summarising qualifying hours and costs per activity, per employee, per period.

Deadlines:
- Monthly reporters: submit by the last day of the following month
- Quarterly reporters: submit within 4 weeks of quarter end

## R&D subsidies

Beyond WBSO, Shillinq tracks other R&D-related subsidies (EIA, MIA, Innovatiebox) in **Bookkeeping → R&D Subsidies**. Each grant has its own cost registration and reporting requirements.

### Innovatiebox

The **Innovatiebox** (innovation box) applies a reduced VPB rate (9 % vs. the standard 25.8 %) to profits from qualifying intellectual property. Shillinq tracks:
- IP assets eligible for the innovatiebox
- The qualifying profit allocation per IP asset
- The tax saving vs. standard rate

## Tarieven & beheer

**Tarieven** is a configurable rate table used across billing calculations. **Beheer** pages cover:
- **Rate cards** — billable rates by role and period
- **Rate schedules** — future planned rate changes
- **Rate audit trail** — history of rate changes

## Related

- [Bookkeeping dimensions](../bookkeeping/dimensions.md) — cost centres and projects link to people & projects
- [Tax (BTW/VAT)](../tax/btw-vat.md) — WBSO hours may feed into ZZP-aftrek and IB calculations
