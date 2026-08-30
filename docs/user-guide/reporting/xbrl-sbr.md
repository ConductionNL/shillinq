---
sidebar_position: 1
title: XBRL, SBR & financial reporting
description: Prepare and submit SBR/XBRL financial statements, consolidated reports, and intercompany eliminations in Shillinq.
---

# XBRL, SBR & financial reporting

Shillinq can generate and submit financial statements in **XBRL** (eXtensible Business Reporting Language) format via the Dutch **SBR** (Standard Business Reporting) infrastructure. This covers annual accounts submission to the Chamber of Commerce (KvK), tax declarations to the Belastingdienst, and consolidated group reporting.

## XBRL taxonomies

**Taxonomies** define the XBRL schema — what tags exist and how they relate to each other. Shillinq ships with the standard Dutch taxonomies:

- **NT17 / NT18** — the annual taxonomy for commercial entities (annual accounts to KvK)
- **BD taxonomy** — the Belastingdienst taxonomy for corporate tax (VPB)
- **FinRep / CoRep** — for financial institutions

Go to **Bookkeeping → XBRL Taxonomies** to see installed taxonomies and their versions. Update taxonomies annually when the Dutch Taxonomy Project (NTP) releases new versions.

## XBRL mapping validation

The **Mapping validation** page checks that every GL account in your chart of accounts is mapped to exactly one XBRL tag. Unmapped accounts will cause the XBRL filing to fail or produce incomplete data.

Run the validation before each filing and fix any flagged accounts in **Chart of accounts → [Account] → XBRL tag**.

## SBR documents

**SBR documents** are the submission packages — one document = one report filed with one authority. Each document contains:
- The XBRL instance document (the financial data)
- The submission metadata (period, entity, contact)
- The filing status

Go to **Bookkeeping → SBR Documents** to prepare and submit packages.

## SBR/XBRL filings

The **Filings** list shows all completed and pending SBR submissions, with their Digipoort confirmation codes and timestamps.

## Consolidations

The **Consolidations** module supports group-level financial statements when you have multiple legal entities:

- **Consolidations** — configure which entities are consolidated and their ownership percentages
- **Intercompany transactions** — identify transactions between group entities that must be eliminated
- **Elimination rules** — automatically reverse intercompany sales, loans, and dividends on consolidation
- **Consolidated report** — the consolidated balance sheet and P&L after eliminations

## Financial statements

The core financial statement pages (accessible from the Bookkeeping menu):

- [Balance sheet](../bookkeeping/balance-sheet.md) — assets, liabilities, and equity
- [Trial balance](../bookkeeping/trial-balance.md) — account-level check
- **Trial balance by account** — transaction-level detail per account for audit

## Related

- [Chart of accounts](../bookkeeping/chart-of-accounts.md) — XBRL mapping is done at account level
- [Tax (BTW/VAT)](../tax/btw-vat.md) — SBR is also used for BTW and ICP submissions
- [Year-end close](../bookkeeping/year-end.md) — financial statements are prepared during year-end close
