---
sidebar_position: 13
title: Dimensions (cost centres & projects)
description: Track financial results by cost centre, project, or kostendrager using Shillinq's dimension system.
---

# Dimensions — cost centres & projects

**Dimensions** let you track income and expenses across multiple analysis axes beyond the standard GL account. Assign a dimension to a journal line and Shillinq reports not just *what* was spent but *where* and *for what*.

## Cost centres

A **cost centre** (*kostenplaats*) is an organisational unit that incurs costs: a department, team, location, or service. Assign cost centres to expense lines when posting bills or journal entries.

Go to **Bookkeeping → Cost centres** to manage your cost centre hierarchy. Each centre can have a parent (for roll-up reporting) and a budget.

## Kostendragers

A **kostendrager** (*cost object*) is the activity or output that receives allocated costs. Where a cost centre asks "which department spent this?", a kostendrager asks "which product/service/project bears this cost?".

Typical use: you allocate shared overhead (rent, utilities, management salaries) from cost centres to kostendragers using **allocation rules**.

## Projects

**Projects** in Shillinq track revenue and costs for specific time-bounded activities — client projects, internal initiatives, or product launches. Each project can have:
- A budget (per cost category)
- Start and end dates
- Team members assigned
- Sub-tasks / project orders (*projectopdrachten*)

Go to **Bookkeeping → Projects** to create and manage projects. All bills, invoices, and journal lines can be tagged with a project for project P&L reporting.

## Cost projects

**Cost projects** are a simpler variant — just cost tracking without revenue or budgets. Use them for internal initiatives where you want to accumulate costs but don't invoice customers.

## Allocation rules

**Allocation rules** automatically distribute costs from one cost centre to others based on a chosen driver (headcount, square metres, revenue share, equal split). Shillinq runs the allocation rules at period-end and posts the resulting journal entries.

Go to **Bookkeeping → Allocation rules** to configure:
- Source cost centre (where costs accumulate)
- Destination cost centres (where costs are distributed)
- Driver (the basis for splitting)
- Frequency (monthly, quarterly)

## Related

- [General ledger](general-ledger.md) — view transactions filtered by dimension
- [Journals](journals.md) — post manual allocation entries
- [Balance sheet](balance-sheet.md) — dimensions feed into departmental P&L views
