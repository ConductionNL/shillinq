---
sidebar_position: 5
title: Configure inventory GL posting
description: Set the two accounts a stock issue books its cost of goods sold to, and see what inventory posts to the ledger today.
---

# Configure inventory GL posting

Every stock issue books its cost of goods sold (COGS) to the ledger. You set the two accounts once, and no manual COGS journal is needed after that.

## What posts today

| Stock event | Booked to the ledger? | Debit | Credit |
|---|---|---|---|
| Sale dispatch (outbound stock move) | Yes, one transaction per move | COGS account | Inventory account |
| Goods receipt | Not yet | | |
| Count variance | Not yet | | |

The amount is the valuation cost of the move: FIFO lots or the moving average, whichever the product uses.

Goods receipts and count variances do not reach the ledger yet. Book them with a journal entry until they do.

## Prerequisites

- An administration with its chart of accounts loaded (see [Set up your chart of accounts](./01-chart-of-accounts.md)).
- A COGS account and an inventory account in that chart. The Dutch SMB chart has `5100` and `1300` for these.
- Products with a valuation method, so each move has a unit cost.

## Walk-through

### 1. Set the two accounts

An administrator sets them as app settings:

```bash
occ config:app:set shillinq cogs_account --value=5100
occ config:app:set shillinq inventory_account --value=1300
```

### 2. Dispatch stock

Post an outbound stock move, for example by dispatching a sales delivery. The move books one balanced transaction: debit the COGS account, credit the inventory account.

### 3. Check the ledger

Open **Bookkeeping > General ledger**. The transaction has journal code `COGS`, and its source reference is the stock move.

## Troubleshooting

- **A dispatch books nothing and the valuation shows `pendingCogs`.** One of the two accounts is not set. Set both, then set the valuation back to active.
- **A dispatch books nothing and there is no warning.** The move has no unit cost yet. Receive stock first, so the valuation has a cost layer.

## Posting configuration records

The **Voorraad > Posting Configuratie** page holds one routing record per administration, with four accounts. The save checks that each account exists in that administration. Nothing books through these records yet: the COGS booking above uses the two app settings.
