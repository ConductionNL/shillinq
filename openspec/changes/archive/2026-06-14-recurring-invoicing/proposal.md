# Proposal: recurring-invoicing

`kind: config` per ADR-032/ADR-037 — one new register schema
(`RecurringInvoiceProfile`) with lifecycle, calculations, a scheduled
generation workflow, and notification rules in an ADR-037 register
fragment. Generation produces **ordinary AR invoices** through the existing
invoicing surfaces — no parallel invoice type, no app-local scheduler.

## Summary

Introduce **periodieke facturen**: "invoice this customer X every period" —
rent, hosting, service contracts, memberships, fixed-fee retainers,
maintenance agreements. An operator defines a recurring profile (customer,
lines, frequency, start/end, delivery channel); the system generates the
invoice each period via OpenRegister's scheduled machinery, numbers it in
the normal no-gap sequence, computes BTW per the normal engine, delivers it
through the normal channels (email / Peppol), and — combined with
`ar-invoice-payment-links` and `bookkeeping-sepa-direct-debit` — collects
it automatically.

This closes EXPECTED-GAP 3 of the 2026-06-11 feature re-evaluation.
`bookkeeping-quote-order-invoice` **explicitly defers** it ("Subscription /
recurring invoicing — future. Billing is per-order"); `retainer-billing-engine`
covers prepaid hour bundles (consumption-based, a different thing) and
`invoice-from-time-and-expense` covers variable time-based amounts. The
plain fixed-amount periodic invoice — standard in every NL competitor
(Moneybird "periodieke facturen", e-Boekhouden "automatische facturen",
Exact, SnelStart) — is covered nowhere.

**Depends on:**
- `bookkeeping-accounts-receivable-core` (the `ARInvoice` record generated
  invoices materialize as; merged spec)
- `bookkeeping-quote-order-invoice` (CHAINED: no-gap invoice numbering
  REQ-QOI-007, BTW-per-line engine REQ-QOI-006, UBL/Peppol delivery
  REQ-QOI-008 — generated invoices flow through that machinery)
- `shillinq-notifications` (ADR-031 notification rule conventions)
- `ar-invoice-payment-links` (soft synergy: generated invoices carry
  payment links automatically)
- `bookkeeping-sepa-direct-debit` (CHAINED soft: profiles can flag SEPA
  collection so generated invoices enter the existing DD batch flow)

## Motivation

Recurring revenue is how a large share of ZZP'ers and SMBs actually bill:
hosting and SaaS fees, rent and sublets, maintenance and support contracts,
memberships, monthly bookkeeping fees. Today each of those means manually
re-creating the same invoice every month — the most obviously automatable
chore in bookkeeping, and the feature whose absence makes users keep their
old package "just for the periodic invoices". Competitors treat it as
table stakes; its absence is conspicuous in any comparison matrix.

The combination story is the real prize: recurring profile + payment link
(or SEPA DD) + automatic reconciliation = revenue that books itself.

## Affected Projects

- [x] Project: shillinq — one new register schema in an ADR-037 fragment,
  scheduled generation workflow, notification rules, manifest pages.
- [ ] Project: openregister — consumer only (scheduled machinery,
  lifecycle, calculations, notifications); no OR changes required.
- [ ] Project: openconnector — not directly involved (payment links / DD
  ride their own changes).

## Scope

### In Scope

- **`RecurringInvoiceProfile` schema** in the ADR-037 fragment
  `lib/Settings/register.d/recurring-invoicing.json`: customer reference
  (NC addressbook contact + `CustomerMaster` link per AR conventions),
  embedded line array (description with period tokens, quantity, unit
  price, VAT code, GL revenue account, cost center/dimensions), frequency
  (weekly / monthly / quarterly / semi-annually / annually) with interval
  multiplier, `startDate`, end condition (endDate or occurrence count or
  open-ended), `invoiceDay` (incl. last-day-of-month semantics),
  `nextRunDate` (computed), issue mode (draft-for-review / auto-issue),
  delivery channel (email / Peppol / none), payment terms, optional annual
  indexation percentage, optional SEPA-DD flag, lifecycle status.
- **Scheduled generation** via OR's scheduled machinery (no app cron /
  TimedJob): on `nextRunDate`, generate one ordinary `ARInvoice` from the
  profile, substitute period tokens (`{period}`, `{month}`, `{year}`) in
  line descriptions, advance `nextRunDate`, decrement remaining
  occurrences. Idempotent per profile+period — catch-up after downtime
  never duplicates.
- **Normal invoice machinery**: generated invoices take the next number of
  the no-gap sequence, BTW per the standard per-line engine, delivery via
  the standard channels, dunning/ageing/payment matching as any other
  invoice [numbering/BTW/UBL specifics CHAINED:
  bookkeeping-quote-order-invoice].
- **Issue modes**: `auto-issue` (generate → issue → deliver, the
  rent/hosting case) and `draft-for-review` (generate a draft, notify the
  owner, human issues it).
- **Lifecycle**: draft → active → paused → ended, with resume semantics
  that never back-generate skipped periods silently.
- **Price indexation**: optional `indexationPercent` applied to unit prices
  at each anniversary of `startDate`, recorded on the profile history.
- **Notifications** (ADR-031 dialect): draft generated (review mode),
  generation failed, profile ending soon (last occurrence approaching).
- **Frontend** (ADR-037 manifest fragment): profiles index (next run,
  status, MRR-style total), profile detail with "next invoice preview" and
  generated-invoices history, create/edit modal.
- **i18n**: ENGLISH source keys, `nl` + `en` catalogs.

### Out of Scope

- **Consumption/usage-based billing** — owned by `retainer-billing-engine`
  (prepaid bundles) and `invoice-from-time-and-expense` (time-based).
- **Proration on mid-period start/change** — v1 generates whole periods;
  the operator handles a partial first period manually.
- **Customer self-service subscription management** (sign-up, upgrades,
  cancellation portal) — future capability.
- **Payment collection itself** — `ar-invoice-payment-links` (links) and
  `bookkeeping-sepa-direct-debit` (DD) own collection; this change only
  flags/feeds them.
- **CPI-linked automatic indexation** (external index feeds) — v1 is a
  fixed percentage; index-feed automation later.
- **Quote/order coupling** — a recurring profile is created directly or
  from an accepted quote later; the conversion is `bookkeeping-quote-order-invoice`
  follow-up territory.

## Approach

1. The profile is the single source of truth; the generated invoice is an
   ordinary `ARInvoice` carrying `recurringProfileId` + `billingPeriod`
   provenance fields (the pair is the idempotency key).
2. Generation is a declared scheduled workflow on the profile schema
   (filter: `status = active AND nextRunDate <= today`), executing through
   the existing invoice-creation surface. The only PHP, if any proves
   necessary, is a thin generation executor in the ADR-031 exception path —
   it composes existing surfaces and contains no tax/numbering logic.
3. `nextRunDate` is a declared calculation from frequency, interval,
   `invoiceDay`, and the last generated period (month-end clamping:
   "31st" in February = Feb 28/29).
4. Pause stops generation; resume recomputes `nextRunDate` forward from
   today (skipped periods are listed for explicit manual generation, never
   silently mass-generated).
5. Ending: `endDate` reached or occurrence count exhausted → `ended`;
   "ending soon" notification one period before.

Specs: one spec file `recurring-invoicing` with REQ-RIN-001 … REQ-RIN-008.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/recurring-invoicing.json` — NEW ADR-037 register
  fragment: `RecurringInvoiceProfile` schema, lifecycle, `nextRunDate`
  calculation, scheduled generation workflow, notification rules.
- `lib/Service/RecurringInvoiceGenerator.php` — NEW thin executor (only if
  the generation step is not fully expressible declaratively; ADR-031
  exception path; composes existing invoice surfaces, idempotent).
- `src/manifest.d/recurring-invoicing.json` — NEW ADR-037 manifest
  fragment: profiles index + detail/preview pages.
- `l10n/en.json`, `l10n/nl.json` — new keys (ENGLISH source strings).
- `tests/Unit/` — fragment shape, nextRunDate edge cases (month-end, leap
  year), idempotent generation, pause/resume, indexation;
  `tests/e2e/` — profile UI specs (gate-19); Newman — object surface +
  generation assertions.

## Cross-Project Dependencies

- **bookkeeping-quote-order-invoice** — CHAINED for the machinery the
  generated invoice rides: no-gap numbering (REQ-QOI-007), BTW per line
  (REQ-QOI-006), UBL/Peppol delivery (REQ-QOI-008). Until it lands,
  generation targets the AR-core `ARInvoice` surface with its existing
  lifecycle; the numbering/UBL clauses activate with that change.
- **bookkeeping-accounts-receivable-core** — generated invoices are
  standard `ARInvoice` records; dunning, ageing, credit limits apply
  unchanged.
- **ar-invoice-payment-links** — soft: a generated invoice gets a payment
  link exactly like a manual one; profiles need no special handling.
- **bookkeeping-sepa-direct-debit** — CHAINED soft: `sepaCollection` flag
  routes generated invoices into the DD batch flow once that change lands.
- **retainer-billing-engine / invoice-from-time-and-expense** — boundary
  neighbors: fixed-amount periodic invoicing only; consumption and
  time-based billing stay theirs (dedup task verifies no overlap).

## Risks

### Risk 1: Duplicate or skipped generation around downtime/restarts

**Severity**: High
**Mitigation**: idempotency key = profile id + billing period on the
generated invoice; catch-up generates each missed period at most once and
only via the explicit catch-up rules (auto for auto-issue profiles up to a
bounded look-back, listed-for-manual beyond it); spec scenarios cover
double-run and downtime cases.

### Risk 2: Date arithmetic edge cases (month-end, leap years, DST)

**Severity**: Medium
**Mitigation**: `invoiceDay` clamping semantics are spec-pinned ("31st" →
last day of shorter months; Feb 29 → Feb 28 in non-leap years); date math
is unit-tested at the edges; all dates are administration-timezone civil
dates, not timestamps.

### Risk 3: Auto-issued wrong invoices at scale (bad profile = bad invoice every month)

**Severity**: Medium
**Mitigation**: `draft-for-review` is the default issue mode; the "next
invoice preview" renders the exact would-be invoice before activation;
auto-issue requires an explicit opt-in on an active profile; credit-note
correction works as for any invoice.

### Risk 4: Overlap creep with retainer/time-based billing

**Severity**: Low
**Mitigation**: hard scope line in the spec (fixed lines only — no
consumption input, no time-entry input); dedup task documents the
boundary; profiles cannot reference time entries or retainer bundles.

## Rollback Strategy

**During implementation (before merge):** revert the implementing PR.

**Post-merge, before adoption:** the register fragment and manifest
fragment are self-contained; removal removes the capability.

**Production:** pausing or ending profiles stops all generation; generated
invoices are ordinary AR invoices and remain valid bookkeeping records
regardless of the feature's fate. Disabling the manifest fragment hides
the UI without data loss.

## Open Questions

1. **Catch-up bound for auto-issue profiles** — auto-generate at most N
   missed periods (suggest 1) and list older ones for manual action?
   Spec assumes 1; confirm with ops.
2. **First-period proration** — keep out of v1 (operator invoices the
   partial period manually) or add a simple day-based proration flag?
   Assumed out; revisit after adoption.
3. **MRR widget** — the profiles index shows a recurring-total; is a
   dashboard widget (x-openregister-widgets) wanted in v1? Cheap if the
   aggregation is declarative; decide at design review.
