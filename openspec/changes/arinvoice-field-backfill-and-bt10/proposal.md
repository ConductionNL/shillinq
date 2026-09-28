---
kind: code
depends_on: [billing-inherited-defects]
---

# Proposal: arinvoice-field-backfill-and-bt10

## Summary

The follow-up to billing-inherited-defects (#1745), which declared
`recurringProfileId`, `billingPeriod`, `customerReference` and
`invoiceLines[].glAccount` on ARInvoice 0.16.0 and left three things out of scope.
This change back-fills those fields on invoices saved before 0.16.0 where they can
be derived, maps `customerReference` to UBL BT-10 (Buyer reference) in the
e-invoice, and gives `DunningRunService` headroom under phpmd's class length limit
by moving the letter and record composition into its own class.

## Motivation

Recorded in `sq-fees/LANE-LOG-f.md` and in the billing-inherited-defects proposal
("Out: mapping `customerReference` to UBL BT-10, back-filling invoices written
before this change") and design ("Invoices written before this change lost the
dropped values; not back-filled"):

1. Invoices saved before 0.16.0 have no provenance. The recurring double-billing
   guard (REQ-RIN-004) filters on `recurringProfileId` and `billingPeriod`, so for a
   period generated before the upgrade it finds nothing, and a manual regeneration
   of that period bills it again. Their lines book to no account.
2. `customerReference` is declared and written by the quick draft, but
   `ArInvoiceUblMapper` does not emit it, so the buyer's reference (a purchase
   order or cost centre number, which Dutch public buyers require) never reaches
   the e-invoice.
3. `DunningRunService` is 1,299 lines against phpmd's 1,300-line class limit: the
   next fix to the dunning service turns the quality gate red.

## Affected Projects

- shillinq only.

## Scope

In: a repair step that fills the four fields where the recurring profile, its lines
or the quick draft's create audit entry give them; `cbc:BuyerReference` in the
NLCIUS document; `DunningLetterComposer` taking the voluntary letter, the template
fallback and the DunningRun record out of `DunningRunService::executeStage()`.
Out: inventing values that cannot be derived, a data model change, and any other
reshaping of the dunning service.

## Approach

- `BackfillArInvoiceProvenance` (post-migration repair step, after
  InitializeSettings) reads every ARInvoice and RecurringInvoiceProfile once.
- A generated invoice is matched to its profile by the generator's own
  fingerprint: customer, administration, the invoice date the generator writes for
  that period (the invoice day clamped to the month), a period between the
  profile's start and its last generated period, and the profile's net amount.
  Exactly one profile must fit; two leave the invoice alone. The period is the
  invoice date's month; each line without an account gets the profile line's
  `revenueAccount` at the same position.
- A quick draft (`DRAFT-` number) is read from its create audit entry
  (`getLogs()`), where the reference and the line accounts may survive; the current
  `invoiceLines` and the older `lines` shape are both read. An unreadable trail
  skips that draft.
- A field that holds a value is never written; a rerun saves nothing.
- `ArInvoiceUblMapper` writes `cbc:BuyerReference` after
  `cbc:DocumentCurrencyCode` when `customerReference` is not blank.
- `DunningLetterComposer::prepare()` and `compose()` hold what `executeStage()` did
  inline; `executeStage()` keeps the pause guard, the dispatch and the save.

## New Dependencies

None.

## Impact

No schema change. The repair step writes existing, declared properties only.

## Cross-Project Dependencies

None.

## Risks

- A profile whose prices changed since an old invoice no longer fits it by amount,
  so that invoice stays without provenance. That is the safe side: a wrong profile
  would block a legitimate regeneration.
- The quick-draft audit entry only holds the dropped values if OpenRegister logged
  the payload before dropping them; where it did not, the draft is left as it is.

## Rollback Strategy

Revert the PR. The back-filled values are declared, nullable and correct; leaving
them in place after a revert is harmless.
