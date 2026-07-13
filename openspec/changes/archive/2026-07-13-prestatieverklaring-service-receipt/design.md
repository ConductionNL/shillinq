# Design: prestatieverklaring-service-receipt

## Architecture Overview

Member 12 of the `bookkeeping-purchase-order-3way` chain. Sits alongside
member 04 (`GoodsReceiptNoteService`) as an alternative third leg feeding
member 06 (`ThreeWayMatchingEngine`). The engine currently resolves its
third leg exclusively from `GoodsReceiptNote`/`GoodsReceiptLine`; this
change adds a second, symmetric resolution path from
`SvcReceipt`/`SvcReceiptLine` and merges both pools before line matching,
so `matchLineItems()` / `calculateDivergence()` / `evaluateTolerance()` are
untouched — they already operate on a generic "receipt line" shape
(`quantityAccepted`, `quantityReceived`).

```
PurchaseOrderLine ──┬── (goods) ── GoodsReceiptLine ── GoodsReceiptNote
                     │                                        │
                     └── (service) ─ SvcReceiptLine ── SvcReceipt
                                                                │
                     SupplierInvoice ───────────────────────── ThreeWayMatchingEngine
```

## API Design

### `POST /api/service-receipts`
**Request:**
```json
{
  "administrationId": "adm-1",
  "poIds": ["po-2"],
  "approver": "controller-01",
  "periodStart": "2026-07-01",
  "periodEnd": "2026-07-31"
}
```
**Response:** `201` — the persisted `SvcReceipt` (`statusCode: "draft"`).

### `POST /api/service-receipts/{id}/lines`
**Request:**
```json
{
  "administrationId": "adm-1",
  "poLineId": "pol-svc-1",
  "periodStart": "2026-07-01",
  "periodEnd": "2026-07-31",
  "percentageComplete": 10000,
  "notes": "July retainer delivered in full"
}
```
**Response:** `201` — the persisted `SvcReceiptLine`, with `quantityAccepted`
derived server-side (see D3).

### `POST /api/service-receipts/{id}/confirm`
Transitions `draft → confirmed` (the approver named on the receipt
confirms delivery). **Response:** `200` — updated `SvcReceipt`.

### `POST /api/service-receipts/{id}/accept`
Transitions `confirmed → accepted` and recomputes the originating PO's
receipt lifecycle (mirrors `GoodsReceiptNoteService::acceptGRN()` minus the
StockMove posting — services never move inventory). **Response:** `200`.

## Database Changes

No native tables — new OpenRegister schemas only (`SvcReceipt`,
`SvcReceiptLine`), declared declaratively in
`lib/Settings/register.d/bookkeeping-purchase-order-3way-12-service-receipt.json`
per ADR-037 (per-change register fragment, never edit `shillinq_register.json`
directly). Schema slugs are abbreviated (`SvcReceipt`/`SvcReceiptLine`
rather than `ServiceReceipt`/`ServiceReceiptLine`) to stay at or under the
fleet's existing 40-character magic-table-name high-water mark
(`oc_openregister_shillinq_supplierinvoice`); `ServiceReceiptLine` would
have produced a 43-character table name, one longer than any existing
shillinq table.

## Nextcloud Integration

- Controllers: `ServiceReceiptController` (mirrors `GoodsReceiptNoteController`)
- Services: `ServiceReceiptService` (new), `ThreeWayMatchingEngine` (modified)
- Mappers/Entities: none — OpenRegister `ObjectService` only (ADR-022)
- Events/Hooks: none — the matching engine pulls both receipt types
  synchronously at match time; no event bus is introduced

## Security Considerations

Identical posture to `GoodsReceiptNoteService`: every read/write is scoped
to `administrationId` via `AdministrationContextService::canAccess()`
(cross-tenant refs masked as 404, ADR-005); `approver` is derived from the
validated session (`AdministrationContextService::currentUserId()`), never
trusted from the request body, matching how `receivedBy`/`inspector` are
already handled in `GoodsReceiptNoteService`.

## File Structure

```
lib/
  Controller/
    ServiceReceiptController.php          (new)
  Service/
    ServiceReceiptService.php             (new)
    ThreeWayMatchingEngine.php             (modified)
  Settings/register.d/
    bookkeeping-purchase-order-3way-12-service-receipt.json  (new)
appinfo/
  routes.php                              (modified)
tests/Unit/Service/
  ServiceReceiptServiceTest.php           (new)
  ThreeWayMatchingEngineTest.php          (modified)
```

## Declarative-vs-imperative decision (ADR-031)

**Decision: imperative `ServiceReceiptService`, declarative lifecycle +
calculations only.**

The `SvcReceipt` lifecycle state machine (`draft → confirmed → accepted /
rejected`) is declared declaratively via `x-openregister-lifecycle` in the
member-12 register fragment — no imperative state-machine code, matching
`GoodsReceiptNote`'s existing pattern.

The *transition-time behaviour* (deriving `quantityAccepted` from
percentage/amount/quantity confirmation, cross-validating `poLineId`
belongs to the receipt's `poIds`, recomputing the parent PO's cumulative
receipt lifecycle across every period) remains imperative, for the same
reason `GoodsReceiptNoteService` is imperative rather than a pure
declarative aggregation:

- **Server-authoritative identity derivation** — `approver` must come from
  the validated session, never the request body; OR's declarative
  aggregations have no concept of "the calling user."
- **Cross-object cross-validation** — `poLineId` must belong to one of the
  receipt's own `poIds[]`; this is a relational integrity check across two
  FK hops, not a single-object calculation `x-openregister-calculations`
  can express.
- **Cumulative multi-record aggregation with branching** — recomputing
  `partial_received`/`fully_received`-equivalent PO status requires
  summing `SvcReceiptLine.quantityAccepted` across every accepted
  `SvcReceipt` targeting a PO line and comparing against
  `quantityOrdered`; `x-openregister-aggregations` supports single-target
  sums but not this per-line branching outcome, matching the same
  limitation that already justifies `GoodsReceiptNoteService::
  updatePurchaseOrderReceiptLifecycle()` being imperative.

This is not a new precedent — it is the existing GRN precedent applied
symmetrically to the second receipt type feeding the same matching engine.

## Decisions

### D1 — SvcReceiptLine reuses GoodsReceiptLine's field names verbatim

`SvcReceiptLine` carries `quantityReceived`/`quantityAccepted` — the exact
field names `ThreeWayMatchingEngine::calculateDivergence()` already reads
off `GoodsReceiptLine` — rather than inventing service-specific field
names (`percentageDelivered` etc. are also stored, but as *input*, not as
what the matching engine reads). **Alternative considered:** give the
matching engine a `receiptType` branch reading different field names per
type. Rejected — it would duplicate `calculateDivergence()`'s price/qty/vat
delta logic for zero benefit; the conversion happens once, at write time,
in `ServiceReceiptService`.

### D2 — Confirmation may be expressed as percentage, quantity, or amount

Real service contracts are billed three different ways: percentage of
contract complete (retainers), quantity of a unit-of-measure delivered
(hours), or a direct euro amount (milestone billing). `addServiceReceiptLine()`
accepts any one of `percentageComplete` / `quantityConfirmed` /
`amountConfirmedCents` and derives an effective `quantityAccepted` against
the PO line's `quantityOrdered`:
- `quantityConfirmed` set → used directly
- else `percentageComplete` set → `percentageComplete / 10000 × quantityOrdered`
- else `amountConfirmedCents` set (and `poLine.unitPrice > 0`) →
  `amountConfirmedCents / unitPrice`
- else → validation error (at least one confirmation mode is required)

### D3 — quantityAccepted is derived server-side, not trusted from the request

Mirrors `GoodsReceiptLine`'s `quantityAccepted` trust boundary: the client
supplies the confirmation mode + value, the server computes the
`quantityAccepted` the matching engine will read. This also makes the
per-mode conversion unit-testable in isolation.

### D4 — Partial/periodic confirmation via multiple SvcReceiptLine rows, one per period

A 12-month service contract accrues one `SvcReceipt` (or one `SvcReceiptLine`
per period on a single ongoing `SvcReceipt`) per billing period, exactly as
a partially-shipped goods PO accrues multiple `GoodsReceiptNote`s.
`ServiceReceiptService::updatePurchaseOrderReceiptLifecycle()` sums
`quantityAccepted` across every accepted `SvcReceiptLine` targeting a PO
line, the same integer-thousandths accumulation
`GoodsReceiptNoteService::updatePurchaseOrderReceiptLifecycle()` already
performs.

### D5 — No quality-check step, no StockMove

Services have nothing to physically inspect and never move inventory, so
`SvcReceipt`'s lifecycle skips `GoodsReceiptNote`'s `quality_checked`
state (`draft → confirmed → accepted` only) and
`ServiceReceiptService::acceptServiceReceipt()` never calls anything
resembling `postReceiptStockMove()`.

## Risks / Trade-offs

- [Risk] A PO could carry both goods and service lines and end up with
  both a `GoodsReceiptNote` and a `SvcReceipt` → [Mitigation] the matching
  engine's per-line tuple resolution (`findGrnLine()`/new
  `findSvcReceiptLine()`) is keyed by `poLineId`, so each PO line resolves
  independently against whichever receipt pool has a matching line; a
  mixed-type PO naturally works without special-casing.
- [Risk] `GRIRClearingService` GL postings are not wired to fire from
  either receipt type today (pre-existing gap, confirmed by grepping for
  callers of `createGRIRPosting()` — none exist) → [Mitigation] out of
  scope for this change (see proposal.md Out of Scope); documented so it
  is not silently assumed fixed.

## Migration Plan

Additive only — new registers, new service, new controller, new routes,
one new `OR` branch in `ThreeWayMatchingEngine::evaluateMatch()`. No
existing schema, service, or route is modified in a breaking way. Deploys
via the standard shillinq register.d fragment merge (ADR-037); no manual
migration step. Rollback = revert the four changed/new files; no data to
migrate back since no `SvcReceipt` records exist prior to this change.

## Seed Data

### Schema: `SvcReceipt`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | svc-receipt-consultancy-jul | svc-receipt-maintenance-q3 | svc-receipt-retainer-aug-draft |
| receiptNumber | SVR-2026-adm-1-000001 | SVR-2026-adm-1-000002 | SVR-2026-adm-1-000003 |
| poIds | ["po-service-1"] | ["po-service-2"] | ["po-service-1"] |
| approver | controller-01 | controller-01 | controller-01 |
| periodStart | 2026-07-01 | 2026-07-01 | 2026-08-01 |
| periodEnd | 2026-07-31 | 2026-09-30 | 2026-08-31 |
| statusCode | accepted | accepted | draft |
| administrationId | adm-1 | adm-1 | adm-1 |

### Schema: `SvcReceiptLine`
| Field | Object 1 | Object 2 |
|-------|----------|----------|
| slug | svc-receipt-line-consultancy-jul-1 | svc-receipt-line-maintenance-q3-1 |
| serviceReceiptId | (svc-receipt-consultancy-jul id) | (svc-receipt-maintenance-q3 id) |
| poLineId | (consultancy PO line id) | (maintenance PO line id) |
| percentageComplete | 10000 | 10000 |
| quantityAccepted | 1 | 1 |
| approver | controller-01 | controller-01 |
| confirmedAt | 2026-07-31T17:00:00+02:00 | 2026-09-30T17:00:00+02:00 |
| administrationId | adm-1 | adm-1 |

**Related items per object:** none — service receipts carry no file/photo
attachments (unlike GRN's delivery-condition photos); the approval trail
is the audit-relevant artefact and is captured on the record itself.
