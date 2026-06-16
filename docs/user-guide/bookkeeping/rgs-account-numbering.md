---
sidebar_position: 2
title: RGS account numbering
description: How the Dutch Referentie Grootboekschema (RGS) works and how to pick account numbers for your chart of accounts in Shillinq.
---

# RGS account numbering

Shillinq's chart of accounts follows the **RGS** — *Referentie
Grootboekschema* — the Dutch national standard for account numbering. Using
RGS makes your books compatible with Dutch tax software, your accountant's
tools, and the CBS statistical submissions that many Dutch organisations must
file.

You are free to deviate from RGS, but the built-in reports, VAT declarations,
and IV3/SiSa exports assume RGS-compliant numbers. If you are starting fresh,
start with RGS.

## The RGS tree

RGS organises accounts in a six-digit hierarchy. The first digit is the
**balance sheet class**:

| Digit | Balance sheet class | Examples |
|-------|---------------------|---------|
| `0` | Capital assets / intangibles | Land, buildings, patents |
| `1` | Current assets (excluding receivables) | Cash, prepayments |
| `2` | Tangible fixed assets | Equipment, vehicles |
| `3` | Inventory | Goods for resale, raw materials |
| `4` | Payables and long-term liabilities | Long-term loans, provisions |
| `5` | Short-term liabilities | Accounts payable, VAT payable |
| `6` | Revenue | Sales, interest income |
| `7` | Direct costs (cost of goods sold) | Materials, direct labour |
| `8` | Indirect operating expenses | Rent, salaries, marketing |
| `9` | Financial items and results | Profit/loss, tax on result |

The remaining five digits add sub-classification. In practice you need the
**first four digits** for standard bookkeeping:

```
8 0 1 0 [00]
│ │ │ │
│ │ │ └── Sub-category (often 0 = default)
│ │ └──── Category (0 = consulting/professional services)
│ └────── Group (0 = operating revenue)
└──────── Class (8 = indirect costs in some schemas;
                 6/7/8 vary by RGS version — follow RGS 3.4)
```

## The accounts you definitely need

Start with these before you add anything else:

### Bank and cash (class 1)

| Number | Name | Use |
|--------|------|-----|
| `10000` | Kas | Physical cash (petty cash) |
| `10100` | Bank — main account | Your primary business bank account |
| `10110` | Bank — payroll account | Separate payroll bank if applicable |

### Accounts receivable (class 1)

| Number | Name | Use |
|--------|------|-----|
| `13000` | Debiteuren | All open customer invoices (AR control account) |

Shillinq uses `13000` as the AR control account. Every time you create an
invoice, one side of the journal entry posts to `13000`. Do not post individual
invoices directly to this account; Shillinq manages it.

### Accounts payable (class 5)

| Number | Name | Use |
|--------|------|-----|
| `50000` | Crediteuren | All open supplier bills (AP control account) |

Shillinq uses `50000` as the AP control account. Every bill you record posts
one side to `50000`. Same rule: do not post here manually.

### Revenue (class 6 or 8, check your RGS version)

| Number | Name | Use |
|--------|------|-----|
| `80000` | Omzet — dienstverlening | Revenue from services |
| `80010` | Omzet — producten | Revenue from products |
| `84000` | Omzet — huur | Revenue from renting assets |

### Expenses (class 7 or 8)

| Number | Name | Use |
|--------|------|-----|
| `74000` | Inkoopkosten | Cost of purchased goods |
| `83000` | Personeelskosten | Salaries and social charges |
| `83500` | Huisvesting | Office rent and facilities |
| `83600` | ICT-kosten | Software subscriptions, IT |
| `83700` | Marketing | Advertising and promotion |
| `83900` | Overige bedrijfskosten | Other operational expenses |

### VAT (class 5)

| Number | Name | Use |
|--------|------|-----|
| `51000` | Te betalen BTW (hoog) | 21 % VAT payable to the Tax Authority |
| `51010` | Te betalen BTW (laag) | 9 % VAT payable |
| `51020` | Voorbelasting BTW | Input VAT you can reclaim |

## How to pick a number for a new account

1. Decide which **class** the account belongs to (revenue, expense, asset, liability).
2. Look up the official RGS 3.4 mapping at
   [rgs.taxonomie.nl](https://rgs.taxonomie.nl) for the closest match.
3. Use the first four or six digits from that mapping. If you need a custom
   sub-account, extend with two digits of your own (e.g. `80000` → `800010`
   for a specific service line).
4. Leave gaps between numbers so you can insert accounts later (e.g. `80000`,
   `80010`, `80020` rather than `80000`, `80001`, `80002`).

## Importing a standard RGS chart

Shillinq ships with a standard RGS 3.4 import package. In the admin
settings → Chart of Accounts → *Import RGS 3.4* you can load the full ~900
account taxonomy and then delete accounts you don't need. This is faster than
building from scratch.

See [Setting up your chart of accounts](../../guides/setup-chart-of-accounts.md)
for the full walkthrough.
