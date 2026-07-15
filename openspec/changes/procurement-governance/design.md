# Design — procurement-governance

## Context

`purchaseq#5` retired the standalone `purchaseq` app and asked whether any of
its planned **13-spec procurement suite** should be salvaged into `shillinq`
(which now owns AP / procurement / PurchaseOrder). Ten homeless slugs were
flagged. The owner's directive is explicit: **do NOT rebuild bespoke Dutch
procurement jargon** — for each slug either **drop it**, or **abstract it to a
genuinely reusable, English-named, jurisdiction-neutral capability** that
`shillinq`'s procurement surface actually benefits from.

The requisition capability (`Aanvragen`) already shipped (`purchase-requisition`,
PR #448). This change disposes of the remaining nine.

## Decision table (the whole point — decide before building)

| # | Original slug (purchaseq) | Capability | Decision | Rationale |
|---|---|---|---|---|
| 1 | `catalog-purchase-management-other-t1` | Aanvragen / requisition | **already-shipped** | `purchase-requisition` (PR #448) — `Requisition` + budget-gated approve. |
| 2 | `supplier-onboarding-vragenlijst` | Supplier onboarding/qualification | **ABSTRACT → build `Supplier qualification`** | Generic, jurisdiction-neutral: approve-before-first-PO, duplicate-supplier check on tax-id/IBAN, required documents/certificates with expiry. Fills a real AP gap; `VendorPerformanceAggregation` only scores *performance*, it does not gate onboarding. |
| 3 | `raamovereenkomst-minicompetitie` | Framework agreement | **ABSTRACT → build `Framework agreement`** | Generic: a contract with a spend **ceiling** that PO call-offs draw down against; a call-off past the remaining ceiling is blocked. `contract-lifecycle-management` models generic contracts but has **no** ceiling/drawdown accounting. Reuses PO infra. |
| 4 | `tenderned-publicatie-adapter` | Formal tenders / TenderNed | **already-covered** | `bookkeeping-tenderned-integratie` ships `TenderNedStatusSync`, `TenderNedAanbestedingGuard`, listener + seeds. The generic RFQ/tender procurement stage is present. |
| 5 | `peppol-ubl-inkoop-factuur-ontvangst` | Inbound Peppol/UBL invoice receipt | **already-covered** | `SupplierInvoiceService::ingestUBLInvoice()` ingests a **Peppol-received** UBL Invoice (`ubl_source_uri`, `peppol_received_at`, `statusCode=received`) and feeds the `bookkeeping-purchase-order-3way` matching engine. purchaseq#5's "outbound only" note is stale. |
| 6 | `catalog-purchase-management` | Catalogus-based ordering | **DROP** | No distinct generic gap — line-item ordering is already covered by `Requisition` (raise) + `inventory-product-catalog` (products) + `PurchaseOrder` (order). A punch-out/catalog-ordering layer is a separate large product bet, not procurement *governance*. |
| 7 | `catalog-purchase-management-ai` / `-analytics` | AI supplier/price suggestions, spend analytics | **DROP** | AI belongs to `hermiq`; spend analytics is dashboard/reporting surface, not a governance control. No jurisdiction-neutral governance capability underneath worth a bespoke build here. |
| 8 | `mvi-sroi-aanbesteding` | MVI-SROI social/sustainability tender criteria | **DROP** | Jurisdiction-locked NL public-procurement policy (Maatschappelijk Verantwoord Inkopen / Social Return). No generic English equivalent; do not build government-policy screening into a generic accounting suite. |
| 9 | `bibob-toetsing-leveranciers` | BIBOB integrity screening | **DROP** | Wet Bibob is an NL statute (integrity screening of suppliers by government bodies). Jurisdiction-locked policy; no generic equivalent. |
| 10 | `inhuur-derden-wnra-wnt` | Contingent-labour hire (Wet DBA/WNRA/WNT guards) | **DROP** | Jurisdiction-locked NL labour-law juridical guards. The generic DBA marker `shillinq` needs already exists (`dba-compliance-marker` + `DBAVbarMonitorService`); the WNRA/WNT tender-hire layer has no generic form worth building. |

**Net: 2 built (generic, English), 5 dropped, 2 already-covered, 1 already-shipped.**
This is deliberately a small change — two reusable governance controls plus a
documented drop-list — exactly as the directive blessed.

## What we build

### 1. Supplier qualification (`SupplierQualification` schema + guard)

A `SupplierQualification` record captures a supplier's onboarding state:
`taxId`, `iban`, `statusCode` (`draft → qualified → expired|revoked`), and a
`requiredDocuments[]` list (`{documentType, expiresAt, provided}`).

- `SupplierQualificationService::registerSupplier()` — creates a qualification,
  rejecting a **duplicate** supplier whose `taxId` **or** `iban` already exists
  in the administration (dup-supplier check).
- `SupplierQualificationService::qualify()` — moves `draft → qualified` only
  when every required document is provided and unexpired.
- `SupplierQualificationGuard::assertQualifiedForPo()` — invoked from
  `PurchaseOrderService::createPurchaseOrder()` **when policy
  `require_supplier_qualification_for_po` is ON** (default OFF). Blocks the PO
  when the supplier has no `qualified` record, or has a required document that
  is missing/expired. Fail-closed: an unresolvable check denies.

### 2. Framework agreement (`FrameworkAgreement` schema + drawdown guard)

A `FrameworkAgreement` record: `agreementNumber`, `supplierId`, integer-cent
`ceilingAmount`, `drawnAmount`, `validFrom`/`validUntil`, `statusCode`
(`active → closed|expired`).

- `FrameworkAgreementService::recordCallOff()` — records a PO drawing down
  against the agreement; increments `drawnAmount`.
- `FrameworkAgreementDrawdownGuard::assertWithinCeiling()` — invoked from
  `createPurchaseOrder()` **when the PO payload carries a `frameworkAgreementId`**
  (opt-in per PO). Blocks the call-off when `drawnAmount + poTotal >
  ceilingAmount`, or the agreement is not `active` / outside its validity
  window. Fail-closed.

### Wiring (ADR-022 / ADR-031 — consume OR abstractions, reuse existing infra)

Both guards are plain in-process PHP classes (like `BudgetBlocker` /
`MandaatEnforcer`) that read/write via OpenRegister's `ObjectService`
(`setRegister()->setSchema()->findAll()/saveObject()`), never a bespoke
mapper. They are appended to `PurchaseOrderService`'s constructor as **trailing
nullable** parameters (lazy `?? new …`, mirroring the existing
`PeppolTransmissionAdapterInterface` slot) so every existing `PurchaseOrderService`
test constructs unchanged and stays green. Neither gate re-implements any
business logic that lives elsewhere — supplier qualification and ceiling
drawdown are new controls with no existing home.

## Seed data

`lib/Settings/register.d/procurement-governance.json` ships seed `objects`:
- one `qualified` supplier (all docs valid), one `draft` supplier with an
  **expired** ISO-certificate (so the blocked-first-PO path is demonstrable), and
- one `active` `FrameworkAgreement` with `ceilingAmount 5 000 000` cents and
  `drawnAmount 4 800 000` (so a €3 000 call-off blows the ceiling — the
  blocked-drawdown path is demonstrable).

Money is integer euro cents throughout (ADR-022). Register slug resolves from
app config (`shillinq`, ADR-037 modular fragment — never edit
`shillinq_register.json`).

## ADR-031 notes

The `x-openregister-lifecycle` on both schemas is declarative; the two blocking
controls are enforced imperatively at the single mutation point that creates a
`PurchaseOrder` (`createPurchaseOrder()`), because both are **cross-schema**
guards (supplier qualification and framework ceiling are separate records from
the PO being written) — the same pattern `RequisitionConversionService` and
`PurchaseOrderService::blockSendUntilApproved()` already use for cross-record
lifecycle effects.
