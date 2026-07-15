# Tasks — procurement-governance

## Decision (design.md)
- [x] 1. Produce the 10-slug decision table (2 build / 5 drop / 2 already-covered / 1 shipped) in `design.md`.

## Schema + seed
- [x] 2. Add `lib/Settings/register.d/procurement-governance.json`: `SupplierQualification` + `FrameworkAgreement` schemas (integer-cent money, `x-openregister-lifecycle`, audit-trail, RBAC).
- [x] 3. Ship seed `objects`: qualified + expired-doc supplier; an `active` FrameworkAgreement near its ceiling.
- [x] 4. Declare the default-OFF policy `require_supplier_qualification_for_po` (app config).

## Supplier qualification
- [x] 5. `lib/Service/SupplierQualificationService.php`: `registerSupplier()` (dup taxId/IBAN check), `qualify()`, `isQualifiedForPo()`.
- [x] 6. `lib/Lifecycle/SupplierQualificationGuard.php`: `assertQualifiedForPo()` — fail-closed; blocks unqualified / missing-or-expired-document supplier.

## Framework agreement
- [x] 7. `lib/Service/FrameworkAgreementService.php`: `createAgreement()`, `recordCallOff()` (increments drawnAmount).
- [x] 8. `lib/Lifecycle/FrameworkAgreementDrawdownGuard.php`: `assertWithinCeiling()` — fail-closed; blocks call-off past remaining ceiling / inactive / out-of-validity.

## Wire into PurchaseOrder
- [x] 9. Append the two guards to `PurchaseOrderService` as trailing nullable constructor deps (lazy `?? new …`).
- [x] 10. Call `assertQualifiedForPo()` in `createPurchaseOrder()` behind the policy gate.
- [x] 11. Call `assertWithinCeiling()` + `recordCallOff()` in `createPurchaseOrder()` when a `frameworkAgreementId` is present.

## i18n
- [x] 12. English l10n keys + Dutch translations for the new UI/error strings.

## Tests (php:8.3-cli, real numbers)
- [x] 13. `SupplierQualificationGuardTest`: unqualified supplier BLOCKED; missing/expired doc BLOCKED; qualified passes.
- [x] 14. `SupplierQualificationServiceTest`: duplicate taxId/IBAN rejected; `qualify()` gates on doc validity.
- [x] 15. `FrameworkAgreementDrawdownGuardTest`: call-off within ceiling passes; call-off exceeding remaining ceiling BLOCKED; inactive/out-of-window BLOCKED.
- [x] 16. `PurchaseOrderGovernanceGuardTest` (integration through `createPurchaseOrder`): first PO to unqualified supplier BLOCKED (policy ON); call-off over ceiling BLOCKED.
- [x] 17. Fragment test: `procurement-governance.json` parses; schemas declare required fields; seed covers the blocked paths.

## Spec + governance
- [x] 18. Write `specs/procurement-governance/spec.md` delta with REQ-PG-* requirements + scenarios.
- [x] 19. Update `purchaseq#5` with the disposition of all 10 slugs.
- [x] 20. Run hydra gates (spdx, spec-coverage, e2e-coverage, manifest) before push.
