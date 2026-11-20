---
sidebar_position: 12
title: E-invoices over Peppol
description: Send sales invoices over Peppol, follow their delivery status, handle a rejection, and review self-billed invoices your customers send you.
---

# E-invoices over Peppol

Shillinq sends a sales invoice as a UBL e-invoice over the Peppol network, follows what happens to it, and takes in the invoices your customers send you over Peppol. The Peppol connection itself is Integriq's: Shillinq hands the invoice to Integriq and reads back what Integriq reports. Without Integriq on the instance, Shillinq says so and offers email instead.

## Send an invoice over Peppol

1. Open an issued invoice under **Sales > Accounts Receivable**.
2. Choose **Send e-invoice**.

Shillinq checks the invoice, builds the UBL file and a PDF with that file inside it, and stores both in the administration's Files folder under `Shillinq/<administration>/e-invoices/<year>/`. It then hands the invoice to Integriq. The invoice shows delivery status **queued** only once that hand-off worked.

When the invoice cannot go over Peppol, the page tells you why and offers **Send by email**. The invoice stays at **not sent**. That happens when:

- Integriq is not installed on this instance: *Peppol sending is not available on this instance*;
- the customer has no Peppol participant id;
- the e-invoice files could not be stored.

## Follow the delivery status

Integriq reports each step back, and the invoice moves with it:

| Status | Meaning |
|---|---|
| Queued | Handed to Integriq, not yet at the access point. |
| Sent | The access point took it. The invoice now shows Integriq's transmission id. |
| Delivered | The customer's access point received it. |
| Failed | The access point could not send it. |
| Rejected | The customer refused it. The reason is on the invoice. |

## When a customer rejects an invoice

A rejected e-invoice reaches the people who handle the administration's receivables: everyone whose membership role is debiteurenadmin, boekhouder, controller or eigenaar gets a Nextcloud notification naming the invoice and the reason, which opens the invoice. Nextcloud can also mail it to them if they switched on email for notifications.

To see every rejected invoice, choose the **Rejected e-invoices** filter on **Accounts Receivable**.

## Self-billed invoices

Some customers draw up the invoice for what you delivered and send it to you, for example a processor that weighs and pays for the crop it takes in. This is called self-billing, and it needs an agreement with that customer (article 224 of the VAT Directive).

### Record the agreement

1. Open the customer under **Sales > Customers**.
2. Edit **Self-billing agreement**: the date it was agreed, the last day it applies (leave it empty for no end date) and, if you like, a link to the signed agreement.

### What happens when one arrives

When a self-billed invoice arrives over Peppol, Shillinq looks up the administration it was made out for and the customer who sent it. When the customer's agreement covers the invoice date, the invoice appears as a **draft** on **Accounts Receivable** with the customer's own invoice number, the lines and VAT from the document and the mention *Factuur uitgereikt door afnemer*. Nothing is booked yet. A second delivery of the same document adds nothing.

When Shillinq cannot match the administration or the customer, when the customer has no agreement or it does not cover the invoice date, or when the document cannot be read, no invoice is written. The document is listed under **Sales > Refused self-billed invoices** with the reason, so you can take it up with the customer.

Any other invoice that arrives over Peppol is a purchase invoice and goes to **Accounts Payable**.

### Review it

Open the draft and compare it with what you delivered.

- If it is right, issue it. Shillinq books it like any sales invoice. Issuing is refused when the agreement no longer covers the invoice date.
- If it is wrong, choose **Reject self-billed invoice** and give the reason. The invoice stays a draft, is not booked, and cannot be issued any more. Shillinq does not answer the customer over Peppol; tell them yourself.

## For administrators: invoices stuck at queued

Before this version, **Send e-invoice** marked invoices as queued without handing them to Integriq, so nothing was sent. After upgrading, list them and send them again:

```bash
occ shillinq:einvoice:requeue
```

The command lists every invoice at queued that was never handed over, and asks before it sends anything. Answer yes, or run it with `--yes`, to send each again through Integriq. An invoice that cannot be sent stays at queued and is named in the output; send it by email instead.
