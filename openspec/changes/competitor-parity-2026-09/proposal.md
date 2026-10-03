---
kind: umbrella
depends_on: []
---

# Proposal: competitor-parity-2026-09

## Summary

This is the shillinq half of the dossiq competitor parity programme. Two
sources of record sit behind it, both in ConductionNL/market-intelligence:
the gap register at `procest/_gaps/` (written 2026-09-13) and the round 4
discovery sweep at `procest/_round4/discovery/` (36 systems read, 636
candidates, 71 capability clusters, written 2026-09-14).

Ruben's ownership rule governs the split. dossiq reaches full
comparability with the competition, and logic that belongs to another app
is specified in that app. dossiq then consumes it. Money is shillinq's, so
the fee, the payment and the contract are specified here.

Nothing here is implemented. Each indexed change carries its own
`proposal.md`, `design.md`, `specs/` and `tasks.md`.

## The changes under it

Three changes on shillinq cite the register. Two were opened by the
small-owner lane against the gap register's ledger rows. The third opens
here, for the discovery cluster shillinq owns.

| change | rows and candidates | size | dossiq consumer |
|---|---|---|---|
| `case-payment-requests` | ledger row 12.12, Payments | M | dossiq raises a payment request on a case instead of waiting for an ERP callback, and retires the callback-only path |
| `leges-at-intake` | ledger row 1.11, Online payment during intake | M | the portaliq journey ends on a checkout; dossiq reads the case as paid or awaiting payment |
| `fees-payments-and-the-contract-register` | discovery cluster 55: C-intake-7, C-intake-38, C-intake-44, C-deadlines-10, C-parties-and-contacts-1 | M | needs a dossiq change: a case type declares its fee and the intake channels it is charged on, a case names the contract it is handled under, and the case shows the payment state shillinq holds |

`leges-at-intake` depends on `case-payment-requests`, and
`fees-payments-and-the-contract-register` depends on both. Build them in
that order.

## Coverage confirmation

Two of the five discovery candidates land partly on ground shillinq already
holds. Both are named with the requirement that carries them, so neither is
rediscovered and neither is built twice.

| candidate | already covered by | what is left |
|---|---|---|
| C-deadlines-10, a contract register alerting before renewal or lapse | `compliance-deadline-calendar` REQ-CDC-005, contract renewal and opzegtermijn alerts published by extending `ObligationTaskBridge`, and the CLM `Contract` schema with its `expiring` state | the case-system-facing half: a case names the contract it runs under, and the contract is readable from the case app |
| C-intake-38, payments and SEPA batches through a provider | `payment-run-sepa-export` REQ-SEPA-001, an approved `PaymentRun` exported as pain.001.001.03 | submitting the run to a provider instead of handing a file to a bank, and reading the per line result back |

## The decisions these rest on

- **D6, relevance-led promotion.** Every `must` enters whatever its passer
  count. Cluster 55's one `must` is C-intake-44, the fee per case type,
  with one driven passer and one documented.
- **D21, documented candidates admitted and labelled.** Two of the five,
  C-intake-38 and C-deadlines-10, rest on a vendor page rather than on a
  run system. Each is labelled in the spec and neither is counted in a
  driven tally.

## Affected projects

- `shillinq`: three changes, listed above.
- `dossiq`: declares the fee per case type and the contract per case, and
  reads the payment state. Needs a change of its own.
- `portaliq`: renders the checkout at the end of an intake journey.
  Unchanged here.
- `integriq`: owns the payment providers under `live-payment-providers`.
  The provider submission below calls integriq, it does not embed a
  provider.
- `pipelinq`: owns the product a fee may point at, under ADR-107.
