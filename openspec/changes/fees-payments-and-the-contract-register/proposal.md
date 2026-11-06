---
kind: code
depends_on: [case-payment-requests, leges-at-intake]
---

# Proposal: fees-payments-and-the-contract-register

## Summary

Three money questions a case system is asked and shillinq cannot yet
answer. What does this case type cost, and does the counter charge the same
as the website. Somebody paid at the desk in cash, how is that recorded.
Which contract is this case being handled under, and what did it cost.

## Candidates and cluster

Cluster 55 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "Money and obligations:
leges, payments and the contract register". Owner shillinq, size M, no
cluster decision. Five candidates, one `must`, six passers of which two
driven and four documented. Proving system xxllnc-zaken.

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-intake-44 | must | no | the fee for a case type, from the legesverordening and per intake channel |
| C-intake-7 | should | no | a payment status set by hand when the money arrived another way |
| C-intake-38 | should, documented | partial | single payments and large SEPA batches through a provider |
| C-parties-and-contacts-1 | should | no | a contract with its own term and costs, linked to its party and its cases |
| C-deadlines-10 | should, documented | partial | contracts managed from the case system, alerting before renewal or lapse |

## The evidence, verbatim

Quoted from `procest/_round4/discovery/candidates.md`, best-evidence
column.

- **C-intake-44**, "must, the fee is a council decision published in a
  verordening, and reading it rather than typing it is the difference".
  Best evidence: "xxllnc-zaken: Case type > Webformulier, Tarieven
  (case-type-editor-anatomy.md)", with mozard documented beside it. dossiq:
  "no, lib/Settings/register.d/30-beschikking.json:108 feeAmount sits on
  one decision; no fee is administered per case type or per intake
  channel". Its note: "Both make the fee a property of the case type rather
  than a number in code".
- **C-intake-7**, "should, a pin payment at the balie has to be recorded
  against the case". Best evidence: "xxllnc-zaken: Payments
  (payments/spec.md)".
- **C-intake-38**, "should, the SEPA batch is the outgoing half (a subsidy
  paid out, a dwangsom refunded) and our payments row is entirely about the
  citizen paying in". Best evidence: "atabix: documented,
  /gestandaardiseerde-modules (Betalingen)". Documented, never counted in a
  driven tally (D21).
- **C-parties-and-contacts-1**, "should, contractbeheer sits next to inkoop
  in every gemeente and a contract with an expiry nobody watches is the most
  common avoidable overspend". Best evidence: "glpi: Contracts (Management,
  Contracts, front/contract_item.php, contractcost.php,
  ticket_contract.php)", with easy-redmine documented beside it. Its note:
  "Both hold the agreement a case is handled under as an object in its own
  right".
- **C-deadlines-10**, "should, a contract term is a deadline with an owner
  and a consequence, which is exactly what our section 8 models for cases
  and not for anything else". Best evidence: "decos-join: documented,
  /oplossingen/modules (Contractbeheer)". Documented, never counted in a
  driven tally (D21).

## What shillinq already ships, and does not rebuild

- **The contract record and its alert.** The CLM `Contract`
  (`lib/Settings/register.d/contract-lifecycle-management.json`) already
  carries `contractNumber`, `contractType`, `direction`,
  `counterpartyReference`, `startDate` and `endDate`, `renewalTerms`,
  `totalContractValue` and a lifecycle through `expiring` to `expired`.
  `compliance-deadline-calendar` REQ-CDC-005 already publishes contract
  renewal and opzegtermijn alerts by extending `ObligationTaskBridge`.
  `contracts-single-home` is resolving the schema name collision behind it.
  What is missing is the link to the cases raised under the contract, and
  reading the contract from the case app.
- **The SEPA file.** `payment-run-sepa-export` REQ-SEPA-001 already exports
  an approved `PaymentRun` as pain.001.001.03. What is missing is handing
  the run to a provider rather than a file to a bank.
- **The payment request.** `case-payment-requests` puts a `PaymentRequest`
  on a case and `leges-at-intake` raises one from a `feeSchedule`. This
  change extends the schedule, it does not open a second one.

## What shillinq builds

- **A fee that cites its source.** A `feeSchedule` entry references the
  legesverordening article it comes from, with the regulation's own
  identifier and the date it took effect, so the amount can be traced to a
  council decision rather than to whoever typed it.
- **A fee per intake channel.** The same case type may cost one amount on
  the website and another at the counter. The schedule holds an amount per
  channel, with a default that applies where no channel is named.
- **A manual settlement.** A payment request is settled by hand with the
  method the money arrived by, the actor who recorded it, a reference and a
  reason. It is an append, never an edit of the provider's own state.
- **A batch through a provider.** An approved payment run is submitted to a
  provider over integriq rather than exported as a file, and each line
  carries the provider's own result back.
- **A contract that knows its cases.** A contract links to the party it
  binds and to the cases raised under it, and rolls its costs up over those
  cases.
- **A contract readable from the case app.** A case names the contract it
  is handled under, and the case app reads the contract's term, its
  counterparty and its remaining value without holding a copy.

## How dossiq consumes it

There is no dossiq change for cluster 55 yet. dossiq needs one, and it
consumes rather than models:

- A case type declares that it carries a fee and which intake channels
  charge it. The amount stays in shillinq.
- A case names the contract it is handled under, and renders what shillinq
  answers.
- The case shows paid, awaiting payment or settled by hand, from the
  request shillinq holds. dossiq's `DwangsomPaymentCallbackController` stops
  being the only path.

## Affected projects

- [x] `shillinq`: this change.
- [ ] `dossiq`: the declarations and the rendering. Needs a change of its
      own.
- [ ] `integriq`: `live-payment-providers` gains a batch submission
      capability. Agreed with that lane, not specified here.
- [ ] `pipelinq`: owns the product a fee may point at, under ADR-107.
      Unchanged.

## Out of scope

- A second contract schema. This extends the CLM `Contract`, and
  `contracts-single-home` owns the collision with the IFRS-15 one.
- A second alerting mechanism. `compliance-deadline-calendar` already
  alerts, and this change adds no timers of its own.
