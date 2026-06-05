---
sidebar_position: 30
title: VAT / BTW filing
description: Prepare, review, submit and file Dutch VAT (BTW) returns in Shillinq — derived from the general ledger, with standard, KOR and reverse-charge regimes.
---

# VAT / BTW filing

Shillinq turns your general ledger into ready-to-file VAT returns. VAT is
derived **declaratively** from GL transactions on accounts marked
`vatApplicable`; you review the result, submit it, and (if a late invoice
shows up) rebase and re-submit. There is no second place to key VAT data, so
the return can never drift from the books.

This capability is part of the Tier 3 (Operations) bookkeeping layer and is
defined entirely as register metadata — three registers, a lifecycle, and a
pair of reconciliation aggregations — with no bespoke service code.

## The registers

| Register | What it holds |
|----------|---------------|
| **VAT Return** | One return per administration and fiscal period. Tracks total VAT collected, VAT paid, the balance, and the submission status. |
| **VAT Declaration** | A per-rate / per-type grouping within a return (collected, paid, reverse-charge), with bucket totals. |
| **VAT Line** | One line per GL source, with the taxable amount, rate, VAT amount, type, and a link back to the GL account for audit. |

The VAT amount on a line is always `taxableAmount × taxRate / 100`. You cannot
edit it directly — fix the GL entry and rebase the return, and the line is
re-derived.

## The workflow

The VAT Return moves through a four-state lifecycle:

```
draft → submitted → verified → filed
          │
          └── rebase ──► draft   (to include late postings)
```

1. **Create a return.** Open **Belastingen → BTW-aangiften (VAT)** and add a
   return. Choose the period (quarter, month or year), the year, and the
   regime (standard, KOR or reverse-charge). Shillinq derives the VAT lines
   and declarations from the GL for that period.
2. **Review the VAT lines.** Open the return. The detail page shows the
   summary (collected, paid, balance), the declarations grouped by rate/type,
   and the underlying lines with their GL account references. Check the totals.
3. **Submit.** Once the totals are correct, submit the return. The submission
   timestamp is recorded and the lines are locked.
4. **Verify / file.** When the tax authority acknowledges receipt the return
   moves to *verified*; once accepted as final it moves to *filed* and becomes
   read-only.
5. **Rebase if needed.** If a late invoice lands in an already-submitted
   period, rebase the return back to *draft*. The submission fields are
   cleared, the VAT lines are re-derived from the GL, and you submit again.
   This keeps the audit trail honest instead of silently re-opening a filed
   return.

## Regimes

- **Standard** — all rates apply per account: 21% (standard), 9% (reduced,
  e.g. food and books), 0% (export / zero-rated). One return can mix all three.
- **KOR (kleineondernemersregeling)** — small-business exemption. VAT collected
  and VAT paid are both 0 and the balance is 0; no VAT filing is required.
- **Reverse-charge (verleggingsregeling)** — for intra-EU purchases, imports,
  and cross-border services. The VAT is shifted to you as the recipient; the
  line is flagged `reverseChargeApplicable` and carries 0 VAT, because the
  liability sits with the operator under the reverse-charge rule.

## Reconciliation

The return totals are not typed in — they come from aggregations over the VAT
lines:

- `totalVATCollected` = sum of `vatAmount` over lines of type `collected`
- `totalVATPaid` = sum of `vatAmount` over lines of type `paid` and
  `reverse-charge`
- `vatBalance` = `totalVATPaid − totalVATCollected` (negative = amount owed,
  positive = refund)

## VAT reports

**Belastingen → BTW-rapportage (VAT)** shows the year at a glance: the running
VAT balance, the count of filed returns, and a table of every return with its
period, regime, collected/paid amounts, balance and status.

## FAQ

**The GL is wrong — what now?** Fix the journal entry, then rebase the return.
The VAT lines re-derive from the corrected GL; you never patch a VAT amount by
hand.

**A return is already filed but I found a missing invoice.** A filed return is
final with the authority. Contact the Belastingdienst — in the Netherlands a
correction is filed as a *suppletie*. Within Shillinq, record the correction
against the appropriate period.

**What about electronic filing to the Belastingdienst?** Preparing and
reviewing the return is in scope here. Direct electronic submission
(Digipoort / SBR) is handled by a separate integration capability.

> Screenshots of the index, detail and report pages are added once the
> capability is exercised against a live instance.
