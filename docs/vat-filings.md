# BTW-aangifte (VAT filings)

Shillinq implements quarterly and monthly Dutch BTW-aangifte preparation
on top of the existing bookkeeping foundation. VAT is derived
declaratively from GL transactions marked `vatApplicable: true`; the
operator reviews and submits the prepared return.

## Overview

| Concept | Schema | Purpose |
|---|---|---|
| `VATReturn` | one per period + regime | Container with totals + lifecycle |
| `VATDeclaration` | one per (type, rate) within return | Per-rubriek bucket |
| `VATLine` | one per GL posting | Audit link to source transaction |

The return moves through `draft → submitted → verified → filed`. Lines
are immutable once submitted; rebase to draft to recompute.

## Workflow

### 1. Create a return

Navigate to **BTW-aangifte → Nieuw**. Pick:

- **Administration** — which administration this return belongs to.
- **Period** — quarter (default), month, or year.
- **Year + period number** — e.g. 2026 + 1 = Q1 2026.
- **Regime** — standard (21/9/0%), KOR (vrijgesteld), or
  reverse-charge (intra-EU).

The server validates that the period is not in the future and not
before the administration's start date, then derives the VAT lines from
GL: for every transaction in the period that posts to an account where
`vatApplicable = true`, a `VATLine` is created and grouped into a
`VATDeclaration` by `(type, rate)`. Totals roll up into the parent
`VATReturn` (`totalVATCollected`, `totalVATPaid`, `vatBalance`).

### 2. Review the declarations and lines

The detail page shows three sections:

- **Summary** — return number, period, regime, totals, balance.
- **Rubrieken (declarations)** — one row per `(type, rate)` bucket.
- **Regels (lines)** — every underlying GL posting with the audit
  link to its `glTransactionId`.

If you spot a posting that should not be VAT-bearing, adjust the GL
entry — the VAT line will be re-derived on the next rebase.

### 3. Submit

Click **Indienen** on a draft return. The server validates that the
totals are non-negative and stamps `submissionDate`. The return is now
read-only.

### 4. Rebase (re-open) if needed

If new GL postings land in the same period after a submit, click
**Heropenen** on the submitted return. The lifecycle transitions back
to `draft`, the existing VAT lines are dropped, and the derivation runs
again against the current GL.

## Regimes

### Standard

Applies the Dutch standard rate (21%) for high-rate sales, the reduced
rate (9%) for food, books, energy, etc., and 0% for export. Mixed
returns are grouped by rate.

### KOR (Kleineondernemersregeling)

Small-business exemption. `totalVATCollected` and `totalVATPaid` are
forced to 0; no rubrieken are produced. The detail page shows a note
explaining the KOR exemption.

### Reverse-charge

Intra-EU and import purchases / services. The VAT amount is captured on
the operator's side via `VATLine.type = 'reverse-charge'` with
`reverseChargeApplicable = true`. Reverse-charge VAT folds into
`totalVATPaid` because the operator self-accounts.

## FAQ

**Q: A GL entry is wrong — how do I fix the return?**
A: Adjust the GL entry, then rebase the return (draft → re-derive).

**Q: The return is already filed but I have new invoices.**
A: Contact the Belastingdienst — Shillinq does not allow editing filed
returns. The new postings will appear in the next period's return.

**Q: Where is the XML export?**
A: T3 prepares the return data; XML export to the Belastingdienst
gateway is a T4 / integration responsibility tracked separately.

## Retention

VAT returns and lines are retained for 7 years per article 52 of the
Algemene Wet inzake Rijksbelastingen. The schema declares this
explicitly via `x-openregister-lifecycle.retention`.

## References

- Spec: `openspec/changes/bookkeeping-vat-btw-filing/specs/bookkeeping-vat-btw-filing.md`
- Proposal: `openspec/changes/bookkeeping-vat-btw-filing/proposal.md`
- Design: `openspec/changes/bookkeeping-vat-btw-filing/design.md`
- Belastingdienst — Hoe vraag ik aangifte aan?:
  https://www.belastingdienst.nl
