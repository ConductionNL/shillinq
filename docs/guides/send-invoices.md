---
sidebar_position: 4
title: Send invoices
description: Send sales invoices from Shillinq by email, Peppol or post, send a selection at once, bill one set of lines to several customers, and set each customer's payment term and delivery method.
---

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

# Send invoices

An issued invoice reaches the customer from Shillinq itself: by email with the
PDF attached, through Peppol, or as a letter you post. Each customer says how
they want their invoices, and every send is recorded on the invoice, so you can
always see how and when it went out.

## Set a customer's payment term and delivery method

Open **Customers**, open the customer and edit:

| Field | What it does |
| --- | --- |
| **Payment term (days)** | How many days after the invoice date an invoice for this customer is due. Default 30. |
| **Delivery method** | **email**, **peppol**, **post** or **none**. Default email. |
| **Invoice email** | The address invoices are mailed to. Empty uses the customer's email address. |

A new invoice takes its due date from the payment term: the quick draft, an
invoice generated from hours and costs, and an invoice batch all add the
customer's days to the invoice date. The customer list shows the term and the
delivery method as columns.

## Send one invoice

Open the invoice under **Sales invoices**. On an issued invoice:

- **Send invoice** sends it by the customer's delivery method.
- **Send by email** mails the PDF whatever the delivery method is.

What happens per delivery method:

| Delivery method | What Shillinq does |
| --- | --- |
| email | Mails the invoice PDF (`Factuur-<number>.pdf`) to the invoice email. |
| peppol | Sends the e-invoice to the customer's Peppol participant. Without a participant, you are offered **Send by email** instead. |
| post | Records the send; the letter is printed and posted by hand. |
| none | Records that the invoice is not sent from Shillinq. |

A draft cannot be sent: issue it first. When a mail is refused, the invoice
keeps no sent date and the error is shown, so you can correct the address and
try again. The line under the buttons tells you how the invoice went out.

## Send selected invoices

On **Sales invoices**, select the invoices and choose **Send selected**. Up to
200 invoices go out in one batch, in the background, each by its customer's
delivery method. An invoice that was already sent is skipped. You get a
notification when the batch is done, and the batch under **Invoice batches**
lists for every invoice whether it was sent, skipped (and why) or failed.

## Bill the same lines to several customers

For a charge that several customers pay, such as a maintenance contract:

1. On **Sales invoices**, open the actions menu and choose **New invoice batch**
   (also on **Invoice batches**).
2. Select the **Customers**, up to 200.
3. Enter the **Invoice date** and, only when every invoice must fall due on the
   same day, a **Due date**. Without one, each invoice is due by its customer's
   own payment term.
4. Add the **Lines**: description, quantity, price excluding VAT and VAT rate.
5. Leave **Issue the invoices** on to issue them at once, and switch on **Send
   them by each customer's delivery method** to send them too.
6. Choose **Create invoices**.

Example: one line *Onderhoudscontract oktober 2026* of EUR 250 excluding 21%
VAT, dated 1 October 2026, for customers with terms of 14, 30 and 21 days gives
three invoices of EUR 302.50, due on 15, 31 and 22 October.

The dialog then says how many invoices were created, and names every customer
for whom none was written, with the reason. If the connection drops before
the dialog answers, choose **Create invoices** again: the batch keeps its
reference, finds the invoices it already wrote and writes only the missing
ones.

## Recurring invoices

A recurring invoice profile whose delivery channel is email and that issues its
invoices automatically also sends each new invoice by email. A failed send
keeps the invoice; the error is on the invoice.

## See also

- [Create an invoice](./create-invoices.md)
- [Payment reminders](../user-guide/bookkeeping/payment-reminders.md)
