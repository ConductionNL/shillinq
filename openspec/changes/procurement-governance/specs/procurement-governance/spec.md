# procurement-governance Specification

## ADDED Requirements

### Requirement: REQ-PG-001: The nine remaining homeless purchaseq slugs MUST be dispositioned as generic-abstraction, drop, or already-covered

The change MUST NOT introduce any bespoke Dutch-jurisdiction procurement-policy
feature. Each of the nine remaining `purchaseq#5` slugs MUST be either abstracted
to a generic, English-named, jurisdiction-neutral capability, dropped with a
rationale, or recorded as already-covered by an existing `shillinq` capability.
The disposition MUST be recorded in `design.md` and mirrored to `purchaseq#5`.

#### Scenario: Decision table dispositions all ten slugs
- **GIVEN** `openspec/changes/procurement-governance/design.md`
- **WHEN** its decision table is read
- **THEN** all ten slugs appear, each marked build / drop / already-covered / already-shipped, and only `supplier-onboarding` and `raamovereenkomst` are marked build

#### Scenario: No jurisdiction-locked policy feature is built
- **GIVEN** the change's `lib/` additions
- **WHEN** the shipped schemas and services are inspected
- **THEN** there is no BIBOB, MVI-SROI, or inhuur-derden/WNRA/WNT screening capability

### Requirement: REQ-PG-002: A supplier that is not qualified MUST be blocked from a PurchaseOrder when supplier-qualification enforcement is enabled

`SupplierQualificationGuard::assertQualifiedForPo()` MUST fail-closed: when the
policy `require_supplier_qualification_for_po` is enabled and the supplier has no
`qualified` `SupplierQualification` record, or has a required document that is
absent or expired, `PurchaseOrderService::createPurchaseOrder()` MUST throw and
persist no PurchaseOrder. When the policy is disabled the gate MUST be inert.

#### Scenario: First PO to an unqualified supplier is blocked
- **GIVEN** `require_supplier_qualification_for_po` enabled and a supplier with a `draft` qualification whose ISO certificate is expired
- **WHEN** `createPurchaseOrder()` is called for that supplier
- **THEN** a RuntimeException is thrown and no PurchaseOrder is persisted

#### Scenario: PO to a fully qualified supplier proceeds
- **GIVEN** a supplier with a `qualified` record and all required documents unexpired
- **WHEN** `createPurchaseOrder()` is called
- **THEN** the PurchaseOrder is created

### Requirement: REQ-PG-003: A duplicate supplier MUST be rejected on tax-id or IBAN at registration

`SupplierQualificationService::registerSupplier()` MUST reject registration when
a `SupplierQualification` with the same `taxId` **or** the same `iban` already
exists in the administration.

#### Scenario: Duplicate tax-id is rejected
- **GIVEN** an existing qualification with `taxId` `NL001234567B01`
- **WHEN** `registerSupplier()` is called with the same `taxId`
- **THEN** a RuntimeException is thrown and no second record is persisted

### Requirement: REQ-PG-004: A framework-agreement call-off exceeding the ceiling MUST be blocked

`FrameworkAgreementDrawdownGuard::assertWithinCeiling()` MUST fail-closed: when a
PurchaseOrder carries a `frameworkAgreementId`, `createPurchaseOrder()` MUST throw
and persist no PurchaseOrder if `drawnAmount + poTotal > ceilingAmount`, or the
agreement is not `active`, or the order date is outside `validFrom`/`validUntil`.
A call-off within the remaining ceiling MUST increment `drawnAmount`.

#### Scenario: Call-off past the remaining ceiling is blocked
- **GIVEN** an `active` FrameworkAgreement with `ceilingAmount` 5 000 000 cents and `drawnAmount` 4 800 000 cents
- **WHEN** `createPurchaseOrder()` is called with that `frameworkAgreementId` and a 300 000-cent total
- **THEN** a RuntimeException is thrown and no PurchaseOrder is persisted

#### Scenario: Call-off within the remaining ceiling draws down
- **GIVEN** the same agreement
- **WHEN** a 100 000-cent call-off is recorded
- **THEN** `drawnAmount` becomes 4 900 000 cents
