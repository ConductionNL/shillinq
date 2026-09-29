---
kind: code
depends_on: [ledger-posting-path]
---

# Proposal: banking-payment-run

## Summary

A bookkeeper who wants to pay this week's suppliers today types every
payment line of a `PaymentRun` by hand. This change lets shillinq propose the
run from the supplier invoices that are due, lets a bookkeeper block a single
invoice or a whole supplier from being paid, and lets each payment in a run
carry its own execution date. The SEPA file, the four-eyes approval and the
duplicate-payment control stay as they are.

## Motivation

Three rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`) share the payment run screen and its
export guard. The OpenSpec pass of 2026-09-27 decided `build` for all three.
This change covers all three.

**`bnk-sepa-batch`**, "Create a SEPA payment batch for the supplier invoices
due." Rated partial, built. The matrix evidence:
"lib/PaymentRun/PaymentRunExportService.php:120 renders pain.001.001.03 XML
+ CSV from PaymentRun.paymentLines (refuses lines without creditorIban) ...
no service proposes a run from due APTransactions/SupplierInvoices". The
note: "The SEPA file is real; the batch lines are typed by hand, not
selected from the invoices due." No demand row; all five competitors rate it
yes:

- exact-online: https://support.exactonline.com/community/s/article/All-All-HNO-Task-financial-bank-finbnk-processpaymentst?language=en_GB, "You process payments to generate a payment file, which you can send to your bank".
- moneybird: https://helpcenter.moneybird.nl/nl/articles/207944-facturen-betalen-via-betaalbatch, "Met een betaalbatch zet je meerdere betalingen tegelijk klaar en stuur je ze in één keer naar je bank".
- snelstart: https://kennisplein.snelstart.nl/snelstartpolaris/betaalopdrachten-maken, "in een betaalbestand verschijnen standaard je inkoopfacturen".
- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/betalen, "Elke betaalronde bestaat uit zes fases".
- odoo: https://www.odoo.com/documentation/19.0/applications/finance/accounting/payments/batch.html, select bills and generate "a single outgoing payment file".

**`bnk-payment-block`**, "Block a supplier or a single invoice from being
paid." Rated no, built state none. The matrix note: "APInvoice declares a
dispute state (posted->disputed ...) described as a payment hold, but posted
is only reached through post, which declares the unregistered action
materialise-gl-transaction and aborts; PaymentRun export
(lib/PaymentRun/PaymentRunExportService.php:122) checks only the run's own
state, and there is no supplier-level block." Tender demand:
https://www.tenderned.nl/aankondigingen/overzicht/416109. One competitor
rates it yes:

- odoo: odoo/odoo@19.0 `addons/account/models/account_move.py:55` payment_state `blocked`, `:6400` `action_toggle_block_payment`, and `:6110` "You cannot register payments for blocked invoices."

**`bnk-scheduled-payment`**, "Schedule a supplier payment to go out on a
chosen day." Rated partial, built: "PaymentRun.executionDate is written into
the pain.001 file as the requested execution date
(lib/PaymentRun/Generator/SepaPain001Generator.php:74)". The note: "Per
batch through the export file, not per payment in the bank." Demand from the
moneybird changelog https://www.moneybird.nl/changelog/slim-plannen-van-betalingen/.
Four competitors rate it yes:

- exact-online: https://support.exactonline.com/community/s/article/All-All-HNO-Task-financial-bank-fin-bnkpymt-editsplitpymntt?language=en_GB, "This is the date the payment will be made".
- moneybird: https://www.moneybird.nl/changelog/slim-plannen-van-betalingen/, "De betaling wordt automatisch uitgevoerd op de dag die jij kiest".
- snelstart: https://kennisplein.snelstart.nl/snelstartpolaris/direct-een-betaling-doen-vanuit-snelstart-polaris, "je kunt zelf de betaaldatum kiezen en inplannen".
- twinfield: https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/betalen, "Betaaldatum: selecteer de datum waarop de bank de betalingen dient uit te voeren".

## Affected Projects

- [ ] Project: `shillinq`: a payment run proposal service and its route, two block fields on two schemas, a per-line execution date, the export guard, the pain.001 generator, and the payment run and AP pages.

## Scope

### In Scope

- Making `APTransaction.issue` passable, because an AP transaction that cannot be issued is never due (see design.md, Context).
- A "Propose payment run" action that drafts a `PaymentRun` from the open `APTransaction` records due on or before a chosen date, for one administration and one debtor account.
- A payment block on one `APTransaction` and on one `Payee`, with a reason, set and released from their detail pages.
- Refusing to export, or to submit to a provider, a run with a blocked or disputed line.
- A requested execution date per payment line, written into the pain.001 file as one payment information block per date.

### Out of Scope

- Submitting a run to a payment provider. That is `fees-payments-and-the-contract-register` REQ-FPCR-004; this change only makes that path honour the block and the per-line date.
- The bank connection itself (PSD2, EBICS). That is integriq's (ADR-091) and the subject of `banking-connected-accounts`.
- Posting the invoice to the ledger. `ledger-posting-path` registers `materialise-gl-transaction`, which `APInvoice.post` declares.
- Proposing runs from `SupplierInvoice`. A supplier invoice becomes payable through `purchasing-supplier-invoice-intake`, which hands it to the AP sub-ledger.
- Paying on the chosen day through the bank without a file. A requested date in the file is what the bank honours.

## Approach

The proposal is a small imperative service, because it selects across two
schemas and writes a third; the block is two declarative fields; the refusal
extends the one guard the export transition can carry. Details and the
alternatives are in design.md.

## New Dependencies

None.

## Impact

- `lib/PaymentRun/PaymentRunProposalService.php` (new) and one route in `appinfo/routes.php`.
- `lib/Settings/register.d/bookkeeping-accounts-payable-core.json`: `paymentBlocked` and `paymentBlockReason` on `APTransaction` and `Payee`, `requestedExecutionDate` on `PaymentRun.paymentLines[]`.
- `lib/Lifecycle/PaymentRunDuplicateGuard.php`: one more refusal.
- `lib/PaymentRun/Generator/SepaPain001Generator.php`: one `PmtInf` per requested date.
- `lib/AppInfo/Application.php`: the `APTransaction.issue` guard registration.
- `src/manifest.d/bookkeeping-accounts-payable-core.json`: header actions on `PaymentRuns`, `APTransactionDetail` and `PayeeDetail`.

## Cross-Project Dependencies

- OpenRegister: the lifecycle engine and `ObjectService` as they stand on development. No change needed there.
- integriq: `live-payment-providers`, reached through REQ-FPCR-004 of the open change `fees-payments-and-the-contract-register`. This change adds no call to integriq.

## Risks

### Risk 1: A proposal pays an invoice twice
**Severity:** High. **Mitigation:** the proposal skips every invoice already on a run in state draft, approved or exported, and `PaymentRunDuplicateGuard` still refuses the export of a duplicate. Two controls, one read path.

### Risk 2: A block set after approval is ignored
**Severity:** High. **Mitigation:** the block is checked again at export, not only at proposal. A run approved on Monday and exported on Tuesday leaves out nothing silently: it is refused and names the blocked line.

### Risk 3: A bank rejects a file with several execution dates
**Severity:** Low. **Mitigation:** several `PmtInf` blocks in one pain.001.001.03 message is the standard shape; a run whose lines share one date still produces exactly one block, as today.

## Rollback Strategy

Remove the proposal route and the header actions. The added fields are
optional and stay unread; the guard's extra refusal can be reverted on its
own. Runs typed by hand keep working throughout.

## Open Questions

None.
