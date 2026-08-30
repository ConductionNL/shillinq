---
sidebar_position: 1
title: BTW / VAT declarations
description: Prepare, review, and submit Dutch BTW (VAT) declarations and related tax filings in Shillinq.
---

# BTW / VAT declarations

Shillinq collects VAT data from every bill and invoice you post, then aggregates it into the required Dutch tax filing formats. The **Belastingen** section covers quarterly BTW (VAT) declarations, intra-EU reporting (ICP), and corrections.

![BTW aangifte list showing quarterly declaration periods](/screenshots/btw-aangifte.png)

## Pages in this section

### BTW aangiften (VAT returns)

The **BTW aangiften** list shows one row per declaration period (quarter or month, depending on your tax authority assignment). Each row shows:

| Column | Meaning |
|--------|---------|
| Period | Q1/Q2/Q3/Q4 or the specific month |
| Sales BTW | Total output VAT collected on your invoices |
| Purchase BTW (voorbelasting) | Total input VAT paid on your bills (reclaimable) |
| Net to pay | Output VAT − input VAT. Negative = Belastingdienst owes you |
| Status | In progress / Ready / Filed / Paid |

Click a period to see the breakdown by VAT rate and to file or export.

### Filing a BTW declaration

1. Open the period.
2. Review the auto-calculated amounts:
   - **1a** Sales taxed at 21 %
   - **1b** Sales taxed at 9 %
   - **1c** Sales taxed at 0 % / exempt
   - **2** Intra-EU acquisitions (received from EU suppliers)
   - **4** Sales outside the EU
   - **5a** Total VAT on rows 1–4
   - **5b** Voorbelasting (input VAT from bills)
   - **5c** Net amount due or refundable
3. Correct any discrepancies (see BTW corrections below).
4. Click **Submit to Belastingdienst** to file via SBR/Digipoort.

### VAT by period

The **VAT by period** page provides a detailed breakdown of every invoice and bill that contributed VAT in a specific period. Use this to investigate unexpected differences or to support a VAT audit.

### ICP-opgaaf (intra-Community supplies)

The **ICP-opgaaf** lists sales to VAT-registered customers in other EU countries at the 0 % intra-EU rate. Dutch law requires a quarterly ICP report alongside the BTW declaration. Shillinq generates the ICP from invoices where:
- The customer's VAT number is an EU VAT number (format `CC...`)
- The invoice was zero-rated for intra-EU supply

Submit the ICP-opgaaf in the same month as the quarterly BTW declaration.

### BTW correcties (VAT corrections)

Use **BTW correcties** to correct VAT amounts from prior periods without reopening and re-filing those periods. Common scenarios:
- A credit note crosses a period boundary
- You discover a posting error from a previous quarter
- A supplier issues a revised invoice

Enter the correction amount (positive or negative) and the original period it belongs to. Shillinq includes the correction in the current period's filing.

### KOR (kleineondernemersregeling)

The **KOR** pages are relevant if your annual turnover is below €20,000 and you have opted into the Dutch small-business VAT exemption scheme. The KOR status page shows whether the exemption is active and when it expires or is revoked.

### Urenregistratie (hour tracking for ZZP deductions)

For sole traders (*ZZP-ers*), Shillinq tracks billable and non-billable hours. The accumulated hours feed into:
- **ZZP-aftrek** — the self-employed deduction (*zelfstandigenaftrek*)
- **IB-aangifte** — pre-populated income tax return (*inkomstenbelasting*)

## BTW rates in the Netherlands

| Rate | Dutch name | When |
|------|-----------|------|
| 21 % | Hoog tarief | Most goods and services |
| 9 % | Laag tarief | Food, medicine, books, accommodation |
| 0 % | Nultarief | Exports, intra-EU sales, certain financial services |
| Exempt | Vrijgesteld | Medical, education, insurance — no VAT recovery on input costs |

## Related

- [Chart of accounts](../bookkeeping/chart-of-accounts.md) — configure BTW control accounts
- [Import a bill](../../guides/import-bills.md) — how input VAT is recorded
- [Create an invoice](../../guides/create-invoices.md) — how output VAT is recorded
