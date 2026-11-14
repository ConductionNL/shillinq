---
sidebar_position: 1
title: Purchasing overview
description: Manage the full purchase-to-pay cycle in Shillinq — purchase orders, goods receipts, expense claims, and 3-way matching.
---

# Purchasing & Inventory

The **Purchasing** section covers the full **purchase-to-pay** (*inkoop-naar-betaling*) cycle: requesting goods or services, receiving them, matching the delivery to the supplier's invoice, and paying.

## Pages in this section

### Purchase orders (inkooporders)

A **purchase order (PO)** is a formal commitment to buy a specific quantity of goods or services from a supplier at an agreed price. Create POs to:
- Commit to a purchase before the goods arrive
- Get management approval before ordering
- Enable 3-way matching (PO → receipt → invoice)

1. Go to **Purchasing & Inventory → Purchase orders**.
2. Click **+ New purchase order**.
3. Select the supplier, add lines (product, quantity, agreed price), and save.
4. Once approved, the PO is sent to the supplier (via email or Peppol).

When goods arrive, create a **Goods receipt** against the PO. When the supplier invoice arrives, create a **Supplier invoice** against the PO. Shillinq matches all three automatically.

### Goods receipts (pakbonnen)

A **goods receipt** records when you physically receive the goods from a supplier. It:
- Increases inventory stock levels
- Posts to the GR/IR (goods received / invoice received) clearing account

1. Go to **Purchasing & Inventory → Goods receipts**.
2. Click **+ New goods receipt** or select from an open PO.
3. Enter the quantities actually received (may differ from ordered if partial delivery).
4. Save. Shillinq posts the stock and GR/IR entries.

### Supplier invoices

The **Supplier invoices** (under Purchasing) is a purchasing-centric view of bills. It shows the same data as [Accounts Payable](../bookkeeping/accounts-payable.md) but organised by purchase workflow:
- Bills linked to a PO (3-way match)
- Bills linked to a goods receipt
- Standalone bills (no PO)

#### Booking an invoice without an order

An invoice without a purchase order is booked in one step. Open it and press **Book without order**. Shillinq writes the invoice into Accounts Payable, books it and links the two. The ledger then shows each line on its expense account, the input VAT and the amount owed to the supplier.

Before you book, check three things on the invoice page:

- **Supplier.** A UBL invoice finds its supplier by KvK number, then by VAT number. When no supplier matches, the page shows the supplier as the invoice states it. Pick the supplier in the edit form.
- **Expense account.** Each line needs one. An empty line takes the supplier's default expense account.
- **Warnings.** Two warnings need a reason before you can book.

An invoice with a line linked to a purchase order cannot be booked this way. It goes through the 3-way match.

#### Same number as

Shillinq warns when a supplier invoice number was already used for the same supplier. This works however the invoice arrived. A UBL import of a known number is refused. A CSV import skips the row and lists it by number. A typed invoice is saved with the warning, and needs a reason before you book it.

#### IBAN differs from supplier record

Shillinq compares the IBAN on an invoice with the supplier's bank account and qualification. Spaces and case do not count. When they differ, the invoice page shows both. You can still book the invoice with a reason, but its Accounts Payable entry is blocked from payment. No payment run pays it until someone checks the new account and releases the block.

### 3-way matching

**3-way matching** verifies that what was ordered (PO), what was received (goods receipt), and what was invoiced (supplier invoice) all agree within tolerance. Shillinq flags discrepancies:

| Status | Meaning |
|--------|---------|
| Matched | All three documents agree within configured tolerance |
| Price variance | Invoice price differs from PO price |
| Quantity variance | Invoice quantity differs from received quantity |
| Exception | Requires manual review |

Matched items can be auto-approved for payment. Exceptions need manual resolution.

### Expense claims

**Expense claims** let employees submit reimbursable expenses (receipts for travel, meals, office supplies) through Shillinq. Each claim:
1. Is submitted with a PDF receipt attached.
2. Goes through an approval workflow.
3. Once approved, creates a bill against the employee as supplier.
4. Is included in the next payment run.

### Mileage log

For Dutch ZZP-ers and companies reimbursing car travel at the official rate (€0.23/km), the **Mileage log** records trips and calculates the reimbursement.

## Related

- [Import a bill](../../guides/import-bills.md) — the full supplier invoice workflow
- [Accounts Payable](../bookkeeping/accounts-payable.md) — AP aging, payment runs
- [Vendors](../bookkeeping/vendors.md) — manage supplier master data
- [Inventory](../inventory/overview.md) — stock levels and product management
