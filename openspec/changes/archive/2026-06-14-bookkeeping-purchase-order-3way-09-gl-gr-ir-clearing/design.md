# Design — Member 09: GR/IR Clearing & GL Posting (code)

## Context

`kind: code` member implementing the two-stage GR/IR GL posting. The GRN
member (04) fires the clearing trigger on accept; the match members (06-08)
fire settlement on approval. This member owns the posting logic itself.

## Decisions

### D6 — GR/IR clearing per IFRS goods-in-receipt

Carried from the giant's D6. Two balanced postings:

- **At GRN accept**: DR Inventory [PO line gl_account, e.g. 1200] / CR
  GR/IR Clearing [per ToleranceProfile, e.g. 2910], for the line amount.
- **At invoice match approval**: DR GR/IR Clearing [2910] / CR Accounts
  Payable [supplier liability, e.g. 4400] + VAT Payable [2100].

Both postings preserve cost_center + project_code from the PO line. The
GR/IR control account saldo SHALL reconcile to zero at period-end.

### Boundary — triggers vs logic

Members 04 (GRN accept) and 06-08 (match approval) fire the lifecycle
transitions; this member materialises the JournalEntry rows. Keeping the
posting logic in one member keeps the accounting double-entry invariant
reviewable in one place.

## Security (ADR-005)

- Postings are server-authoritative and balanced (debit == credit
  enforced); GL account codes come from configuration, not client input.

## Reuse
- T1 `JournalEntry` materialisation pattern (same trigger-based shape as
  AP core)
- `ToleranceProfile` (member 01) for the configurable GR/IR clearing account
