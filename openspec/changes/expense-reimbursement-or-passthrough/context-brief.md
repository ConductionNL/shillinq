---
status: draft
---

# Expense Reimbursement or Pass-through

## Placement & Information Architecture

**Placement type:** `DETAIL_TAB+ACTION` (compound — implement all of the following):

- **`DETAIL_TAB`** — Tab on the detail view of an existing object. NOT a standalone page — appears inside the parent record's detail surface (e.g. an extra tab on the existing detail header).
- **`ACTION`** — Action button or menu item on an existing surface. Implemented as a single button / context-menu entry that opens a modal/wizard or runs a backend operation — NOT a page.

**Lives at:** Inkoop / Onkosten → Pass-through tab + "Splits onkost door naar klant" action

**Rationale:** Two modes of one expense lifecycle.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Mark expense reimbursable (pay employee via SEPA) or pass-through (bill to client at cost + markup).

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 11/26 competitors
- **Dependencies:** expense-capture-core

## Competitor Evidence (from intelligence-db)

- adp-nl :: Onkostenkoppeling grootboek :: ADP koppeling onkostenworkflow naar grootboek
- bezala :: Reimbursement via SEPA :: SEPA payment file for employee reimbursement
- bigtime :: Reimbursement and pass-through :: Reimburse employee or pass through to client invoice
- exact-online-hrm :: Expense reimbursement to AP :: Exact onkostendeclaraties native AP-posting in grootboek
- loket :: Expense reimbursement to AP :: Loket onkostenvergoeding post to AP grootboek
- pivot-hr :: Expense reimbursement to AP :: Pivot onkostendeclaratie naar AP-grootboek
- replicon :: Reimbursement + pass-through :: Pay to employee or bill to client
- rippling :: Bill pay / corporate cards :: Rippling Bill Pay + corporate cards auto-reconcile with GL
- rippling :: Expense reimbursement to AP :: Rippling reimbursements post to GL as AP entries
- yuki :: Expense reimbursement (declaraties) :: Employee expense reimbursement workflow

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 10 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
