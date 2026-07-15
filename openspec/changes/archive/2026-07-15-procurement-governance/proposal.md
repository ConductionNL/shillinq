# Dispose of the homeless purchaseq procurement-governance slugs

## Why

`purchaseq#5` retired the standalone `purchaseq` app into `shillinq` and left
**10 procurement-governance slugs** without a home. The requisition capability
already shipped (PR #448). The owner's directive for the remaining nine is
explicit: **drop them, or abstract them to generic English functionality** — do
NOT rebuild bespoke Dutch procurement jargon into a generic accounting suite.

## What changes

Per the decision table in `design.md` (2 build / 5 drop / 2 already-covered):

- **Build `Supplier qualification`** (generic, jurisdiction-neutral): a
  `SupplierQualification` schema + `SupplierQualificationGuard` that blocks the
  **first PurchaseOrder** to a supplier that is not qualified or has a
  missing/expired required document, plus a duplicate-supplier check on
  tax-id / IBAN at registration. Enforced behind a default-OFF policy
  `require_supplier_qualification_for_po`.
- **Build `Framework agreement`** (generic): a `FrameworkAgreement` schema with
  a spend **ceiling** + `FrameworkAgreementDrawdownGuard` that blocks a PO
  call-off exceeding the remaining ceiling. Enforced when a PO carries a
  `frameworkAgreementId`.
- **Drop** (jurisdiction-locked policy or no distinct generic gap): catalog
  ordering, AI/analytics, MVI-SROI, BIBOB, inhuur-derden.
- **Already-covered** (no build): formal tenders / TenderNed
  (`bookkeeping-tenderned-integratie`), inbound Peppol/UBL invoice receipt
  (`SupplierInvoiceService::ingestUBLInvoice`).

Both new guards consume OpenRegister abstractions (ADR-022) and reuse the
existing `PurchaseOrderService` / `BudgetBlocker` infrastructure without
re-implementing any business logic (ADR-031). English i18n keys (the point of
the abstraction) with Dutch translations.

## Impact

- Schemas: `+SupplierQualification`, `+FrameworkAgreement` (register.d fragment).
- Services: `+SupplierQualificationService`, `+FrameworkAgreementService`.
- Guards: `+SupplierQualificationGuard`, `+FrameworkAgreementDrawdownGuard`.
- `PurchaseOrderService::createPurchaseOrder()` gains two default-inert gates
  (trailing nullable constructor deps — existing tests unchanged).
- `purchaseq#5` updated with the disposition of all 10 slugs; `purchaseq`
  retirement is then clean.
