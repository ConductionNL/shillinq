# purchase-requisition Specification

**Status**: done
**Scope**: shillinq
**OpenSpec changes**:
- purchase-requisition (2026-07-14, archived)

## Purpose

Adds a purchase-requisition (aanvraag) sub-ledger: an employee raises a
`Requisition` before a `PurchaseOrder` exists, submits it for approval, an
approver approves or rejects it — gated by the existing, unmodified
`BudgetBlocker`/`MandaatEnforcer` commitment infrastructure so the budget
check is a synchronous, in-process call, never a cross-app event — and an
approved `Requisition` converts into a `PurchaseOrder` with a traceability
link both ways. This capability was originally scoped to a standalone
`purchaseq` app; it ships here instead because requisition approval needs an
in-process budget query `purchaseq` could not make synchronously. See
`openspec/changes/archive/2026-07-14-purchase-requisition/design.md` for
the full ADR-031 decision rationale and seed-data trade-offs.

## Requirements

### Requirement: REQ-REQ-001: Requisition and RequisitionLine schemas MUST carry the BudgetBlocker field contract

`Requisition` MUST declare `programma`, `boekjaar`, `totaalbedrag_excl_btw`,
`soort`, and `administrationId` — the exact fields `OCA\Shillinq\Lifecycle\
BudgetBlocker::canCommit()` and `OCA\Shillinq\Lifecycle\MandaatEnforcer`
already read off a `Verplichting` — so those guards run unmodified against a
`Requisition` object. `RequisitionLine` MUST declare integer-cent
`unitPrice` and a computed `lineTotal` (ADR-022).

#### Scenario: Requisition declares the shared budget field contract
- **GIVEN** `lib/Settings/register.d/purchase-requisition.json`
- **WHEN** the `Requisition` schema is inspected
- **THEN** `programma`, `boekjaar`, `totaalbedrag_excl_btw`, `soort`, and `administrationId` are all declared properties, with `programma`/`boekjaar`/`soort` required

#### Scenario: Seed data covers every lifecycle-reachable starting status
- **GIVEN** the fragment's seed `objects`
- **WHEN** filtered to schema `Requisition`
- **THEN** at least one seeded Requisition exists with `statusCode` `draft`, `submitted`, and `approved`, each with a matching `RequisitionLine` whose `lineTotal` sums to `totaalbedrag_excl_btw`

@e2e exclude: schema/seed-data declaration only, verified by `PurchaseRequisitionFragmentTest`; no dedicated UI to drive for a static fixture check.

### Requirement: REQ-REQ-002: Requisition approval MUST reuse BudgetBlocker synchronously and fail closed

`RequisitionService::approveRequisition()` MUST call `BudgetBlocker::
canCommit()` as a plain in-process method call against the `Requisition`
object, and MUST NOT approve when that call returns `false` or throws.
`submitRequisition()` MUST always route to `submitted` regardless of mandate
sufficiency — a `Requisition` never auto-skips to `approved`.

**Renamed 2026-08-20 by `budget-core-schema`:** the matched schema was
`Budget`, colliding with an unrelated `Budget` declared by
`bookkeeping-provincies-bbv-variant`; renamed to `CommitmentBudget`
(`design.md` §1). This requirement's substance is unchanged.

#### Scenario: A requisition within the matching CommitmentBudget's free room is approved
- **GIVEN** a submitted Requisition with `programma=5.1`, `boekjaar=2026`, `totaalbedrag_excl_btw=500000`
- **AND** a CommitmentBudget for `programma=5.1`/`boekjaar=2026` with sufficient free room
- **WHEN** `approveRequisition()` runs
- **THEN** it returns the Requisition with `statusCode='approved'`, `approvedBy` and `approvedAt` set

#### Scenario: A requisition exceeding the matching CommitmentBudget's free room is blocked
- **GIVEN** a submitted Requisition requesting more than the matching CommitmentBudget's free room
- **WHEN** `approveRequisition()` runs
- **THEN** it throws "Requisition exceeds available budget"
- **AND** the Requisition's `statusCode` remains `submitted` (never silently approved)

#### Scenario: A blank rejection reason is refused
- **GIVEN** a submitted Requisition
- **WHEN** `rejectRequisition()` runs with a blank/whitespace-only reason
- **THEN** it throws "rejectionReason is required" and the Requisition is not mutated

@e2e exclude: backend service logic proven against a REAL, unmodified BudgetBlocker (ADR-009) via `RequisitionServiceTest`; no dedicated Playwright suite exists yet for this manifest page type in this app.

### Requirement: REQ-REQ-003: Converting an approved Requisition to a PurchaseOrder MUST be fail-closed and MUST reuse PurchaseOrderService unmodified

`RequisitionConversionGuard::canConvert()` MUST return `false` for any
`Requisition` not in status `approved`, for a missing `Requisition`, and on
any lookup exception. `RequisitionConversionService::
convertToPurchaseOrder()` MUST delegate PurchaseOrder creation to
`PurchaseOrderService::createPurchaseOrder()` unmodified, and MUST write the
new PurchaseOrder's id back onto the Requisition as
`convertedPurchaseOrderId` while the new PurchaseOrder carries the
Requisition's id as `requisitionId` (link intact both ways).

#### Scenario: An unapproved requisition cannot convert
- **GIVEN** a Requisition with `statusCode` in `draft`, `submitted`, `rejected`, or `converted`
- **WHEN** `convertToPurchaseOrder()` runs
- **THEN** it throws "Requisition must be approved before it can be converted to a purchase order"
- **AND** no PurchaseOrder is created

#### Scenario: An approved requisition converts with the link intact
- **GIVEN** an approved Requisition with a `preferredSupplierId` and at least one `RequisitionLine`
- **WHEN** `convertToPurchaseOrder()` runs
- **THEN** the returned PurchaseOrder's `requisitionId` equals the Requisition's id
- **AND** the returned Requisition has `statusCode='converted'`, `convertedPurchaseOrderId` equal to the new PurchaseOrder's id, and `convertedAt` set

#### Scenario: An approved requisition with no preferred supplier cannot convert
- **GIVEN** an approved Requisition with a blank `preferredSupplierId`
- **WHEN** `convertToPurchaseOrder()` runs
- **THEN** it throws before calling `PurchaseOrderService::createPurchaseOrder()`

@e2e exclude: backend service logic proven against a REAL, unmodified PurchaseOrderService (ADR-009) via `RequisitionConversionServiceTest`/`RequisitionConversionGuardTest`.

### Requirement: REQ-REQ-004: PurchaseOrderService MAY optionally require an approved Requisition, gated by a default-OFF policy flag

`PurchaseOrderService::createPurchaseOrder()` MUST accept an optional
`requisitionId` payload field and persist it unchanged when the
`require_approved_requisition_for_po` app-config flag is `false` (default).
When the flag is `true`, `createPurchaseOrder()` MUST refuse a blank
`requisitionId` and MUST refuse a `requisitionId` that does not resolve to a
`Requisition` in the same administration whose `statusCode` is `approved`
or `converted`.

#### Scenario: The policy is inert by default
- **GIVEN** the `require_approved_requisition_for_po` flag is unset (default `false`)
- **WHEN** `createPurchaseOrder()` runs with no `requisitionId`
- **THEN** it succeeds exactly as before this change

#### Scenario: The policy blocks a PO with no traceable approved requisition, when enabled
- **GIVEN** the flag is `true`
- **WHEN** `createPurchaseOrder()` runs with a blank `requisitionId`, or one that resolves to a Requisition that is not `approved`/`converted`
- **THEN** it throws before persisting the PurchaseOrder

@e2e exclude: backend policy-gate logic; no dedicated UI toggle shipped in this change (app-config only).

### Requirement: REQ-REQ-005: Every requisition endpoint MUST be IDOR-safe and server-authoritative

`RequisitionController` MUST reject anonymous callers (401), mask
cross-tenant access as not-found (404), and MUST derive `requester`/
`approvedBy`/`rejectedBy` from the authenticated session — never from the
request body.

#### Scenario: Cross-tenant access is masked as not-found
- **GIVEN** an authenticated caller with no access to administration `adm-1`
- **WHEN** any Requisition endpoint is called with `administrationId=adm-1`
- **THEN** the response is 404 with a generic "not found" message, not a distinct "forbidden" status

@e2e exclude: security/IDOR backend contract, proven by `RequisitionServiceTest`/`RequisitionConversionServiceTest`'s cross-tenant denial cases; no dedicated Playwright suite for this manifest page type in this app yet.

### Requirement: REQ-REQ-006: The convert-to-purchase-order UI action MUST NOT be exposed as a generic lifecycle-transition

The manifest UI's "Convert to purchase order" action MUST call the
dedicated `POST /apps/shillinq/api/requisitions/{id}/convert` controller
endpoint, MUST NOT be declared as a `lifecycle-transition` action against
OR's generic transition engine, because the generic engine can only flip
`statusCode` and cannot materialise the new `PurchaseOrder` record
`RequisitionConversionService` creates.

#### Scenario: manifest.json wires convert as a custom action, not a lifecycle transition
- **GIVEN** `src/manifest.json`
- **WHEN** the `RequisitionDetail` page's `actions` array is inspected
- **THEN** the `convert-to-purchase-order` action has `type: "api-call"` with `url` pointing at the dedicated controller endpoint, not `type: "lifecycle-transition"`

@e2e exclude: manifest configuration, not runtime behaviour; verified by inspection/JSON-parse validation.
