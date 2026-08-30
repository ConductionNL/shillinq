---
sidebar_position: 7
title: Vendors
description: Manage your supplier (vendor) master data in Shillinq, linked to Nextcloud Contacts.
---

# Vendors

The **Vendors** page shows all suppliers (*leveranciers*) you have worked with in Shillinq. Vendor records are not stored separately in Shillinq — they are **Nextcloud Contacts** enriched with financial details like payment terms and default GL accounts.

![Vendors index showing empty state](/screenshots/vendors.png)

## How vendor data works

When you create a bill and select a supplier, Shillinq:
1. Looks up the contact in Nextcloud Contacts.
2. Stores any Shillinq-specific settings (payment terms, default expense account, preferred currency) alongside the contact.
3. Uses the contact's name and address on all supplier-related views and reports.

This means you manage vendor contact details (name, address, IBAN) in Nextcloud Contacts, and vendor financial settings in Shillinq.

## Add a vendor

Vendors are created implicitly when you first use a contact in a bill. To pre-configure a vendor's financial settings before their first bill:

1. Go to **Bookkeeping → Vendors**.
2. Click **+ Add vendor**.
3. Search for and select a Nextcloud Contact.
4. Configure:
   - `paymentTerms` — default number of days to pay (e.g. 30)
   - `defaultGlAccount` — the expense account to pre-fill on new bills (e.g. `83600` for ICT costs)
   - `currency` — default currency for bills from this vendor
   - `vatNumber` — supplier's VAT number (shown on bills for cross-border verification)
   - `iban` — payment bank account (used for payment runs)
5. Save.

## Vendor balance

Click any vendor row to open their detail view. The **Balance** tab shows:
- Total bills received (lifetime)
- Currently open bills (unpaid amount)
- Average payment duration (how many days on average you pay this vendor)

## Payment runs

The **Vendor** detail also shows which open bills are eligible for the next payment run. See [Accounts Payable](../guides/import-bills.md) for the full bill workflow and payment run details.

## Related

- [Import a bill](../../guides/import-bills.md) — record a supplier invoice
- [Accounts Payable](../../guides/import-bills.md) — the full AP workflow
- [Bank Reconciliation](../../guides/import-bank-statements.md) — match payments to bills
