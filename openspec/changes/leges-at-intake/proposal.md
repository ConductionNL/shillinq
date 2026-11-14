---
kind: config
depends_on: [case-payment-requests]
---

# Proposal: leges-at-intake

Competitor gap register, row 1.11 "Online payment during intake"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated no, owner shillinq, size M. Opened by the small-owner
lane of the OpenSpec phase.

## Why

A citizen who applies for a permit pays leges. In every Dutch case system
the payment is part of the application: the form ends on a checkout, and
the case starts as paid or as awaiting payment. dossiq has a flag,
`case.paymentIndication`, and nothing behind it (register note). Round 2
C06 deferred leges until portaliq carries intake; portaliq now does
(`move-portals-to-portaliq` archived 2026-09-09, ADR-085 journeys), so the
row is due.

`case-payment-requests` gives shillinq a request on any object. This
change is the rule that raises one at intake: which case types carry a
fee, how much, and what the journey does with the answer. It is
configuration over that primitive, not a second mechanism.

The best competitor in the register: xxllnc Zaken,
`backend/zaken/src/zsnl_domains/payments/entities/payment_integration.py`
(`_round2/compare/M1-functionality.md`).

## What changes

- A `feeSchedule` object: per `(targetApp, register, schema, typeProperty,
  typeValue)` a fee with `amount`, `currency`, `revenueAccount`,
  `validFrom`, `validTo` and `payAtIntake` (`required`, `optional`,
  `later`). ADR-107 decision 3 makes a fee a pipelinq product where
  pipelinq is installed; the schedule then references the product and
  reads the amount from it, and holds the amount itself only when pipelinq
  is absent.
- A journey step type `payment` for portaliq's journeys (ADR-085): when
  the journey writes the object, shillinq's `create` on
  `shillinq-payment-requests` raises a `leges` request on it, and the step
  renders the checkout through `portal-payment-initiation`. `required`
  blocks completion until `authorized`; `optional` and `later` complete
  with a `pending` request and the link in the receipt message.
- The internal intake: the data-provider leaf's `list` on a type object
  answers the fee for that type, so a desk clerk creating the case sees
  the amount and can raise the request from the panel in one click.
- The fee schedule admin page, under shillinq's settings, with the
  BbvTaakveld of the revenue account shown beside it (ADR-107 decision 1).

## How dossiq consumes it

The register's dossiq half: "a payment request on the intake form for case
types with a fee". dossiq declares nothing about money. Its portal journey
(ADR-085) adds the `payment` step after the write step for case types
that have a schedule entry; its desk intake places
`shillinq-payment-requests-panel` on the case, which `case-payment-requests`
already asks for. `case.paymentIndication` is derived from the request
state through the object event. One task in dossiq's umbrella
`competitor-parity-2026-09`, against `leaf-integrations` and the portal
provider.

## ADRs

- ADR-107: shillinq books; pipelinq originates the fee as a product where
  present; dossiq classifies its types and holds no amount.
- ADR-085: the checkout is a journey step type, rendered by `CnJourney`,
  not a per-app wizard.
- ADR-046 and ADR-108: the citizen-facing surface is portaliq's; shillinq
  contributes the step and the request.
- ADR-066: the fee lookup is the existing data-provider leaf's `list` on
  the type object.

## Existing specs it extends

`object-payment-requests` (this repo, the delta of `case-payment-requests`)
and `portal-payment-initiation` (the checkout).

## Out of scope

- Fee calculation from form answers (a fee per square metre). The
  schedule holds one amount per type value; a computed fee is a follow-up
  once a case type needs one.
- Refunds on withdrawal. The existing credit path.
