---
kind: code
---

# Proposal: customer-invoice-portal-wave2

> Contributes to the shared **portaliq** external portal (hydra ADR-046). This
> change ships NO portal of its own — it extends shillinq's existing
> `PortalContributionProvider` so debtors can finally see and pay their own
> AR invoices through portaliq.

## Summary

Lift the Wave-1 (`portal-contribution`) customer-side exclusion of `ARInvoice`
and `PaymentRequest`. The Wave-1 slice deferred them because it read a stale
fragment description that called `ARInvoice.customerId` an *internal customer
code*; the **base** `ARInvoice` schema in fact declares `customerId` as
`format: uuid`, `$ref: CustomerMaster`, `inversedBy: invoices` — the
CustomerMaster **object UUID**, which is globally unique and therefore a safe,
non-colliding scope. Wave 2 appends two read-only collections to the existing
`customer` manifest:

- `salesInvoices` (schema `ARInvoice`) — scoped by `customerId` (the
  CustomerMaster object UUID) against the new bare-name claim
  `claims.shillinq.customerMasterId`, with a `fields` whitelist that projects
  the row to the customer-safe subset (header, lines, PDF/UBL artefact URIs,
  the read-only `dunning` summary group) and hides internal accounting fields.
- `paymentRequests` (schema `PaymentRequest`) — has no customer property, so it
  is reached through a **one-hop reverse `via` join** through
  `ARInvoice.customerId` (contract v2.2, `match: 'scopeField'`). The computed
  `paymentLink` field (OpenConnector hosted payment UI, short-lived signed
  token; the existing pay-by-link machinery) is the pay-now surface; payment
  status reflects through `PaymentRequest.state` + `ARInvoice.state`.

This is provider wiring plus tests only — **no schema edit, no route, no
controller, no portaliq change**. The scope is enforced entirely by portaliq's
already-tested `PortalObjectReader` (per-row `verifyScope` + reverse-`via`
membership); shillinq only declares the manifest.

## Motivation

The pay-by-link machinery already exists (archived
`2026-06-15-ar-invoice-payment-links`: `PaymentRequest` schema,
`PaymentRequestWebhookController`, `PaymentReconciliationService`) and a real,
signature-verified, idempotent payment provider now exists (openconnector
live-payment-providers #149/#150 — Mollie + iDEAL). What was missing was the
one thing a debtor actually asks for: *"show me my invoice and let me pay it."*
Wave 1 explicitly excluded exactly that surface. This change closes the gap
through the portaliq contribution contract, without weakening the security
boundary — the whole point of an invoice portal collapses the moment it leaks
another debtor's invoice.

## Affected Projects

- [x] Project: shillinq — extends `lib/Portal/PortalContributionProvider.php`
  (customer manifest only), extends
  `tests/Unit/Portal/PortalContributionProviderTest.php`, this OpenSpec change.
  No register JSON, no routes, no controllers, no frontend, no portaliq edit.

## Scope

### In Scope

- Append `salesInvoices` (ARInvoice) + `paymentRequests` (PaymentRequest) to the
  existing `customer` manifest, scoped by `customerMasterId` (direct + reverse
  `via`), read-only.
- `fields` projection whitelists that hide internal accounting fields from the
  debtor; v3 presentation config (columns / detail / defaultSort) for a usable
  list + detail surface; the `paymentLink` pay-now field.
- Dunning surfaced read-only via the `ARInvoice.dunning` summary group.
- PHPUnit unit tests pinning the new manifest wiring AND the mandatory IDOR
  boundary (no `administrationId`/client-id scope; PaymentRequest only via the
  join through ARInvoice.customerId).
- Spec delta on the `portal-contribution` capability (REQ-SPC-020 … 022).

### Out of Scope

- **Any schema/register JSON edit** — `ARInvoice.customerId` already is the
  CustomerMaster UUID reference; no new field is added.
- **Portal-side create/write actions** (generate-a-link from the portal, quote
  acceptance) — Wave 2 stays read-only; portal-initiated PaymentRequest
  creation is a deferred Wave-3 item (see design.md).
- **A dedicated binary PDF/UBL download endpoint in shillinq** — the artefact
  URIs (`sourceDocumentUri`, `ublXml`) ride on the projected row; streaming for
  externals is portaliq/docudesk-side and deferred (design.md).
- **Any change to portaliq** — the reverse-`via` + claim mechanics already
  exist and are tested in portaliq; if a gap were found there it would be filed
  as a portaliq follow-up, never crossed here.
- **Raising `minTrust`** — collections stay at the default (low) until the
  eHerkenning broker lands; the UUID+claim scope is the boundary (design.md).

## Approach

Purely declarative. The two new collections are pure data appended to the
customer manifest; portaliq resolves `claims.shillinq.customerMasterId`
server-side and scopes every read (direct for ARInvoice, reverse-`via` for
PaymentRequest) with per-row verification. Because the CustomerMaster object
UUID is globally unique, a customer of two administrations legitimately sees
their invoices from both, and no cross-administration collision is possible —
the exact failure the per-administration customer *code* would have risked.

## New Dependencies

None. The provider stays dependency-free and inert without portaliq.

## Impact

- `lib/Portal/PortalContributionProvider.php` — customer manifest +2
  collections; docblocks updated. No other method changes.
- `tests/Unit/Portal/PortalContributionProviderTest.php` — exclusions lifted;
  new shape + IDOR tests.
- Zero runtime behaviour change inside shillinq when portaliq is absent.

## Cross-Project Dependencies

At runtime portaliq must issue `claims.shillinq.customerMasterId` = the
CustomerMaster object UUID the portal subject is bound to (the claim-names
handshake in design.md). A subject without the claim matches no AR rows —
fail-closed. `paymentLink` resolves through the existing OpenConnector payment
adapter (unchanged by this change).

## Risks

### Risk 1: `customerMasterId` claim not yet issued by portaliq's broker

**Severity:** Medium — **Mitigation:** every new collection scopes via
`scopeClaim`; a subject without the claim matches zero rows (fail-closed on the
portaliq side). Nothing in shillinq breaks if the claim never arrives.

### Risk 2: Legacy rows whose `customerId` holds a code/slug, not the UUID

**Severity:** Low — **Mitigation:** such rows simply do not match any
`customerMasterId` claim and stay invisible (fail-closed, never leaked).
Harmonising legacy `ARInvoice.customerId` values to the CustomerMaster UUID is
pre-existing data-quality debt, out of scope here.

### Risk 3: `PaymentRequest.invoiceReference` stored as a slug, not the id

**Severity:** Low — **Mitigation:** the reverse-`via` `targetField` is the
canonical ARInvoice `id`; a slug-only reference fails the join closed
(invisible), never widening. Portal-visible payment requests reference their
invoice by id (the reconciliation service's primary resolution form).

## Rollback Strategy

Revert the two appended collections (and the test changes) and archive the
change. No data, schema, or config was touched.

## Open Questions

None blocking. Portal-initiated link generation, a binary download endpoint,
and `minTrust: substantial` are recorded as deferrals in design.md.
