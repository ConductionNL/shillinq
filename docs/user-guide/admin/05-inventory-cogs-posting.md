---
sidebar_position: 5
title: Configure inventory GL posting
description: Map your stock-movement events (sale dispatch, goods receipt, count variance) to COGS, Inventory Asset, GR/IR clearing and Inventory Adjustment accounts so every move auto-posts a balanced GLTransaction.
---

# Configure inventory GL posting

When Shillinq runs perpetual inventory the lifecycle on `InventoryValuation` materialises one balanced `GLTransaction` per stock movement: COGS on every sale dispatch, Inventory Asset on every goods receipt, and an Inventory Adjustment on every count variance. The account routing is per-administration configuration held in the `InventoryGLConfig` register — once set up correctly, no manual COGS journalling is ever required.

## Goal

By the end the administration has an active `InventoryGLConfig` record pointing at four `Account` records (COGS, Inventory Asset, GR/IR clearing, Inventory Adjustment), and the next sale / receipt / count will auto-post a balanced GL entry surfaced under **Voorraad > Posting Historie**.

## Prerequisites

- A configured **Administration** with its **Chart of Accounts** loaded (see [Set up your chart of accounts](./01-chart-of-accounts.md)). The four target account numbers — Dutch SMB defaults `7000` Kostprijs omzet, `1400` Voorraden, `1800` GR/IR clearing, `7100` Voorraadmutaties — MUST exist in that chart, otherwise the FK validation will reject the config on save.
- The **OpenRegister** app installed with the Shillinq register imported (the repair step seeds a default `InventoryGLConfig` if `administration_id` is configured).
- The **inventory-valuation-fifo-avg** capability merged (this provides the `InventoryValuation.unitCost` value that drives the posting amount). Without `unitCost` the lifecycle skips with a `unitCost_missing` warning — no zero-cost GL entries are ever written.

## Walk-through

### 1. Open Posting Configuratie

Go to **Voorraad > Posting Configuratie** in the Shillinq menu. The index page lists one `InventoryGLConfig` row per administration with columns:

- **Administration**
- **COGS Account** — debited on sale/dispatch (Dr COGS)
- **Inventory Asset Account** — debited on receipt / credited on COGS posting
- **GR/IR Clearing Account** — credited on receipt; subsequently debited by the AP invoice posting
- **Inventory Adjustment Account** — debited/credited on count variance
- **Active** — when false, posting is paused (no GL entry, structured warning `posting_disabled` is logged)

If the repair step already seeded the defaults you will see one row pointing at `7000 / 1400 / 1800 / 7100`. Otherwise click **+ Add** and fill the four account numbers.

### 2. Verify the FK invariant

Saving the config invokes `InventoryPostingGuard::accountExists`. The save is rejected with `account <N> does not exist in administration` when any account number does not resolve to an existing `Account` record inside the **same** administration. Cross-administration FK leaks are blocked too. Adjust the chart of accounts or the FK before re-saving.

### 3. Confirm the three posting events

Once the config is active, every quantity-changing event on `InventoryValuation` carrying the matching `postingEvent` will materialise a `GLTransaction`:

| `postingEvent` | Debit | Credit | journalCode |
|---|---|---|---|
| `saleDispatch` | COGS Account (`7000`) | Inventory Asset Account (`1400`) | `inkoop` |
| `goodsReceipt` | Inventory Asset Account (`1400`) | GR/IR Clearing Account (`1800`) | `inkoop` |
| `countVariance` (positive) | Inventory Asset Account (`1400`) | Inventory Adjustment Account (`7100`) | `memo` |
| `countVariance` (negative) | Inventory Adjustment Account (`7100`) | Inventory Asset Account (`1400`) | `memo` |

Amount per side = `|delta_quantity| × unitCost`. The transaction carries `subLedgerType: "inventory"` and `subLedgerRef: <InventoryValuation UUID>`, and the source `InventoryValuation.glTransactionId` is updated to the new transaction id so you can drill from the inventory row to the GL line and back.

### 4. Check the GR/IR two-step

A goods receipt posts to GR/IR clearing (`1800`), not to AP control. The matching AP invoice (per `bookkeeping-accounts-payable-core` REQ-AP-003) debits GR/IR clearing and credits AP control, netting the `1800` account to zero per receipt. Watch the GR/IR account balance under **Bookkeeping > Trial balance**: it should oscillate around zero in normal operation. A persistent non-zero balance means a receipt is unmatched, an AP invoice is unmatched, or the chart wiring is wrong.

### 5. Review the posting history

Open **Voorraad > Posting Historie**. The page lists every inventory `GLTransaction` (filtered server-side by `subLedgerType: inventory`) with columns Entry # / Description / Debit / Credit / Date / Inventory Valuation. Click a row to drill into the underlying `GLLine` rows and the originating `InventoryValuation`.

## Troubleshooting

- **`unitCost_missing` warnings flooding the log.** The inventory-valuation-fifo-avg lifecycle has not yet computed `unitCost` for that snapshot. Either run an inbound StockMove to seed the cost layer (FIFO) or trigger a moving-average recalculation. The valuation snapshot is marked `pendingCogs = true` so the operator UI can surface it; no GL entry is written until the cost lands.
- **`posting_disabled` warning.** `InventoryGLConfig.isActive` is `false`. Flip it back to true once the chart wiring is confirmed.
- **`config_missing` warning.** No `InventoryGLConfig` row exists for the administration. Re-run the repair step (`occ maintenance:repair`) or create the row manually under Posting Configuratie.
- **Save rejected with FK error.** One of the four account numbers does not resolve to an `Account` record in this administration. Either add the missing account to the chart of accounts or pick an existing one.
- **Zero-variance count does not produce a `GLTransaction`.** This is intentional per REQ-CG-004: a count that confirms book quantity yields no GL effect. The count audit trail still records the event with `variance: 0`.

## See also

- `openspec/changes/inventory-cogs-posting/specs/inventory-cogs-posting/spec.md` — the formal capability spec (REQ-CG-001 to REQ-CG-006).
- [Set up your chart of accounts](./01-chart-of-accounts.md) — required prerequisite for the four FK targets.
- ADR-022 (config-as-data) + ADR-031 (declarative lifecycle) — architecture rationale for holding the routing in `InventoryGLConfig` instead of an app-local PHP service.
