# Design: leges-at-intake

Kind: config over `case-payment-requests`. One schema, one journey step
type, one admin page.

## D1. `feeSchedule`

| property | type | notes |
|---|---|---|
| `targetApp`, `register`, `schema` | string | the object the fee applies to |
| `typeProperty`, `typeValue` | string | e.g. `caseType`, `bouwvergunning` |
| `productRef` | reference | pipelinq product when pipelinq is installed (ADR-107 decision 3) |
| `amount`, `currency` | number, string | read from the product when `productRef` is set; stored only when it is not |
| `revenueAccount` | reference to `Account` | overrides the `leges` mapping of `paymentRevenueAccounts` |
| `payAtIntake` | enum `required`, `optional`, `later` | what the journey does |
| `validFrom`, `validTo` | date | at most one schedule valid per tuple on a given day |

## D2. The journey step

Portaliq's `journey.steps[]` gains a step kind `payment` with
`{provider: "shillinq", requestType: "leges"}`. Shillinq registers the
step renderer through the shared runtime (ADR-109). At the step:

1. The journey has written the object (a `writes[]` step ran before; the
   step refuses to render otherwise).
2. Shillinq resolves the schedule for the object's type value and date. No
   schedule: the step completes as a no-op and records `noFee`.
3. `create` on `shillinq-payment-requests` for the object with
   `requestType = leges`, the amount, the portal subject as debtor.
4. `required`: render the checkout from `portal-payment-initiation`; the
   step completes on `authorized`. `optional`: render it with a "Pay later"
   link. `later`: skip the checkout; the receipt message carries the link.

The `journeyRun` records the request id, so a resumed journey finds its
request instead of raising a second one (uniqueness in REQ-SOPR-001).

## D3. The desk

`shillinq-payment-requests`'s `list` on a type object (host `caseType`)
returns the schedule entry as `fee`. The panel on the created case shows
"Raise leges request (€ 245,00)" as a one-click `create`.

## D4. Admin page

A manifest index page over `feeSchedule` under shillinq's settings, with
the revenue account's BbvTaakveld as a read-only column, so the finance
officer sees where the leges land.

## Risks

- A schedule change between form start and checkout. The amount is read
  when the request is created, at the step, never at form start.
- pipelinq absent. The schedule holds the amount itself; when pipelinq is
  installed later the admin links the product and the stored amount is
  ignored.
