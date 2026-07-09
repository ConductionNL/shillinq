---
kind: code
---

# Proposal: portal-contribution

> Tracking issue: [Conduction/shillinq#365](https://github.com/ConductionNL/shillinq/issues/365) (Wave-1 ADR-046 rollout)

## Summary

Ship shillinq's Wave-1 ADR-046 portal contribution: one plain, dependency-free
class (`lib/Portal/PortalContributionProvider.php`) that declares — for the
`customer` and `supplier` audiences — the OpenRegister collections an external
party may read through portaliq, the shared external portal for people without
Nextcloud accounts. Multi-audience (contract v2 `getAudiences()` plus the v1
`getAudience()` fallback), read-only (no create actions this wave), scoped
exclusively by verified UUID domain-reference properties on the rows, matched
against per-app claims (`claims.shillinq.customerId` /
`claims.shillinq.supplierId`). Provider class + unit tests only — **no schema
edits** in this change.

## Motivation

ADR-046 (+ 2026-07-06 amendment, contribution contract v2) establishes
portaliq as the one external portal; domain apps contribute via a duck-typed
provider class — no portaliq import, no info.xml dependency — so portal
support is always optional and inert without portaliq. Shillinq owns the
finance domain: customers ask "where is my invoice / quote / order /
contract?" and suppliers ask "was my invoice matched, what did you order?"
today answered by e-mail. The portaliq fleet review (2026-07-06) placed
shillinq in Wave 1 with a multi-audience slice. Every collection below was
verified property-by-property against `lib/Settings/shillinq_register.json` +
`lib/Settings/register.d/*.json` at HEAD (478 schemas); anything whose scoping
property is not an honest UUID domain reference is excluded and documented
rather than approximated.

## Affected Projects

- [x] Project: shillinq — new `lib/Portal/PortalContributionProvider.php`, unit tests under `tests/Unit/Portal/`, this OpenSpec change. No register JSON edits, no routes, no frontend.

## Scope

### In Scope

- A plain `OCA\Shillinq\Portal\PortalContributionProvider` class (no portaliq
  imports, no `implements` clause, no constructor dependencies) exposing
  `getAudiences(): array` (v2), `getAudience(): string` (v1 fallback), and
  `getContribution(array $subject): ?array` branching on
  `$subject['audience']`.
- `customer` manifest (all read-only, register `shillinq`, scopeClaim
  `customerId`): `Invoice` (scopeField `customerReference`), `BillableInvoice`
  (`customerId`), `Quote` (`customerReference`), `SalesOrder`
  (`customerReference`), `Contract` (`customerId`).
- `supplier` manifest (read-only, register `shillinq`, scopeClaim
  `supplierId`): `PurchaseOrder` (scopeField `supplierId`), `SupplierInvoice`
  (`supplierId`).
- PHPUnit unit tests pinning the full contract and manifest shape.
- OpenSpec capability `portal-contribution` (this change).

### Out of Scope

- **Create actions** — none this wave. The one candidate (quote acceptance)
  is modelled as fields on the existing `Quote` row
  (`acceptanceChannel`/`acceptedAt`/`acceptanceEvidenceReference`), i.e. an
  *update*, which the Wave-1 contract does not cover. Deferred (see
  design.md).
- **Endpoint actions** — receiver-side verification does not exist yet
  (Wave-1 contract rule).
- **ARInvoice + PaymentRequest** — `ARInvoice.customerId` is an FK to
  `CustomerMaster.customerId`, an *internal customer code*, not a UUID domain
  reference; the contract requires UUID scoping. `PaymentRequest` has no
  customer property and only reaches a customer through
  `invoiceReference → ARInvoice`, terminating on that same non-UUID property.
  Both deferred to Wave 2 (see design.md Exclusions).
- **Dunning** — `DunningNotice` is verified AP-side (vendor dunning:
  `invoiceRef` = "FK to APTransaction UUID"); AR-side `DunningRecord` /
  `DunningRun` have no customer scope property and `DunningRun` carries
  recipient PII + rendered letters. Excluded.
- **GoodsReceipt / GoodsReceiptNote** — `GoodsReceipt` has no supplier
  reference at all (internal warehouse event); `GoodsReceiptNote` links to a
  supplier only via `poIds`, an *array* of PurchaseOrder FKs, beyond the
  verified one-hop scalar `via` join. Deferred.
- Any schema/register JSON edit, portal UI, auth edge, inbox, or
  notifications (portaliq owns the entire external surface).
- Raising `minTrust` — collections ship at the default (low) until the
  eHerkenning broker lands (Wave 2, see design.md).

## Approach

Duck-typed discovery per ADR-046 A1: portaliq resolves
`OCA\Shillinq\Portal\PortalContributionProvider` by convention FQCN and probes
it with `method_exists` — shillinq ships a plain class with the three contract
methods and nothing else. The contribution is a declarative manifest (pure
data, no I/O, no callbacks). Because a portal subject's `subjectRef` is the
portal person's own UUID — not shillinq's customer/vendor record UUID — every
collection carries a bare-name `scopeClaim` (`customerId` / `supplierId`)
resolving in shillinq's own claim namespace. Details, the verified scoping
map, and the multi-administration note are in design.md.

## New Dependencies

None. The provider is dependency-free by contract and inert without portaliq.

## Impact

- `lib/Portal/PortalContributionProvider.php` — new, self-contained.
- `tests/Unit/Portal/PortalContributionProviderTest.php` — new.
- No routes, controllers, services, register JSON, frontend, or info.xml
  changes. Zero runtime behaviour change inside shillinq.

## Cross-Project Dependencies

None at build or install time (the point of A1). At runtime portaliq — when
installed — discovers and renders the contribution; the claim values
(`claims.shillinq.customerId` / `claims.shillinq.supplierId`) are issued by
portaliq's identity/claims edge per the claim-names contract in design.md.

## Risks

### Risk 1: Claim values not yet issued by portaliq's claim broker

**Severity:** Medium — **Mitigation:** every collection scopes via
`scopeClaim`; a subject without the claim simply matches no rows (fail-closed
on the portaliq side). The claim-names contract in design.md is the
handshake; nothing in shillinq breaks if the claims never arrive.

### Risk 2: Union-merged schema shapes leave some rows unsurfaced

**Severity:** Low — **Mitigation:** documented per collection in design.md
(e.g. bookings-flow `Invoice` rows carry `customerId` instead of
`customerReference`; recurring-revenue `SalesOrder` rows carry `klantId`;
CLM-shape `Contract` rows carry `counterpartyReference`). Unsurfaced rows are
invisible, never leaked — fail-closed is the acceptable Wave-1 posture.

### Risk 3: Financial data at default (low) trust

**Severity:** Low — **Mitigation:** read-only manifest, UUID-scoped rows, and
an explicit documented plan to raise financial collections to
`minTrust: substantial` once the eHerkenning broker lands (Wave 2).

## Rollback Strategy

Delete `lib/Portal/` and `tests/Unit/Portal/` and archive the change. No data,
schema, or config was touched; without the class, portaliq discovery finds
nothing for shillinq and the app is otherwise unaffected.

## Open Questions

None blocking. Wave-2 items (ARInvoice UUID customer ref or code-valued claim
support, array `via` joins for GoodsReceiptNote, quote-acceptance action,
`minTrust: substantial`) are recorded as deferrals in design.md, not open
questions.
