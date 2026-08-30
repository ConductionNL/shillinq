---
sidebar_position: 8
title: Customers
description: Manage your customer (debtor) master data in Shillinq, linked to Nextcloud Contacts.
---

# Customers

The **Customers** page shows all customers (*debiteuren* / *klanten*) you have invoiced in Shillinq. Like vendors, customer records are **Nextcloud Contacts** enriched with financial details such as payment terms and Peppol addresses.

![Customers index showing empty state](/screenshots/customers.png)

## How customer data works

When you create an invoice and select a customer, Shillinq:
1. Looks up the contact in Nextcloud Contacts.
2. Stores Shillinq-specific settings (payment terms, Peppol ID, invoice number prefix) alongside the contact.
3. Uses the contact's name, address, and VAT number on generated invoices and credit notes.

Manage contact details (name, address) in Nextcloud Contacts. Manage financial settings (payment terms, e-invoicing setup) in Shillinq.

## Add a customer

Customers are created implicitly when you first invoice a contact. To pre-configure a customer's financial settings before their first invoice:

1. Go to **Bookkeeping → Customers**.
2. Click **+ Add customer**.
3. Search for and select a Nextcloud Contact.
4. Configure:
   - `paymentTerms` — default number of days to pay (e.g. 30)
   - `defaultGlAccount` — the revenue account to pre-fill on new invoices (e.g. `80000` for professional services)
   - `currency` — default currency for invoices to this customer
   - `vatNumber` — customer's VAT number (required for intra-EU zero-rate invoicing)
   - `peppolId` — Peppol receiver ID (for Dutch government and Peppol-registered customers)
   - `invoiceDelivery` — preferred delivery method: email, Peppol, or manual download
5. Save.

## Customer balance

Click any customer row to open their detail view. The **Balance** tab shows:
- Total invoices sent (lifetime)
- Currently outstanding invoices (open receivables)
- Average payment duration (how many days on average this customer pays)
- Oldest open invoice (helps you decide when to start a dunning process)

## Dunning (payment reminders)

When a customer is overdue, Shillinq can send payment reminders automatically. Configure the reminder schedule in **Settings → Dunning ladders** and assign a ladder to the customer on their detail page.

## Related

- [Create an invoice](../../guides/create-invoices.md) — send a customer invoice
- [Accounts Receivable](../../guides/create-invoices.md) — the full AR workflow
- [Bank Reconciliation](../../guides/import-bank-statements.md) — match received payments to invoices
