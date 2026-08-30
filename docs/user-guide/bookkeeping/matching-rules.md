---
sidebar_position: 10
title: Matching rules
description: Configure automatic matching rules in Shillinq to speed up bank reconciliation by recognising recurring transactions.
---

# Matching rules

**Matching rules** teach Shillinq how to automatically match or categorise specific bank transactions during reconciliation. Once a rule is configured, Shillinq applies it every time a matching transaction is imported — so recurring entries like bank charges, salary payments, and subscription fees are handled without manual intervention.

![Matching rules index showing empty state with Add Matching Rule button](/screenshots/matching-rules.png)

## How matching rules work

During bank reconciliation, Shillinq evaluates each unmatched transaction against all active rules in priority order. When a rule matches:
- **Match to open item** rules find the open bill or invoice that fits and mark it as matched.
- **Assign to GL account** rules post the transaction directly to a GL account (for items that are not bills or invoices, like bank charges or salary).

## Add a matching rule

1. Go to **Banking & Treasury → Matching rules**.
2. Click **+ Add matching rule**.
3. Configure the conditions (all must be true for the rule to trigger):

| Condition | Example |
|-----------|---------|
| `description contains` | "SALARIS", "STRIPE", "MOLLIE" |
| `amount equals` | `1250.00` |
| `amount between` | `4.50` and `5.50` |
| `counterparty IBAN` | `NL86INGB0002445588` |
| `counterparty name contains` | "Belastingdienst" |

4. Set the action when conditions match:

| Action | When to use |
|--------|-------------|
| **Assign to GL account** | The transaction is not tied to a specific bill/invoice (bank charges, salary, tax payments) |
| **Match to invoice by reference** | The payment description contains an invoice number |
| **Match to oldest open bill** | Payment from a known supplier, match to their oldest open bill |

5. Set **priority** — lower numbers run first. Put specific rules (exact amount + IBAN) before broad rules (name contains).
6. Click **Save**.

## Example rules

### Bank service charge (maandelijkse kosten)

| Field | Value |
|-------|-------|
| Description contains | `ABNAMRO REKENING` |
| Action | Assign to GL `83100` (Bank charges) |

### Salary payment

| Field | Value |
|-------|-------|
| Description contains | `SALARIS` |
| Counterparty IBAN | *(leave empty — matches any payroll IBAN)* |
| Action | Assign to GL `44000` (Salary costs) |

### VAT payment to Belastingdienst

| Field | Value |
|-------|-------|
| Counterparty name contains | `Belastingdienst` |
| Action | Assign to GL `51000` (BTW te betalen) |

## Test a rule

Before saving, click **Test rule** to run it against your last 30 imported transactions and see which ones it would match. Adjust the conditions until the matches are exactly what you expect.

## Related

- [Bank Reconciliation](../../guides/import-bank-statements.md) — the full reconciliation workflow
- [Bank connections](bank-connections.md) — import transactions automatically via PSD2
- [Chart of accounts](chart-of-accounts.md) — find the right GL account for rule actions
