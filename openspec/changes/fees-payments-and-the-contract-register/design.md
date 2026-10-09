# Design: fees-payments-and-the-contract-register

## Context

`case-payment-requests` puts a `PaymentRequest` on any object.
`leges-at-intake` adds `feeSchedule`, a fee per
`(targetApp, register, schema, typeProperty, typeValue)` with a validity
window and a `payAtIntake` rule. The CLM `Contract` holds a term, a
counterparty and a value, and `compliance-deadline-calendar` alerts on it.
Everything below extends one of those.

## Decisions

### The fee cites the verordening, it does not copy it

A `feeSchedule` entry gains `legalBasis`: the regulation identifier, the
article, and the date the article took effect. The amount stays in the
schedule, because that is what is charged, but a reader can follow it to
the council decision that set it.

Reading the amount out of the published regulation at runtime was
rejected. The regulations are published as prose, the parsing would be
per gemeente, and a failed parse would mean nobody can pay.

### The channel is a dimension of the schedule, not a second schedule

`amounts` becomes a list of `{intakeChannel, amount, currency}` with one
entry allowed to carry no channel, which is the default. A lookup takes
the channel's entry if there is one and the default otherwise. A schedule
with no default and no matching channel is a configuration error and is
refused at save, not at checkout.

xxllnc Zaken administers tarieven on the case type's web form, which is
the same shape read from the channel end.

### A manual settlement is an append

A hand-set payment is a `settlement` appended to the request: method
(`cash`, `pin`, `bank-transfer`, `waived`, `other`), amount, reference,
actor, timestamp, reason. The request's state is derived from the provider
state and the settlements together. The provider's own state is never
overwritten, so a later provider callback cannot silently contradict a
desk clerk, and a reconciliation can see both.

`waived` is in the list on purpose: a fee remitted on hardship grounds is
a decision somebody takes, and recording it as a settlement keeps it out of
the "unpaid" report where it would age forever.

### The provider submission sits behind integriq

Shillinq builds the run and asks integriq's `live-payment-providers` to
submit it. Each line comes back with the provider's own identifier and
status. Shillinq holds no provider credentials and speaks no provider
protocol, which is the same boundary `case-payment-requests` already takes
for a single payment.

The pain.001 export stays. A bank that wants a file still gets one, and the
provider path is the second route, not a replacement.

### A contract links to its cases by reference, and rolls up over them

`Contract` gains `linkedObjects`, a list of references into a case app's
register, and a computed `incurredCost` that sums the costs booked against
those objects. The case app holds the contract reference on its own side as
well, because a case is read far more often than a contract and a lookup
per case would be a join per row.

GLPI's `ticket_contract.php` is the same link read from the ticket end.

### Reading the contract from the case app is a leaf, not a copy

The case app reads term, counterparty and remaining value through the
existing leaf pattern. It stores the contract reference and nothing else,
so a renegotiated contract is right everywhere the moment it is saved.

## Risks

- **A fee changes and an open request is stale.** A request holds the
  amount and the schedule version it was raised from. A later schedule
  change never moves the amount on an open request.
- **A manual settlement and a provider capture both land.** The derived
  state names the overpayment rather than hiding it, and the reconciliation
  report lists them.
- **A contract roll-up over many cases is slow.** `incurredCost` is
  computed by a scheduled job and stamped with the time it was computed,
  never on every read.

## Open questions

- Which register the intake channel vocabulary lives in. The spec requires
  a declared vocabulary and does not choose the owner.
- Whether a waiver needs its own approval route in decidiq. The spec
  records the waiver and leaves the approval question open.
