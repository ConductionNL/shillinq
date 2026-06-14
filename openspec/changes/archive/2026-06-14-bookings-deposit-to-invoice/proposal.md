# Proposal: bookings-deposit-to-invoice

Apply collected deposit amounts as credits against the final booking invoice, reducing the amount due at checkout and reconciling deposit and invoice records in Shillinq.

## Summary

This change extends the booking module's deposit workflow (from `bookings-deposits`) to automatically consume the authorized deposit amount when a booking transitions to invoicing. When a booking moves from `confirmed` to `completed` (at checkout/fulfillment), a final `Invoice` is created in Shillinq. The system applies the previously authorized `DepositPayment` amount as a credit line item on the invoice, reducing the customer's outstanding balance. The customer pays only the difference (final invoice gross – deposit). Deposit-to-invoice reconciliation is bidirectional: the invoice links back to the deposit, enabling audit and full traceability in Shillinq's AR module.

Per ADR-031, all deposit-to-invoice logic is declarative (`x-openregister-lifecycle` and `x-openregister-calculations`); no `InvoiceService.php` coordination layer is authored.

**Depends on:** `bookings-deposits` (DepositPayment register + state machine), `add-shillinq-accounts-receivable-core` (Invoice entity + AR workflows).

## Motivation

18 of 21 market competitors resolve deposits at invoice time, applying the deposit as a credit and showing customers the net amount due. Without this feature, SMBs must manually track deposits and manually credit invoices in their accounting system, creating administrative overhead and reconciliation errors. Automating deposit-to-invoice consumption addresses the top operational friction point for SMBs managing bookings with upfront payments.

## Affected Projects

- [x] Project: booking — extends the `Order` (booking) lifecycle to trigger final `Invoice` creation; calculates and applies deposit credit as a line item.
- [x] Project: shillinq — consumes existing invoicing (`Invoice`, `InvoiceLine`) and AR workflows; no new register added.
- [ ] Project: nextcloud — no changes (OAuth scope, permission boundaries already in place per ADR-005).

## Scope

### In Scope

- Automatic `Invoice` creation in Shillinq when a booking transitions to `completed` state (fulfillment/checkout).
- Calculation of invoice line items: service amount, applicable tax (21% VAT for EUR), and a **negative line item** for the deposit credit (applied to reduce total due).
- Bidirectional linkage: `Invoice.sourceDocumentUri` references the `Order` (booking); `Order.invoiceId` references the created `Invoice`; `Invoice` line item tracks which `DepositPayment` was credited.
- Booking state machine: confirmed → completed (automatic invoice creation on transition).
- Invoice state: issued (awaiting payment of the net balance after deposit credit is applied).
- Refund reconciliation on cancellation: if a booking is cancelled after invoice is issued, a `CreditNote` is created in Shillinq to reverse the deposit credit (ensuring the invoice is properly settled or reversed).

### Out of Scope

- Partial invoicing (e.g., "50% at booking, 50% on completion") — invoicing is all-or-nothing at completion.
- Invoice amendment after issue (customers cannot adjust line items; tax compliance per Dutch law).
- Multi-currency final invoicing (all invoices in EUR per customer context; T5 multi-currency feature).
- Installment payment plans — final invoice is due in full (or per payment terms configured in AR).
- Automatic payment capture from deposit payment method (deposit is already captured; final invoice payment is separate).

## Risks

1. **Deposit-to-invoice timing mismatch** — if a booking is cancelled after invoice is issued but before final payment, the deposit must be properly credited back. Mitigation: spec includes cancellation workflow (REQ-DI-003).
2. **Invoice state consistency** — if DepositPayment record is lost or deleted, the invoice loses its credit line. Mitigation: spec requires bidirectional FK integrity (REQ-DI-001) and invoice state audit trail (REQ-DI-011).
3. **Tax compliance** — deposit credit is applied as a negative line item; must not affect VAT calculation (tax is on the net service amount, not the credit). Mitigation: spec defines tax calculation rules (REQ-DI-004).
4. **Reconciliation in Shillinq AR** — if deposit is authorized but invoice created without deposit link, AR reconciliation fails. Mitigation: spec enforces sourceDocumentUri tracing (REQ-DI-001).

## Rollback

If the booking-to-invoice integration fails, invoices cannot be created for completed bookings. Bookings remain in `completed` state, unlinked to Shillinq. Full rollback: remove Order → Invoice lifecycle action, delete any invoices created by the failed integration, revert Order state machine.

## Open Questions

1. Should deposit refunds be automatic on booking cancellation, or should cancellation leave the deposit in AR for manual refund processing? (Spec assumes automatic refund via CreditNote; review with ops/SMB).
2. Should the invoice show the deposit credit as a separate line item (visible to customer), or as a hidden GL adjustment? (Spec assumes visible line item for transparency; confirm with tax team).
3. If a booking is cancelled after invoice is issued but before payment, should the invoice be reversed entirely (CreditNote) or left as a record with the original deposit link noted? (Spec assumes CreditNote for clean AR aging; confirm with auditor).
