---
sidebar_position: 2
title: Country-by-country report and minimum tax
description: Assess whether your group is in scope, read the country-by-country figures from the books, and prepare the minimum tax (Pillar Two) return in Shillinq.
---

# Country-by-country report and minimum tax

A group with consolidated revenue of EUR 750 million or more files a
country-by-country (CbC) report, and from the second such year in four also
a GloBE information return for the minimum tax (Pillar Two). Shillinq keeps
the group's entities, reads their figures from the books it holds, sums them
per jurisdiction and runs the safe harbour tests. Three pages under
**Taxes** do this work.

## Group entities

Every legal entity of the group is a **group entity**. Give each one its tax
residency, its tax identification number (TIN), its address and its business
activities, as the CbC report lists them. An entity whose books are kept in
Shillinq names its **administration**; for a Dutch entity the TIN defaults to
that administration's RSIN. An entity without a TIN gets `NOTIN` and a
reason.

List under **counterparty records** the customer and supplier records that
stand for the entity in the other administrations of the group. A sale to
such a customer counts as revenue from a related party.

### Assess scope

On the ultimate parent, **Assess scope** asks for the fiscal year. Shillinq
reads the consolidated revenue of the years before from the group's
consolidated income statements (or the amounts you enter for years without
one) and stores the outcome:

- the CbC report is due when the revenue of the year before was EUR 750 million or more, 12 months after the end of the year;
- the minimum tax applies when the revenue was EUR 750 million or more in at least two of the four years before; the GloBE information return is due 15 months after the end of the year (18 in the first year), the top-up tax return 17 months after (20 in the first year).

A weekly job assesses the current year by itself once last year's
consolidated statement exists, and tells the group's responsible owner when
the outcome changes.

Enter the average and closing exchange rate per currency on the assessment
when an administration keeps its books in another currency than the
ultimate parent's.

### Read figures from the books

On an entity with an administration, **Read figures from the books** reads
the year's posted ledger lines by the **country-by-country category** of each
account (revenue, distributions from group entities, current and deferred
income tax, income tax payable, stated capital, accumulated earnings,
tangible assets, eligible payroll, or none). Give every account with a
movement a category first: an account without one is listed as unmapped and
fails the report's checks. The number of employees comes from humaniq where
it is installed.

## Country-by-country reports

**Prepare from the books** reads the figures of every entity of the group
whose books are kept here and writes one summary per jurisdiction. A summary
you marked reconciled or locked is left as it is. The checks tell you what
still blocks the report: an entity without figures, an unmapped account, a
missing TIN, a jurisdiction without a business activity, a total that does
not add up, or an unexplained difference with the consolidated statements of
more than EUR 1 million. Figures entered by hand give a warning.

**Mark submitted** is refused until a valid file made from the current
figures exists and no blocking check fails.

## Minimum tax returns

**Run safe harbour tests** tests every jurisdiction against the three
transitional CbC safe harbours, for fiscal years beginning on or before
31 December 2026 and ending before 1 July 2028:

- de minimis: revenue below EUR 10 million and profit before tax below EUR 1 million;
- simplified effective tax rate: at least 15% for years beginning in 2023 and 2024, 16% in 2025 and 17% in 2026;
- routine profits: profit before tax not above the substance-based income exclusion (for 2026: 9.2% of eligible payroll and 7.2% of tangible assets).

A jurisdiction that passes none gets a draft jurisdiction computation. The
GloBE adjustments are entered on that computation, not derived. For the
Netherlands, a computation below 15% gives the Dutch QDMTT amount and its
return, due on the top-up tax return deadline.

**Approve** is refused until a valid file made from the current records
exists and no blocking check fails.

## What is not covered yet

- The XML files themselves: the OECD XSD packages are not bundled yet, so **Generate XML** is not available and no file can be validated.
- The GloBE information return's figures per constituent entity: the records hold them per jurisdiction, so a computed jurisdiction lists what an adviser has to add.
- Transport through Digipoort, the notification of the reporting entity, and the top-up tax return file.
- The comparison of tangible assets with the fixed asset register.
