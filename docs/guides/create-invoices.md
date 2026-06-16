---
sidebar_position: 3
title: Create an invoice
description: Create and send a customer invoice (accounts receivable) in Shillinq, including UBL/Peppol e-invoicing for Dutch government clients.
---

# Create an invoice

An **invoice** is a document you send to a customer asking them to pay for
goods or services. In accounting terms it is an **accounts receivable (AR)**
transaction; in Dutch it is a *debiteur* or *verkoopfactuur*.

![Accounts receivable index — empty state showing New invoice button](\screenshots/accounts-receivable.png)

:::info Terminology reminder
Shillinq uses **invoice** for what you send, and **bill** for what you
receive. See [Bookkeeping foundations](../user-guide/bookkeeping/bookkeeping-foundations.md)
for the full breakdown.
:::

## Create an invoice

1. Open **Bookkeeping → Accounts receivable** (or use the **Create invoice**
   shortcut on the Financial overview dashboard).
2. Click **+ New invoice**.
3. Fill in the header:
   - `customer` — pick from your Nextcloud contacts list. The customer is
     always a Nextcloud contact; Shillinq never duplicates the contact record.
   - `invoiceNumber` — Shillinq auto-generates the next number in your
     configured sequence (e.g. F2026-001). You can override it.
   - `invoiceDate` — today's date by default.
   - `dueDate` — auto-calculated from your default payment terms (e.g. 30
     days after invoice date). Edit if needed.
   - `reference` — customer's purchase order number or other reference they
     want on the invoice (shown in their banking payment description).
   - `currency` — defaults to EUR.
4. Add invoice lines:
   - `description` — what you are invoicing for.
   - `quantity` and `unitPrice`.
   - `glAccount` — the revenue account from your chart of accounts (e.g.
     `80000` for professional services).
   - `vatCode` — the applicable VAT treatment (21 %, 9 %, 0 %, or exempt).
5. Verify totals.
6. Click **Save as draft**.

The invoice is in **Draft** status — not yet visible to the customer and not
yet posted to the ledger.

## Preview and send the invoice

1. From the invoice detail, click **Preview** to see the formatted PDF.
2. Verify the customer details, your company details, the invoice lines, VAT
   breakdown, and payment instructions (IBAN).
3. When satisfied, click **Send invoice**:
   - Shillinq generates a PDF and an **UBL 2.1** XML attachment (required for
     Dutch government clients under the DigiInkoop mandate).
   - Choose the delivery method:
     - **Email** — sends the PDF and UBL directly from Nextcloud Mail.
     - **Peppol** — routes the UBL invoice through the Peppol network (for
       customers with a Peppol address / OIN).
     - **Download** — download the PDF and UBL to send yourself.
4. Click **Send**. The invoice status changes to **Sent** and the journal
   entry is posted:

   | Account | Debit | Credit |
   |---------|-------|--------|
   | Debiteuren `13000` | invoice total | |
   | Revenue account (e.g. `80000`) | | net amount |
   | BTW te betalen `51000` | | VAT amount |

## Invoice numbering

Shillinq enforces sequential invoice numbering per fiscal year (required by
Dutch tax law). The format is configurable in **Settings → Invoice numbering**:

- Prefix: `F`, `INV`, or your own string.
- Year: `2026` (four-digit) or `26` (two-digit).
- Sequence: padded to a fixed width (e.g. `001`).

Example: `F2026-001`, `F2026-002`, …

:::caution
You cannot re-use or skip invoice numbers. If you delete a draft invoice, its
number is not reused — the next invoice gets the next number. This is the
correct behaviour under Dutch tax law.
:::

## Sending to Dutch government clients (Peppol / DigiInkoop)

Dutch central government and many municipalities require e-invoices in UBL 2.1
format via the Peppol network. Shillinq handles this automatically when you
configure Peppol in **Settings → E-invoicing**:

1. Enter your **Peppol sender ID** (your KvK number formatted as
   `0106:{kvknumber}`).
2. Set your **access point** credentials.
3. When sending an invoice to a government customer, select the
   **Peppol** delivery method and enter the customer's **OIN** (Organisatie
   Identificatie Nummer) as the Peppol receiver ID.

## Track payment status

After sending, the invoice appears in **Bookkeeping → Accounts receivable →
Open invoices**. The **Financial overview** dashboard widget **Open receivables**
shows the oldest unpaid invoices.

When the customer pays, the payment is matched during bank reconciliation. See
[Import your bank statement](import-bank-statements.md).

## Credit notes

To cancel or partially credit a sent invoice:

1. Open the invoice detail.
2. Click **Create credit note**.
3. Adjust the lines (remove, reduce quantity, or change price).
4. Save and send the credit note. Shillinq posts the reversing journal entry
   and links the credit note to the original invoice.

## Common issues

| Issue | Cause | Fix |
|-------|-------|-----|
| Customer missing from autocomplete | Not in Nextcloud Contacts | Add the customer to Nextcloud Contacts first |
| Invoice number gap | Deleted draft invoice | Normal — do not fill the gap (required by Dutch tax law) |
| Peppol delivery fails | Wrong OIN or misconfigured access point | Verify OIN in **Settings → E-invoicing** and check the access point credentials |
| VAT exempt customer (e.g. outside EU) | Incorrect VAT code | Set the line `vatCode` to `0%` or `exempt` and add the legal note in the invoice footer |
