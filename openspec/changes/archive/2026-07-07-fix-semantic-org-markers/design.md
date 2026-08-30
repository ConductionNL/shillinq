# Design: fix-semantic-org-markers

## Context

`x-schema-org` markers (ADR-048) are how fleet consumers resolve a schema's
schema.org type — for ADR-051 handoffs, MDM type-matching, and the
GEMMA/softwarecatalog mappers. The convention is a CURIE: `schema:<Type>` (or a
fleet `ns#<Kind>`). Three verified deviations break resolution.

## Decisions

### D1 — Prefix the five bare markers

`bookkeeping-quote-order-invoice.json` is the only fragment with bare markers
(`Offer`, `Order`, `ParcelDelivery`, `Invoice`×2). Prefix each with `schema:`.
Pure string edits; the types themselves are already correct schema.org types.

### D2 — IFRS 15 Contract → `schema:Contract`

`schema:CreativeWork` on a legal contract is wrong and inconsistent. Correct it
to `schema:Contract` to match the CLM `Contract` and the `semantic-invoice-consume`
`ns#Contract` kind. (The deeper issue — two schemas sharing the `Contract` slug —
is a slug-collision concern handled elsewhere; this change only fixes the marker.)

### D3 — Payment marker: recommend `schema:PayAction`

schema.org has no single canonical "payment record" type. The closest fits are
`schema:PayAction` (the act of paying) and `schema:MoneyTransfer` (a transfer of
funds). Since the `Payment` schema models the *event* of paying (deposit /
installment / disbursement / reclaim / final, with date, method, status),
`schema:PayAction` is the recommended marker; `schema:MoneyTransfer` is
acceptable. The spec requires *a* valid `schema:` CURIE and leaves the final pick
to the implementer.

## Why this is metadata-only

No schema property, lifecycle, calculation, or data row changes. The three edits
are: five string prefixings, one string correction, one added key. Behaviourally
inert; only the semantic layer's resolution improves.

## Verification

A small lint over `register.d/*.json` + `shillinq_register.json` asserting every
`x-schema-org` value matches `^(schema:|ns#)` would prevent regressions and is the
natural test for REQ-SEM-001.
