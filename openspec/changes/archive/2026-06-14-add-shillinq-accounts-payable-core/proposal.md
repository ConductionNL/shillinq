# Proposal: add-shillinq-accounts-payable-core

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`VendorMaster`, `APInvoice`, `PaymentRun`) +
`x-openregister-lifecycle` + `x-openregister-calculations` for SEPA
pain.001 + iDEAL XML composition + manifest entries. No PHP approval
table, no PHP payment-service classes are authored (subject to ADR-
031 exception: at most one single-method `ThreeWayMatchGuard` if the
engine cannot express the conditional precondition).

## Summary

Introduce the **accounts payable (core)** capability for Shillinq as
one of the T2 compliance + operations capabilities (per
`adr-001-bookkeeping-tier-roadmap.md`). This change declares the
`VendorMaster`, `APInvoice`, and `PaymentRun` registers; the AP
lifecycle consuming OR's approval-workflow per ADR-022 (no app-local
approval table); the conditional 3-way match (2-way fallback when
PO/GR registers are absent); SEPA pain.001 + iDEAL XML composition
as `x-openregister-calculations` per ADR-031; vendor master with
bank IBAN / payment terms / tax registration. The capability
materialises a balanced `GLTransaction` per the same lifecycle
pattern T1 used for `JournalEntry`. AP aging is declared as an
aggregation.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`add-shillinq-general-ledger`](../add-shillinq-general-ledger/proposal.md)
(materialises GL transactions),
[`add-shillinq-document-attachment-integration`](../add-shillinq-document-attachment-integration/proposal.md)
(invoice PDF attachment via docudesk).

## Motivation

99% of bookkeeping postings land in practice via AP + AR — manual
journal entries are the exception. Shillinq's original scope was
customer invoicing (the AR half); AP completes the operational
envelope a bookkeeper actually uses every day.

The legacy AP/AR draft cluster from intelligence-db
(`competitor_features` with `app_slug=shillinq`) calls out vendor
master + AP invoice + payment run as top-tier customer-asked
features. Per ADR-022, approval routing comes from OR, not from an
app-local table; per ADR-031, SEPA XML composition is a
declarative calculation, not a `SepaService`.

This is one of eight T2 capability changes; this proposal scopes
only the AP core slice.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-accounts-payable-core`); declares 3 new registers
  (`VendorMaster`, `APInvoice`, `PaymentRun`) with lifecycles,
  calculations, and aggregations; adds 4 manifest navigation entries
  (Vendors, Accounts Payable, AP Aging, Payment Runs).
- [ ] Project: openregister — no source changes; consumes existing
  approval-workflow (ADR-022), `x-openregister-lifecycle`,
  `x-openregister-calculations`, `x-openregister-aggregations`. If
  the lifecycle engine cannot express the conditional 3-way match
  precondition declaratively, ADR-031's exception path applies (one
  single-method `ThreeWayMatchGuard`).
- [ ] Project: docudesk — no source changes; AP invoice PDF
  attachments referenced by FK URI per
  `bookkeeping-document-attachment-integration`.

## Scope

### In Scope

- One new capability spec (`bookkeeping-accounts-payable-core`) — see
  the `specs/` folder.
- The `VendorMaster` register with bank IBAN, payment terms, tax
  registration, dunning-policy reference, contact details.
- The `APInvoice` register with vendor FK, invoice number, dates,
  line items, tax breakdown, payment due, source-document URI per
  `bookkeeping-document-attachment-integration`.
- The AP lifecycle (`draft → submitted → approved → posted →
  scheduled → paid` plus `disputed` / `voided`) consuming OR's
  approval-workflow per ADR-022.
- 3-way match precondition on `APInvoice.post` — conditionally
  active when PO + Goods Receipt registers are present (future T4
  procurement); reduces to 2-way match (invoice + manual approval)
  when not.
- The `PaymentRun` register carrying N selected `APInvoice` UUIDs
  + the SEPA pain.001 XML output (declared as an
  `x-openregister-calculations` field, no PHP service).
- iDEAL payment-link generation as a per-invoice calculation.
- AP aging declared as an `x-openregister-aggregations` query
  grouping `APInvoice` by `(vendorId, agingBucket)`, excluding
  `paid` / `voided`.
- Materialisation: on `APInvoice.post`, an OR lifecycle action
  produces a balanced `GLTransaction` per the T1 `JournalEntry`
  pattern.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **PSD2 live-feed bank initiation** — T2 declares the pain.001 XML
  download surface; direct bank initiation lives in T4.
- **PO + Goods Receipt registers** — owned by a future T4
  procurement capability. AP spec is conditional: 2-way match
  fallback when not present.
- **Multi-currency translation** — T5; AP invoices carry currency
  but no FX revaluation in T2.
- **VAT/BTW posting automation** — T3.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-accounts-payable-core`** — declares the three registers,
the lifecycle (consuming OR approval-workflow), the conditional 3-
way match precondition, the SEPA / iDEAL calculations, the aging
aggregation, and the materialisation path to `GLTransaction`.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-AP-*` for
traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 new schemas
  (`VendorMaster`, `APInvoice`, `PaymentRun`); declares lifecycle
  on `APInvoice`, calculations on `PaymentRun.sepaXml` +
  `APInvoice.idealLink`, aggregation on AP aging.
- `src/manifest.json` — adds 4 navigation entries + their
  `type: index` + `type: detail` pages.
- No new PHP services (subject to ADR-031 exception: one
  single-method `ThreeWayMatchGuard` if the engine cannot express
  the conditional precondition).
- No new bespoke Vue components.

## Cross-Project Dependencies

- **OpenRegister** — depends on approval-workflow (ADR-022),
  `x-openregister-lifecycle` (ADR-031), `x-openregister-calculations`
  (ADR-031), `x-openregister-aggregations` (ADR-031). If the
  conditional precondition shape is not yet expressible, ADR-031
  exception path applies.
- **T1 general ledger** — depends on `add-shillinq-general-ledger`
  for the materialised `GLTransaction` pattern.
- **T2 document-attachment-integration** — depends on
  `add-shillinq-document-attachment-integration` for the invoice-
  PDF URI contract.

## Risks

### Risk 1: 3-way match requires schemas this capability doesn't declare

**Severity**: Medium
**Mitigation**: The Purchase Order + Goods Receipt registers
belong to a future T4 procurement capability. This capability
declares the 3-way match as REQUIRED-IF-PRESENT — if no PO/GR
register is available, the AP invoice posts with a 2-way match
(invoice + manual operator approval). The FK fields are declared
so a future PO/GR capability attaches without a destructive
migration.

### Risk 2: SEPA pain.001 + iDEAL payment initiation requires bank credentials

**Severity**: Low
**Mitigation**: This capability declares the payment-run register
and the pain.001 XML output as a downloadable artefact (operator
uploads to their bank portal). Direct bank initiation via PSD2
lives in T4 once live-feed connectors land. The spec is shape-
neutral.

### Risk 3: Conditional 3-way match precondition not expressible declaratively

**Severity**: Medium
**Mitigation**: If OR's lifecycle engine cannot express conditional
preconditions (`if PO present then quantity matches`), ADR-031's
exception path applies: a single-method
`OCA\Shillinq\Lifecycle\ThreeWayMatchGuard::matches(string
$invoiceId, ?string $poRef, ?string $grRef): bool` ships, ~20 LOC,
no state, no orchestration. Cited in spec under "Declarative-vs-
imperative decision".

### Risk 4: Vendor master overlaps with OR's contact abstraction

**Severity**: Low-Medium
**Mitigation**: Per ADR-022, prefer the OR abstraction. The spec
declares the bookkeeping-side fields as a thin view onto contacts
if OR's contact abstraction is stable; otherwise app-local with
documented migration plan once OR ships contacts. Resolved during
the implementing cycle.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
registers are non-destructive — AP invoices remain queryable but
unreferenced.

## Open Questions

1. **3-way match conditional precondition** — see Risk 3; resolved
   in `opsx-ff` discovery against OR's lifecycle engine
   capabilities.
2. **Vendor master vs OR contact** — see Risk 4; resolved during
   the implementing cycle's ADR-022 review.
3. **Approval routing topology** (linear chain vs threshold-based)
   — operator-configurable per administration; resolved during the
   implementing cycle's UX review.
