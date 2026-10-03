---
sidebar_position: 9
title: Usage billing
description: Import what each customer used, rate it against a rate plan, and bill it from the invoice generator.
---

# Usage billing

Some customers pay for what they use: gigabytes of storage, API calls, hours of a machine. Shillinq keeps each period of use as a meter reading, prices it against a rate plan, and puts it on an invoice once.

## Rate plans

Open **Sales > Rate plans**. A plan names the resource it prices (for example `storage_gb`), its unit and how it rates:

- **Flat**: one price per unit. "Opslag per GB" at 12 cents a GB rates 250 GB at EUR 30.00.
- **Graduated**: tiers, each with its own price per unit. The first 1,000 calls at 5 cents, the next 9,000 at 3 cents, the rest at 2 cents.

Keep one plan per resource in an administration. An import then finds the plan from the resource on its own.

## Import readings

Open **Sales > Meter readings** and choose **Import readings**. Pick a CSV file whose first line names the columns:

```
customerId,resourceType,quantity,unit,periodStart,periodEnd
cust-hosting-noord,storage_gb,250,GB,2026-09-01,2026-09-30
```

`meterId` and `ratePlanId` are optional columns. Dates are written YYYY-MM-DD; a comma or a semicolon separates the columns.

Every valid row becomes an unrated reading. A row that cannot be one is refused and listed with its row number and the reason, for example "Row 3: The quantity is negative." The other rows still land, so fix the refused rows and import only those again.

## Rate readings

Select unrated readings and choose **Rate readings**, or open one reading and choose **Rate reading**. Each reading gets its amount before VAT and the status rated. A reading whose plan is missing stays unrated.

## Bill the usage

Open the invoice generator, choose **Usage** as the billing model, the customer and the invoice period. The generator lists the customer's rated readings whose period ends inside the invoice period and that are not on an invoice yet; all are selected. Clear the ones you do not want to bill now, then choose **Save as Draft**.

Each reading becomes a usage line on the draft invoice and moves to invoiced, with the invoice named on it. It is not offered again, and the server refuses it if it is sent again.

Next: post the draft from its page, or set up a [payment plan](payment-plans.md) for a customer who cannot pay at once.
