# Change: fix-semantic-org-markers

## Why

Shillinq annotates its OpenRegister schemas with `x-schema-org` semantic markers
(ADR-048 schema.org marker convention; the fleet consumes these as CURIEs of the
form `schema:Type`). Three verified defects break or omit those markers, so a
semantic consumer (ADR-051 handoffs, MDM type-matching, the softwarecatalog /
GEMMA mappers) silently fails to resolve them:

1. **Bare markers missing the `schema:` CURIE prefix** — in
   `lib/Settings/register.d/bookkeeping-quote-order-invoice.json` five markers are
   plain type names instead of CURIEs:
   - line 18 `"Offer"` → should be `schema:Offer`
   - line 312 `"Order"` → should be `schema:Order`
   - line 651 `"ParcelDelivery"` → should be `schema:ParcelDelivery`
   - line 760 `"Invoice"` → should be `schema:Invoice`
   - line 1120 `"Invoice"` → should be `schema:Invoice`
   Every *other* marker in the repo is correctly `schema:`-prefixed (e.g.
   `schema:Invoice`, `schema:Order`, `schema:AccountingTransaction`), so a
   consumer that matches on the `schema:` CURIE skips exactly these five.

2. **Wrong + inconsistent Contract marker** — the IFRS 15 customer contract
   schema (`lib/Settings/register.d/bookkeeping-ifrs15-revenue.json:17`, a schema
   literally titled `Contract`: "the legal instrument that bundles one or more
   performance obligations") is marked `schema:CreativeWork`. That is
   semantically wrong (a contract is not a creative work) and inconsistent with
   the canonical Contract markers elsewhere: the CLM `Contract`
   (`contract-lifecycle-management.json`) is `schema:Contract` + `ns#Contract`,
   and `semantic-invoice-consume` keys the Contract handoff on `ns#Contract`. It
   should be `schema:Contract`.

3. **Missing marker on the generic Payment schema** — the base `Payment` schema
   (`lib/Settings/register.d/zz-order-base.json:11`, the canonical payment record
   that the order/subsidie consolidation folds deposits, disbursements, and
   reclaims into) carries `slug` and `title` but **no `x-schema-org` marker at
   all** — the only order-family schema with none. It should carry a
   `schema:`-CURIE (e.g. `schema:PayAction` / `schema:MoneyTransfer`).

None of these change data or behaviour — they correct/complete metadata so the
fleet's ADR-048/051 semantic layer resolves shillinq's finance types.

## What Changes

- **ADDED** `REQ-SEM-001` (new capability `semantic-schema-markers`) — every
  `x-schema-org` marker SHALL be a valid `schema:`-prefixed CURIE; contract-typed
  schemas SHALL use `schema:Contract` consistently; and every order-family schema
  (including the generic `Payment`) SHALL carry a marker.
- `lib/Settings/register.d/bookkeeping-quote-order-invoice.json` — prefix the five
  bare markers with `schema:`.
- `lib/Settings/register.d/bookkeeping-ifrs15-revenue.json` — `Contract` marker
  `schema:CreativeWork` → `schema:Contract`.
- `lib/Settings/register.d/zz-order-base.json` — add an `x-schema-org` marker to
  the `Payment` schema.

## Impact

- Affected spec: new `semantic-schema-markers` capability (ADDED `REQ-SEM-001`).
- Affected code: three register fragments, metadata-only (marker string edits +
  one added key). No schema field, lifecycle, or data change.
- Unblocks correct ADR-051 handoff / MDM type-resolution for the quote→order→
  invoice→contract→payment chain.
- Note (out of scope): two distinct schemas share the slug `Contract` (CLM's and
  IFRS 15's) — a global-slug-collision concern per the fleet convention, tracked
  separately from this marker fix.
