# Tasks: fees-payments-and-the-contract-register

## 1. The fee and its source

- [x] 1.1 Add `legalBasis` (regulation identifier, article, effective date) to `feeSchedule` and refuse an entry without it.
- [x] 1.2 Carry the basis onto every request raised from the schedule.
- [ ] 1.3 Show the basis beside the amount in the administration screen.
- [x] 1.4 PHPUnit on the refusal and on the carry-over.

## 2. The fee per channel

- [x] 2.1 Replace the single amount with `amounts[{intakeChannel, amount, currency}]`, migrating existing entries to a default.
- [x] 2.2 Resolve a lookup by channel, then by default.
- [x] 2.3 Refuse a schedule with neither a matching channel nor a default.
- [x] 2.4 PHPUnit on all three paths, including the migration.

## 3. The manual settlement

- [x] 3.1 Add the `settlement` append to `PaymentRequest` with method, amount, reference, actor, time and reason.
- [x] 3.2 Derive the reported state from the provider state and the settlements, and report an overpayment explicitly.
- [x] 3.3 Gate the append behind the payment administration right.
- [x] 3.4 PHPUnit on the derivation, the overpayment case and the 403.

## 4. The provider submission

- [ ] 4.1 Agree the batch submission capability with the integriq lane and record the per line result shape. — DEFERRED: needs the integriq lane; the pain.001.001.03 export is untouched and still the shipped path.
- [ ] 4.2 Submit an approved run through `live-payment-providers` and store the provider identifier and status per line.
- [ ] 4.3 Refuse a second submission, naming the first.
- [ ] 4.4 Report refused lines with the provider's reason, leaving the rest accepted.
- [ ] 4.5 Keep the pain.001.001.03 export working, with a regression test.

## 5. The contract and its cases

- [x] 5.1 Add `linkedObjects` to `Contract`, after `contracts-single-home` lands.
- [x] 5.2 Compute `incurredCost` in a scheduled job and stamp it with the computation time.
- [x] 5.3 Recompute on unlink without touching the linked object.
- [x] 5.4 PHPUnit on the roll-up and on the unlink.

## 6. The contract leaf

- [x] 6.1 Expose term, counterparty, status and remaining value through the leaf.
- [x] 6.2 Return not found for a contract the reader may not see, with no partial fields.
- [x] 6.3 PHPUnit on the permission path, using a reader who must be refused.

## 7. Handover

- [ ] 7.1 Give the dossiq lane its half: a case type declaring a fee and its charged channels, a case naming its contract, and the payment state read from shillinq.
- [ ] 7.2 Confirm with the portaliq lane that the checkout still fits a channel-dependent amount.
- [ ] 7.3 Tick this change in `competitor-parity-2026-09/tasks.md` when it archives.
