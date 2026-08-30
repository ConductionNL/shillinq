---
sidebar_position: 1
title: Inventory overview
description: Manage products, stock levels, barcodes, lot tracking, and inventory valuation in Shillinq.
---

# Inventory

The **Inventory** section of Shillinq tracks your physical stock — what products you hold, where they are, how many there are, and what they are worth. It integrates directly with the financial ledger so every inventory movement automatically generates an accounting entry.

![Inventory products list](/screenshots/inventory-products.png)

## Pages in this section

### Products

The **Products** list is your product master — every item you buy, sell, or manufacture. Each product record contains:

| Field | Purpose |
|-------|---------|
| SKU / barcode | Unique identifier for scanning and ordering |
| Description | Human-readable name |
| Unit of measure | Piece, kg, litre, hour, etc. |
| Cost method | FIFO, LIFO, or average cost |
| GL account (stock) | The balance sheet account for this product's inventory value |
| GL account (COGS) | The P&L account for cost-of-goods-sold when the item is sold |
| Reorder point | When to trigger a reorder |
| Lead time | Days between ordering and receiving |

### Stock levels

The **Stock levels** page shows real-time quantities per product. If you have multiple warehouses or locations, use **Stock by location** to see the breakdown per location.

### Stock by location

Shows stock quantities split across storage locations. Useful if you have a main warehouse plus a shop floor or bonded warehouse.

### Reserve stock

The **Reserve stock** page shows stock that has been committed to a sales order but not yet shipped. Reserved stock reduces available stock in real-time.

### Barcodes

Manage GS1 / EAN / QR codes assigned to products. Multiple barcodes can be assigned to one product (e.g., product barcode + packaging barcode).

### Lots & Batches

Track individual production lots or batches — required for food, pharma, and other regulated industries. Each lot carries a production date, expiry date, and the quantity still available from that lot.

### Expiry alerts

The **Expiry alerts** page lists lots that are approaching or past their expiry date. Configure how many days before expiry to trigger an alert in **Settings → Inventory → Expiry warning days**.

### Inventory valuation

Shows the book value of your entire inventory, calculated per product using your chosen cost method (FIFO, LIFO, or average). Use this to verify the inventory balance sheet account against the general ledger.

### Reorder rules

Automated reorder rules trigger purchase suggestions when stock falls below the reorder point. Each rule sets:
- Minimum stock level
- Order quantity (or weeks of supply to order)
- Preferred supplier
- Lead time

Triggered rules appear in **Purchasing & Inventory → Purchase orders** as draft orders for your approval.

### Low-stock alerts

The **Low-stock alerts** page lists products that are at or below their reorder point but haven't been reordered yet. Review and approve the pending purchase orders from here.

## Inventory and accounting

Every inventory movement (goods receipt, goods issue, revaluation) generates an automatic journal entry. The posting logic is configured in **Settings → Inventory → GL posting configuration**:

| Event | Debit | Credit |
|-------|-------|--------|
| Receive goods | Stock account (asset) | AP / GR clearing |
| Ship goods (sales) | COGS (expense) | Stock account (asset) |
| Return to supplier | AP / GR clearing | Stock account (asset) |
| Revaluation | Stock account or adjustment | Revaluation reserve |

## Related

- [Purchasing & Inventory](../purchasing/overview.md) — purchase orders, goods receipts, supplier invoices
- [Chart of accounts](../bookkeeping/chart-of-accounts.md) — configure stock and COGS GL accounts
- [Fixed assets](../bookkeeping/fixed-assets.md) — for equipment and long-term assets (not inventory)
