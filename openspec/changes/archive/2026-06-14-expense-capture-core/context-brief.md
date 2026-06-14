---
status: draft
---

# Expense Capture (receipt photo, mileage, per-diem)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Inkoop / Onkosten

**Rationale:** Expense page (capture flow).  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Receipt photo upload, manual entry, multi-currency, mileage, per-diem, project tag.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 17/26 competitors
- **Dependencies:** none

## Competitor Evidence (from intelligence-db)

- akaunting :: Bills (purchase invoices) :: Enter supplier bills; track AP
- akaunting :: Expense module :: Manual expense entry; receipt attach
- anuko-time-tracker :: Expense entry :: Basic expense entry; attach receipt
- anuko-time-tracker :: No OCR or modern UX :: Minimal expense; no OCR
- bezala :: Credit card statement import :: Import card statement; match to receipts
- bezala :: Mileage with route + auto-rates :: Address-to-address; auto kilometre rate
- bezala :: Mobile-first receipt capture :: iOS/Android photo capture; instant OCR
- bezala :: OCR receipt scanning :: Auto-extract date, amount, VAT, supplier
- bezala :: Per diem (daily allowance) calculation :: Country-specific per-diem rates (NL/FI)
- bezala :: Travel expense (verlet/reisbon) :: Travel form integration
- bigtime :: Expense capture with receipt OCR :: Mobile photo + OCR; markup; multi-currency
- clio :: Expense capture with markup :: Client cost (hard/soft) capture and markup
- clockify :: Expense entry with receipts :: Upload receipts, categorize, mark billable
- deltek-maconomy :: Expense capture with mobile receipt :: Mobile receipt + OCR; multi-currency; per-diem
- dext :: Audit trail and bank statement matching :: Bank statement reconciliation; audit log
- dext :: Auto-categorization by ML :: Learns supplier mapping; auto-codes expense category
- dext :: Expense report assembly :: Bundle receipts into expense reports
- dext :: Mileage tracking :: Distance-based reimbursement
- dext :: Multi-channel capture (mobile email scan) :: iOS/Android, email forwarding, drop folder, web upload
- dext :: Receipt OCR with ML :: High-accuracy receipt and invoice OCR; ML extracted line items
- dext :: Supplier insights :: Spend analytics by supplier
- dext :: Supplier rules engine :: Set rules per supplier (category, tax, project tag)
- everhour :: Expense tracking with markup :: Receipt upload; markup to client; reimbursable flag
- harvest :: Expense markup :: Add percentage markup before passing through to client invoice
- harvest :: Mileage tracking :: Distance-based reimbursement; per-mile/km rate

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 30 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
