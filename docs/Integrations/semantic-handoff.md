---
sidebar_position: 5
title: Semantic handoff — quote → contract → invoice
description: Objects handed off from pipelinq (or shillinq's own quotes) land as drafts with provenance links; the finance group is notified on arrival.
---

# Semantic handoff: quote → contract → AR invoice

Shillinq is the **consume side** of the cross-app semantic handoff chain
*pipelinq quote → contract → AR invoice* (ADR-051 semantic-object-handoff,
change `semantic-invoice-consume`). Cross-app flows never target a shillinq
schema or the shillinq app id directly — they target **canonical semantic
kinds**, and OpenRegister resolves whichever installed schema implements the
kind:

| Canonical kind | Implementing shillinq schema | Handoff provider? |
|---|---|---|
| `https://openregister.app/ns#Quote` | `Quote` | marker only (emitter of H1) |
| `https://openregister.app/ns#Contract` | `Contract` | yes — `handoffContract` binding |
| `https://openregister.app/ns#Invoice` | `ARInvoice` | yes — `handoffContract` binding |
| `https://openregister.app/ns#SalesOrder` | `SalesOrder` | marker only (no kind contract at OR HEAD) |

Everything is declarative register configuration in ONE overlay fragment,
`lib/Settings/register.d/semantic-invoice-consume.json` — when the
`abstract-order-primitive` consolidation reshapes the schemas, the markers and
bindings move by re-pointing that single file.

## Objects handed off land as drafts

A handoff **always** creates the target object in the schema's declared
initial lifecycle state — `draft` for both `Contract` and `ARInvoice`. The
handoff mapping carries no lifecycle field, so a handed-off invoice can never
arrive `issued`: no GL transaction is materialised, no credit-limit check
runs, and nothing is dispatched until an operator reviews the draft and
advances the lifecycle through the existing guarded transitions.

Two handoffs are declared:

- **H1 `quote-accepted-to-contract`** — on the `Quote` schema, fires
  automatically when a quote transitions to `accepted` and creates a draft
  `Contract` (title, counterparty, currency, total, start date, provenance).
- **H2 `contract-to-initial-invoice`** — on the `Contract` schema,
  **manual trigger**: the operator runs it from the contract once an outbound
  contract is active, creating ONE draft `ARInvoice`. It is manual because
  the v1 handoff dialect has no condition grammar — an automatic trigger
  would also draft AR invoices for inbound (purchase) contracts. Recurring
  billing stays with `RecurringInvoiceProfile`; H2 only creates the initial
  invoice.

## What the provenance link means

Every handed-off object carries provenance in three places:

1. **In the data** — `Contract.sourceQuoteReference` /
   `ARInvoice.sourceContractReference` hold a scalar pointer
   (`shillinq:quote:<quoteNumber>` / `shillinq:contract:<contractNumber>`).
2. **In the relations** — the engine writes
   `handoff:<id>:originated-from` (target side) and
   `handoff:<id>:handed-off-to` (source side) with the counterpart's UUID;
   this is what the Related widget renders.
3. **In the audit trail** — one immutable `handoff.executed` entry per side
   recording the actor, kind, handoff id, correlation id and resolved schema.

Operator-created contracts and invoices simply leave the provenance fields
empty — they remain fully valid.

## Who gets notified

An `x-openregister-notifications` rule (`handoffReceived`) on `Contract` and
`ARInvoice` fires on `created` **only when the provenance pointer is
non-empty**: members of the `shillinq-finance` group and everyone with manage
rights on the object receive a Nextcloud notification naming the contract or
invoice number (metadata only). Handed-off drafts are triaged through the
existing Contract / AR invoice index views.

## Current limits (verified against OpenRegister HEAD, 2026-07-06)

- The pipelinq produce side (deal → quote emitter) is a separate change;
  pipelinq has no quote schema yet. Shillinq-standalone H1 works via its own
  `Quote` schema.
- Since `contracts-single-home` un-collided `Contract` from the IFRS-15
  revenue-recognition schema (now named `RevenueContract`), `Contract`'s
  `required` list is CLM's own four fields only, and the H1 handoff CREATE
  validates cleanly against it. The remaining validation-failure risk is
  narrowed to `ARInvoice`, whose `required` list still requires fields that
  are not part of the kind contract (invoice number, administration,
  period); until the `abstract-order-primitive` required-dedup and/or an
  ADR-041 intake listener (numbering etc.) lands, an H2 handoff execution
  may still fail target validation — surfaced to the operator on the manual
  trigger. The quote's own `accepted` transition is never blocked.
- Two demo seeds (`CT-2026-HANDOFF-001`, `INV-2026-HANDOFF-001`) ship with
  the fragment so draft-arrival, provenance rendering and the notification
  condition are verifiable without pipelinq.
