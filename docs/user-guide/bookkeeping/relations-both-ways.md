---
sidebar_position: 10
title: Relations that are both customer and supplier
description: How to link a customer to the supplier record of the same organisation, and see the invoices sent and received together with who owes whom.
---

# Relations that are both customer and supplier

Some organisations buy from you and sell to you. Shillinq keeps them twice: once as a customer and once as a supplier. Link the two records, and Shillinq shows every invoice sent and received together, with the net position.

## Link the two records

Shillinq suggests a pair when the KvK number or the VAT number of a customer and a supplier are the same. It ignores spaces, dots and capitals, so `NL 8123.45.678.B01` and `nl812345678b01` match.

- Open **Reports > Relations both ways**. **Suggested links** lists each pair with the number that matched.
- Choose **Link** when the two records are the same organisation. Shillinq records which number matched and who confirmed it.
- Choose **Not the same** when they are not. Shillinq does not suggest that pair again.

You can also link by hand. Open the customer and choose **Link to a supplier**. Pick the supplier and choose **Link**. The same dialog removes a link.

A supplier links to one customer at most. Shillinq refuses a second customer for the same supplier and names the reason.

## See both sides

The customer page shows the linked relation for this year:

- **Open receivable**: what the relation still owes you.
- **Open payable**: what you still owe the relation.
- **Net position**: open receivable minus open payable. A negative number means you owe more than you are owed.
- **Invoices sent** and **Invoices received**, newest first.

A credit note counts negative on its side. Drafts, cancelled invoices and voided purchase invoices do not count.

On the supplier page, **Both sides** opens the linked customer.

For example, Reclamebureau Zuid B.V. has sales invoices of EUR 4,235.00 (paid) and EUR 1,210.00 (open), and a purchase invoice of EUR 2,662.00 (open). The customer page shows open receivable EUR 1,210.00, open payable EUR 2,662.00 and net position minus EUR 1,452.00.

## Who sees which side

Your role in the administration decides which side you see:

- an accounts receivable administrator sees the invoices sent, not the invoices received;
- an accounts payable administrator sees the invoices received, not the invoices sent;
- a payroll administrator sees neither;
- owners, controllers, bookkeepers, viewers and external accountants see both.

A side you may not see shows no invoices and no amounts, and the net position stays empty.

## The report

**Reports > Relations both ways** lists every linked relation for the chosen period: invoiced sales, invoiced purchases, open receivable, open payable and net position. Choose **Export CSV**, pick the period and choose **Download CSV** for one row per relation.
