# Design: purchase-requisition

## Architecture Overview

Two new OR schemas, `Requisition` and `RequisitionLine`
(`lib/Settings/register.d/purchase-requisition.json`), model the
pre-purchase-order "aanvraag" step: an employee raises a `Requisition` with
one or more `RequisitionLine` items, submits it, an approver approves or
rejects it, and an approved `Requisition` converts into a `PurchaseOrder`
(member 02 of `bookkeeping-purchase-order-3way`, unchanged as a class —
only its `createPurchaseOrder()` payload contract gains an optional
`requisitionId` field).

```
employee                     approver                    (system)
   |                             |                            |
   | createRequisition           |                            |
   |----------------------------------------------------------|
   |  RequisitionService::createRequisition()                 |
   |  -> Requisition{statusCode: draft} + RequisitionLine[]    |
   |                             |                            |
   | submitRequisition           |                            |
   |----------------------------------------------------------|
   |  RequisitionService::submitRequisition()                 |
   |  -> Requisition{statusCode: submitted}                   |
   |                             |                            |
   |                             | approveRequisition          |
   |                             |----------------------------|
   |                             | RequisitionService::approveRequisition()
   |                             | -> BudgetBlocker::canCommit(object: $requisition)
   |                             |    (REUSED, unmodified, in-process call)
   |                             | -> Requisition{statusCode: approved,
   |                             |    approvedBy, approvedAt}
   |                             |                            |
   |                             | convert (POST .../convert)  |
   |                             |----------------------------|
   |                             | RequisitionConversionService::
   |                             | convertToPurchaseOrder()
   |                             | -> RequisitionConversionGuard::canConvert()
   |                             |    (fail-closed defence in depth)
   |                             | -> PurchaseOrderService::createPurchaseOrder()
   |                             |    (REUSED, unmodified)
   |                             | -> Requisition{statusCode: converted,
   |                             |    convertedPurchaseOrderId, convertedAt}
   |                             | -> PurchaseOrder{requisitionId: <back-link>}
```

## Why the budget check MUST be in-process (the `purchaseq` decision)

The task brief that originally scoped purchase-requisition management to a
standalone `purchaseq` app assumed cross-app boundaries the way every other
`purchaseq`↔`shillinq` integration in this ecosystem works: `purchaseq`
would write a `Verplichting` (or an equivalent commitment record) and
`shillinq` would react to it asynchronously via ADR-041 cross-app events.
That pattern works for "purchaseq created a PO, shillinq should book a
verplichting eventually" — it does NOT work for "can this employee's
requisition be approved right now, given the current free budget room and
the approver's mandate?" A requisition-approval click needs a **synchronous
answer** the UI can act on immediately (approve, or refuse with "exceeds
available budget"); ADR-041 events are fire-and-forget with no request/
response contract, so a `purchaseq`-hosted `RequisitionService.
approveRequisition()` would have had exactly two options, both bad:

1. **Duplicate the budget/mandate engine inside `purchaseq`** — re-implement
   `Budget`, `Mandaat`, free-room arithmetic, and override-mandate
   resolution as a second, `purchaseq`-owned copy. This directly violates
   ADR-031's "don't reimplement business logic that already exists
   elsewhere" rule and creates exactly the kind of two-copies-drift bug
   class this codebase has hit before (see the `segregation-control-real-
   check` and `or-objectservice-findall-signature-and-fake-drift` incidents
   — a second, independently-evolving copy of a business rule silently goes
   stale).
2. **Make a blocking synchronous HTTP call from `purchaseq` into `shillinq`
   on every approval click** — this is not a real synchronous in-process
   call, it's a network round-trip with its own availability/timeout/auth
   failure modes, and still cannot participate in the same PHP-level
   fail-closed try/catch `RequisitionService::approveRequisition()` already
   uses for every other guard in this codebase.

Building the requisition sub-ledger **inside `shillinq`**, next to the
`BudgetBlocker`/`MandaatEnforcer` it depends on, makes the budget check a
plain PHP method call:

```php
if ($this->budgetBlocker->canCommit(verplichtingsnummer: $requisitionId, object: $requisition) === false) {
    throw new RuntimeException('Requisition exceeds available budget');
}
```

`BudgetBlocker::canCommit()` is schema-agnostic by construction — it reads
`programma`/`boekjaar`/`totaalbedrag_excl_btw`/`soort`/`administrationId`
off whatever `$object` array it is handed, rather than hardcoding a
`Verplichting` OR-lookup. `Requisition` deliberately carries that exact
field contract (enforced by `PurchaseRequisitionFragmentTest::
testRequisitionCarriesBudgetBlockerFieldContract()`), so the guard runs
verbatim against a `Requisition` — no adapter, no duplicated arithmetic, no
network hop, no eventual-consistency window between "approved" and "budget
actually checked".

## `purchaseq` retirement

`purchaseq` (`apps-extra/purchaseq`) is an OpenSpec-only scaffold with no
`lib/` — it never shipped any purchase-requisition code. It is retired via a
companion Codeberg issue (not archived/deleted by this change — that is an
org-admin action left to the user) rather than migrated, because there was
nothing to migrate: this change is the requisition capability's first and
only implementation.

## Declarative-vs-imperative decision (ADR-031)

`submit` (`draft -> submitted`) and `approve`/`reject`
(`submitted -> approved|rejected`) are declared in
`x-openregister-lifecycle` with `requires:` guards
(`OCA\Shillinq\Lifecycle\BudgetBlocker::canCommit` for `approve`) — these
are genuine state-only transitions (plus, for `approve`, a precondition
check) that the generic OR lifecycle engine can express. `RequisitionService`
additionally exposes dedicated `submitRequisition()`/`approveRequisition()`/
`rejectRequisition()` methods (surfaced via `RequisitionController`) because
`approve`/`reject` need to stamp extra fields the generic engine's
`requires:` guard contract does not set (`approvedBy`, `approvedAt`,
`rejectedBy`, `rejectionReason`) — the declarative lifecycle block documents
and defends the transition rules; the controller/service pair is the
actual state-mutation path the manifest UI drives.

`convertToPO` (`approved -> converted`) is the ADR-031 imperative exception:
converting a `Requisition` into a `PurchaseOrder` is not a state-only
transition, it **creates a new cross-schema record** (a `PurchaseOrder` with
its own `po_number`, approval chain, and notifications) **and writes that
new record's id back** onto the `Requisition`
(`convertedPurchaseOrderId`) — precisely the category of behaviour ADR-031
reserves for an imperative service, the same exception already documented
for `AansluitingService::compute()` and for `PurchaseOrderService::
blockSendUntilApproved()` itself (which also mutates `lifecycleState`
outside the generic transition engine). `RequisitionConversionGuard::
canConvert()` is still wired as the transition's declarative `requires:`
precondition — defence in depth so a direct call to the generic OR
transition endpoint cannot flip a non-approved `Requisition` straight to
`converted` — but the actual materialisation only happens through
`RequisitionConversionService::convertToPurchaseOrder()`, which is why the
manifest UI's "Convert to purchase order" button is wired as a custom
`api-call` action against the dedicated controller endpoint
(`POST /apps/shillinq/api/requisitions/{id}/convert`), not as a
`lifecycle-transition` action against the generic engine (see Risk 1 in
proposal.md).

## Seed Data

`lib/Settings/register.d/purchase-requisition.json` seeds one `Requisition`
+ `RequisitionLine` pair per lifecycle-reachable status the seed data can
represent without a follow-on action: `draft` (REQ-2026-adm-demo-000001, no
`preferredSupplierId` — not required until submit), `submitted`
(REQ-2026-adm-demo-000002), and `approved` (REQ-2026-adm-demo-000003, WITH a
`preferredSupplierId` since that is required to convert). `rejected` and
`converted` are not seeded as static rows because they are the terminal
result of an action (reject / convert) rather than a distinct starting
state a demo environment benefits from pre-populating; both are covered by
unit tests instead (`RequisitionServiceTest::
testRejectRequisitionSetsRejected`,
`RequisitionConversionServiceTest::
testApprovedRequisitionConvertsToLinkedPurchaseOrder`).

Every seeded `Requisition.totaalbedrag_excl_btw` equals the sum of its
`RequisitionLine.lineTotal` rows (enforced by
`PurchaseRequisitionFragmentTest::testSeedDataCoversLifecycleStates()`) and
fits within the free room of the seeded `programma=5.1`/`boekjaar=2026`
`Budget` record already shipped by
`bookkeeping-verplichtingenadministratie.json` (geautoriseerd_bedrag
500,000.00 EUR, gerealiseerd_bedrag 25,000.00 EUR → 475,000.00 EUR / 47.5M
cents free room) — so the seed data is internally consistent with the real,
unmodified `BudgetBlocker` a demo environment would actually run.

## Trade-offs

Considered giving `Requisition` its own independent approval-routing engine
(thresholds, approver roles) rather than reusing `BudgetBlocker`/
`MandaatEnforcer`. Rejected per ADR-022/ADR-031: `Verplichting` already has
a working, tested budget/mandate gate with the exact field contract a
requisition needs; building a second one would be the same reimplementation
risk this change exists specifically to avoid (see "Why the budget check
MUST be in-process" above). The one intentional behavioural difference from
`Verplichting`'s own `indienen` transition: `submitRequisition()` always
routes through human approval regardless of mandate sufficiency — a
`Requisition` never auto-skips to `approved` the way a sufficiently-mandated
`Verplichting` can, because a requisition is, by definition, a request that
has not yet been authorised by anyone.

## Nextcloud Integration

- Services: `RequisitionService`, `RequisitionConversionService`,
  `RequisitionConversionGuard` — constructed via Nextcloud's container
  (`ContainerInterface`, `IAppConfig`, `LoggerInterface`, plus the reused
  `BudgetBlocker`/`AdministrationContextService`/`PurchaseOrderService`),
  matching every other service in this app.
- Controller: `RequisitionController` — standard `OCP\AppFramework\
  Controller`, 5 routes registered in `appinfo/routes.php`.
- Events/Hooks: none new — `RequisitionConversionService` calls
  `PurchaseOrderService::createPurchaseOrder()` directly, which dispatches
  its own existing approver notifications unmodified.
- Manifest UI: `Requisitions` (index) + `RequisitionDetail` (detail) pages,
  nav entry under "Purchasing & Inventory" (order 4, immediately before
  "Purchase Orders").

## Security Considerations

- Fail-closed throughout (CWE-863/OWASP A01:2021): `approveRequisition()`
  never approves when `BudgetBlocker::canCommit()` returns `false` or
  throws; `RequisitionConversionGuard::canConvert()` returns `false` (deny)
  on a missing requisition, a non-approved status, or a lookup exception.
- IDOR (ADR-005): every `RequisitionService`/`RequisitionConversionService`
  method scopes reads/writes through `AdministrationContextService::
  canAccess()`; cross-tenant access is masked as "not found", never a
  distinct 403, so a caller cannot enumerate which administrations exist.
- Server-authoritative fields: `requester` (create), `approvedBy`/
  `approvedAt` (approve), `rejectedBy` (reject) are always derived from the
  authenticated session (`IUserSession::getUser()->getUID()`), never trusted
  from the request body.
- `require_approved_requisition_for_po` defaults OFF so this change cannot
  itself break any existing PO-creation caller; enabling it is an explicit,
  separate configuration action.

## File Structure

```
lib/
  Settings/register.d/
    purchase-requisition.json          (new — 2 schemas + seed data)
  Service/
    RequisitionService.php             (new)
    RequisitionConversionService.php   (new)
    PurchaseOrderService.php           (modified — requisitionId + policy gate)
  Lifecycle/
    RequisitionConversionGuard.php     (new)
  Controller/
    RequisitionController.php          (new)
appinfo/
  routes.php                           (modified — 5 new routes)
src/
  manifest.json                        (modified — 2 new pages + nav entry)
tests/
  Unit/Settings/
    PurchaseRequisitionFragmentTest.php        (new)
  Unit/Service/
    RequisitionServiceTest.php                 (new)
    RequisitionConversionServiceTest.php       (new)
  Unit/Lifecycle/
    RequisitionConversionGuardTest.php         (new)
```
