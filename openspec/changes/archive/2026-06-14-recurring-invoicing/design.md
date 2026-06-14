# Design — Recurring Invoicing (Periodieke Facturen)

## Context

Fixed-amount periodic invoicing is deferred by
`bookkeeping-quote-order-invoice` ("Subscription / recurring invoicing —
future"), out of scope for `retainer-billing-engine` (prepaid hour bundles,
consumption-based) and `invoice-from-time-and-expense` (variable
time-derived amounts). Yet it is the most common automation request in NL
bookkeeping — rent, hosting, support contracts, memberships — and every
competitor ships it. The 2026-06-11 re-evaluation lists it as
EXPECTED-GAP 3.

The design constraint that matters most: a generated invoice must be a
completely **ordinary** `ARInvoice`. The moment recurring invoices become a
special type, every downstream capability (numbering, BTW, dunning, ageing,
payment links, SEPA DD, bank matching, XAF export) needs a special case.
So the profile is pure generation metadata, and generation is a scheduled
composition of existing surfaces.

## Goals

- Define once, invoice every period: profile → ordinary `ARInvoice` per
  period via OR's scheduled machinery.
- Exactly-once generation per profile+period, across downtime, restarts,
  and re-runs.
- Generated invoices indistinguishable from manual ones downstream:
  no-gap numbering, standard BTW, standard delivery, dunning, payment
  links, DD.
- Safe-by-default: draft-for-review mode default, exact next-invoice
  preview before activation, bounded catch-up.
- Honest date semantics for NL practice: invoice day clamping, civil dates
  in the administration's timezone.

## Non-Goals

- No consumption-, usage-, or time-based amounts (retainer/time changes own
  those); profile lines are fixed.
- No proration in v1 (partial first/last periods are manual).
- No customer self-service subscription portal.
- No payment collection logic (payment links and SEPA DD are their own
  changes; this change only feeds them).
- No app-local scheduler: no TimedJob/cron — OR scheduled machinery only
  (the docudesk/procest/shillinq invalid-registerJob lesson).
- No parallel invoice schema or "recurring invoice" document type.

## Reuse Analysis

| Need | Reused surface | What this change adds |
|---|---|---|
| The invoice itself | `ARInvoice` (AR core, merged spec) + its lifecycle | Provenance fields `recurringProfileId` + `billingPeriod` on generated rows |
| No-gap numbering | `bookkeeping-quote-order-invoice` REQ-QOI-007 [CHAINED] | Nothing — generated invoices take the next sequence number |
| BTW computation | Q2C per-line BTW engine REQ-QOI-006 [CHAINED] | Nothing — lines carry VAT codes, the engine computes |
| Delivery (email / Peppol UBL) | Q2C delivery REQ-QOI-008 [CHAINED] | `deliveryChannel` selection on the profile |
| Customer identity | NC addressbook contact + `CustomerMaster` (AR conventions) | `customerReference` on the profile; no party schema |
| Scheduling | OR scheduled workflow machinery | One declared workflow: `status=active AND nextRunDate<=today` |
| Date calculation | OR `x-openregister-calculations` | `nextRunDate` from frequency/interval/invoiceDay |
| Review/failure alerts | OR notification engine (ADR-031 dialect) | Three rules (draft generated, generation failed, ending soon) |
| Collection | `ar-invoice-payment-links` / `bookkeeping-sepa-direct-debit` | A `sepaCollection` flag; links need nothing at all |
| Audit, RBAC | OR audit + RBAC | `x-openregister-audit: true` |

## Decisions

### D1 — One schema: `RecurringInvoiceProfile`; lines embedded; invoices ordinary

Profile lines (description, quantity, unit price, VAT code, revenue
account, dimensions) are an embedded array — they have no independent
identity or lifecycle (same reasoning as contract `renewalTerms`). The
generated invoice is a standard `ARInvoice` with two provenance fields;
no `RecurringInvoice` type exists. History = "all invoices where
`recurringProfileId = this`", a plain filtered list.

### D2 — Idempotency key = profile id + billing period

Each generated invoice records its `billingPeriod` (e.g. `2026-07` for
monthly, `2026-Q3` for quarterly). Generation for a period that already
has a non-cancelled invoice for the profile is a no-op. This single rule
makes double-fired jobs, replays, and catch-up all safe, and is checkable
by a reviewer in one place.

### D3 — `nextRunDate` is a declared calculation with pinned clamping semantics

Computed from `frequency` × `interval`, `invoiceDay`, and the last
generated period. Clamping: `invoiceDay = 31` yields the last day of
shorter months; Feb 29 anniversaries fall to Feb 28 in non-leap years.
All dates are civil dates in the administration's timezone — never UTC
timestamps (DST cannot shift an invoice day). The edge cases are unit-test
fixtures, not folklore.

### D4 — Issue modes: draft-for-review (default) and auto-issue (opt-in)

`draft-for-review` generates a draft `ARInvoice` and notifies the profile
owner; a human issues it (the safe default — a misconfigured profile
produces one wrong draft, not a delivered wrong invoice). `auto-issue`
generates, issues (number assigned at issue, per Q2C numbering rules), and
delivers via the profile's channel. Auto-issue can only be enabled on a
profile whose preview has been rendered (UI affordance, spec-pinned
opt-in).

### D5 — Pause/resume never silently back-generates

Pause: generation stops, `nextRunDate` frozen. Resume: `nextRunDate`
recomputes forward from today; the skipped periods are listed on the
profile detail for explicit per-period manual generation. Downtime
catch-up (the job didn't run, profile stayed active): auto-issue profiles
auto-generate at most ONE missed period and list older ones;
draft-for-review profiles generate all missed periods as drafts (drafts
are harmless). Bounded, explicit, never surprising.

### D6 — Period tokens make one description serve every period

Line descriptions support `{period}`, `{month}`, `{year}` substitution
("Hosting {month} {year}" → "Hosting juli 2026", localized to the
customer's document language). Token expansion happens at generation; the
profile keeps the template.

### D7 — Indexation is a fixed annual percentage, applied transparently

Optional `indexationPercent` applied to unit prices at each `startDate`
anniversary; each application appends to an `indexationHistory` on the
profile (date, percent, old→new prices) so price changes are auditable
and visible to the operator before the first indexed invoice goes out
(ending-soon-style notification on application). CPI-feed automation is
explicitly later.

### D8 — End conditions and the ending-soon signal

`endDate` or `occurrenceCount` or open-ended. The profile transitions to
`ended` after the final generation; an "ending soon" notification fires
one period before the last so renewals/renegotiations happen on time
(complementing contract-lifecycle-management where a contract backs the
recurring billing — a profile MAY reference a contract, soft link).

### D9 — Generation executor is thin and composes existing surfaces

If the generate step exceeds what the scheduled workflow can express
declaratively, a thin `RecurringInvoiceGenerator` lands in the ADR-031
exception path: read profile → expand tokens → create `ARInvoice` via the
existing surface (real OR ObjectService API only) → issue/deliver per
mode → advance profile. It contains zero numbering, tax, or delivery
logic — those belong to the surfaces it calls [CHAINED:
bookkeeping-quote-order-invoice for numbering/BTW/UBL specifics; until it
lands, generation targets the AR-core surface and the CHAINED clauses
activate later].

### D10 — i18n with ENGLISH source keys

`t('shillinq', 'Next invoice on {date}')` → nl
`'Volgende factuur op {date}'`; all new strings keyed in English with
`nl` translations in the same commit; notification subjects in `nl` +
`en`, metadata-only.
