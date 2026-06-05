# Proposal: bookkeeping-vat-btw-filing

`kind: config` per ADR-032 — the centre of mass is declarative
VAT/BTW registers (`VATReturn`, `VATDeclaration`, `VATLine`) +
lifecycle consuming OR workflow extension per ADR-022 + aggregations
for VAT reconciliation. No PHP VAT calculation service; business logic
driven by schema lifecycle and aggregations.

## Summary

Introduce the **VAT/BTW filing capability** for Shillinq as one of the
T3 operations capabilities (per `adr-001-bookkeeping-tier-roadmap.md`).
This capability enables Dutch SMB, freelancers (ZZP), and government
entities to:

1. Prepare and submit VAT returns electronically to Dutch tax authority (Belastingdienst)
2. Track collected VAT (sales/AR) and paid VAT (purchases/AP) by period
3. Generate VAT reports showing VAT by type (VAT paid, VAT collected, reverse charge)
4. Support VAT regime variants: standard, small-business exemption (KOR), reverse charge

The change declares the `VATReturn`, `VATDeclaration`, and `VATLine`
registers; the VAT-return lifecycle (`draft → submitted → verified →
filed`); VAT reconciliation as aggregations (sum VAT from GL by rate
and type); and manifest entries for VAT management UI.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:**
- [`add-shillinq-bookkeeping-foundation`](../add-shillinq-bookkeeping-foundation/proposal.md)
  (GL account structure with `vatApplicable` flag, `Account.accountType`)
- [`add-shillinq-bookkeeping-compliance`](../add-shillinq-bookkeeping-compliance/proposal.md)
  (GL transactions, journal entries, trial balance for VAT source data)

**Referenced by:**
- `add-shillinq-gov-sector-mkb-advanced` (BBV variant, tax reporting)

## Why

VAT compliance is mandatory for Dutch registered businesses (KVK
mandatory, VAT number ING if turnover > €20K threshold). Belastingdienst
requires quarterly (or monthly for certain regimes) electronic filing
via MTD-compatible formats. Current Shillinq bookkeeping foundation
(T1/T2) has no VAT return workflow; operators must export GL data and
manually prepare returns in external tax software, creating:

- Reconciliation gaps (GL ↔ tax return discrepancies)
- Lost audit trail (manual adjustments not captured)
- Compliance risk (missed filing deadlines, wrong rate application)
- Duplication of effort (VAT data entered twice: once in GL, again
  in tax software)

This capability closes the loop: VAT is derived declaratively from GL
transactions marked with `vatApplicable: true` and `vatRate`; returns
are generated automatically; operators review and submit electronically.

The legacy AP/AR draft cluster from intelligence-db
(`competitor_features` with `app_slug=shillinq`) identifies VAT/BTW
as a top-5 customer-requested capability alongside accounts payable
and general ledger.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-vat-btw-filing`); declares 3 new registers
  (`VATReturn`, `VATDeclaration`, `VATLine`); adds 2 manifest
  navigation entries (VAT Returns, VAT Reports).
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-lifecycle`, `x-openregister-aggregations`.

## Scope

### In Scope

- One new capability spec (`bookkeeping-vat-btw-filing`) — see
  the `specs/` folder.
- The `VATReturn` register tracking return period, status, submission
  date, tax authority reference, amounts (gross, VAT collected, VAT
  paid, net).
- The `VATDeclaration` register grouping VAT lines by return and
  providing summary totals and regime type (standard, KOR, reverse
  charge).
- The `VATLine` register with GL account FK, VAT rate, amount,
  VAT amount, type (collected/paid/reverse-charge), and period.
- VAT reconciliation aggregations:
  - Sum of VAT collected by rate (sales/AR with VAT payable)
  - Sum of VAT paid by rate (purchases/AP with VAT deductible)
  - Reverse-charge VAT (intra-EU, import, services)
  - VAT balance (collected - paid)
- VAT return lifecycle (`draft → submitted → verified → filed`)
  consuming OR's lifecycle extension per ADR-022.
- Manifest entries for VAT Returns list + detail page, VAT Reports
  dashboard.
- Support for VAT regimes: standard rate (21%), reduced rate (9% and
  0%), small-business exemption (KOR), reverse-charge items.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **Electronic filing gateway integration** — direct API calls to
  Belastingdienst are deferred to T4 or a dedicated integration
  change. T3 prepares return data; filing itself is out-of-scope.
- **VAT posting automation** — automatic GL posting of VAT entries
  on invoice/purchase-order issue. T3 tracks VAT from GL; GL entry
  creation is T2 responsibility (AP/AR invoicing creates GL postings
  that this spec then reads).
- **Multi-country VAT** — European VAT schemes other than
  intra-EU reverse charge. UK VAT (MTD) is tracked as a future variant.
- **Advanced regimes** — margin scheme, second-hand goods, consignment,
  return/exchange flows beyond standard deductible/payable dichotomy.

## What Changes

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-vat-btw-filing`** — declares the three registers,
lifecycle (consuming OR's workflow extension), the aggregations for
VAT reconciliation, regime variants (standard, KOR, reverse-charge),
and the manifest entries for returns + reports.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-VAT-*` for
traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.66`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 new schemas
  (`VATReturn`, `VATDeclaration`, `VATLine`); declares lifecycle on
  `VATReturn` and `VATDeclaration`; declares aggregations for VAT
  reconciliation.
- `src/manifest.json` — adds 2 navigation entries (VAT Returns,
  VAT Reports) + their `type: index` + `type: detail` pages.
- No new PHP services (all VAT logic driven by schema lifecycle +
  aggregations per ADR-031).
- No new bespoke Vue components beyond standard CnIndexPage +
  CnDetailPage patterns.

## Cross-Project Dependencies

- **T1 Foundation** — depends on `Account` schema with
  `vatApplicable: boolean` and `accountType` enum to identify VAT-bearing
  accounts.
- **T2 Compliance** — depends on `GLTransaction` / `JournalEntry`
  pattern for GL postings that source VAT data; depends on AP/AR
  for creation of VAT-marked transactions.
- **OpenRegister** — depends on `x-openregister-lifecycle` for
  VAT return state machine, `x-openregister-aggregations` for VAT
  reconciliation queries.
