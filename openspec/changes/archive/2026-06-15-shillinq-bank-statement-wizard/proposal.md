# Proposal: shillinq-bank-statement-wizard

`kind: config` — a guided first-import wizard and an improved context-preserving
import flow launched from the Financial overview dashboard.

## Summary

The current **Import bank** button on the Financial overview navigates to the
bank reconciliation page, where the user must know:
1. Which file format their bank exports (CAMT.053 vs MT940)
2. Which bank account in Shillinq to map it to
3. How to handle unmatched transactions

For first-time users these questions cause friction. This change introduces:

1. **BankStatementWizard** — a 3-step `NcDialog` launched from the dashboard
   action button, guiding the user through file format selection, account
   mapping, and import confirmation.
2. **Return-to-dashboard** — after the wizard completes, the user is returned
   to the Financial overview (not stranded on the reconciliation page) with
   the payables and receivables widgets refreshed.

**Depends on:** `add-shillinq-bank-reconciliation` (CAMT.053 import endpoint
and reconciliation engine), `shillinq-financial-dashboard-actions` (Import
bank button, already implemented).

## Motivation

### User journey discovery

While writing `docs/guides/import-bank-statements.md`, the import flow was
traced for a first-time user:

1. User opens the Financial overview.
2. Clicks **Import bank** — lands on the bank reconciliation list.
3. Looks for an "Import" button — finds it, but it doesn't explain which
   format to use.
4. Downloads the wrong format from the bank (PDF instead of XML).
5. Tries again with the correct format.
6. Sees a list of unmatched transactions with no guidance on what "assign to
   account" means.

Steps 4 and 6 are the two most common first-import failure modes reported by
the pilot group.

### PSD2 / bank connection insight

During the user journey analysis, it emerged that many Shillinq users would
prefer **automatic** bank statement imports (via PSD2 bank API) over manual
file upload. The `add-shillinq-bank-connectors` spec covers this for
production use, but the wizard's **Step 0 — Connect bank** screen makes the
connection option discoverable from the import flow.

## Requirements

### REQ-BSW-001 — Entry point

The **Import bank** button in `FinancialDashboardActions.vue` opens the
wizard modal instead of navigating to the reconciliation page.

### REQ-BSW-002 — Step 1: File format selection

The wizard opens with a format picker:

```
 How does your bank export statements?

 ○ CAMT.053 XML   — Most Dutch banks (ING, Rabobank, ABN AMRO, SNS)
 ○ MT940          — Older SWIFT format (Triodos, some ING accounts)
 ○ CSV            — Custom export; map columns in the next step

 ──────────────────────────────────────────────────────────────────
 Or connect your bank directly and skip manual uploads:
 [Connect via PSD2 →]
```

Selecting a format shows format-specific instructions ("Export from ING
Internet Banking: Downloads → Account overview → Format: CAMT.053 → Date
range: last 30 days") before the file picker appears.

### REQ-BSW-003 — Step 2: Account mapping

After file upload, the wizard shows the bank account from the statement
(IBAN / account name) and asks the user to select the matching GL account
from their chart of accounts:

```
 Statement IBAN:   NL91ABNA0417164300
 Statement name:   Bedrijfsrekening

 Map to Shillinq account:  [ 10100 — Bank main account ▼ ]

 [ Next ]
```

For repeat imports from the same IBAN the mapping is remembered and this step
is skipped.

### REQ-BSW-004 — Step 3: Import summary

After mapping, the wizard processes the file and shows:

```
 Importing 47 transactions (€ 24,831.20) for period 2026-05-01 – 2026-05-31

 ✓ 31 automatically matched to open invoices / bills
 ⚠ 16 unmatched — require manual review

 [ Import and review matches → ]  [ Cancel ]
```

Clicking **Import and review matches** runs the import, closes the modal, and
navigates to the bank reconciliation page pre-filtered to the just-imported
statement. This is the only time in this flow that the user leaves the
dashboard.

### REQ-BSW-005 — After reconciliation: return to dashboard

The bank reconciliation page adds a **Back to Financial overview** breadcrumb
link when the import was initiated from the dashboard. After the user
completes reconciliation (posts the statement), clicking the breadcrumb returns
them to the Financial overview with both the payables and receivables widgets
refreshed.

### REQ-BSW-006 — IBAN → account memory

`localStorage` key `shillinq:bank-iban-map` stores `{ [iban]: glAccountId }`.
Populated on each successful import. Used to skip step 2 on repeat imports.
Expires after 1 year.

## Implementation approach

- `BankStatementWizard.vue` registered as `kind: 'modal'` in the shillinq
  registry.
- `FinancialDashboardActions.vue` data flag `showBankStatementWizard: true`
  replaces the current `this.$router.push('BankReconciliation')` call.
- The PSD2 connect link in step 1 routes to **Settings → Bank connections**
  (no new page).
- The "Back to Financial overview" breadcrumb in reconciliation is a
  sessionStorage flag set on wizard close.

## Out of scope

- Automatic reconciliation posting without review (intentionally manual for
  audit compliance)
- Multi-file batch import (future)
- CSV column mapping UI (first iteration: accept a well-defined header format;
  mapping UI in a later slice)
