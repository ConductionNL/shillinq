# Integration Points

This page lists the documented integration points exposed by the shillinq
backend that are **shaped now but not implemented yet**, so partner teams
(mobile-scanner app, third-party WMS, POS integrators) can plan against a
stable contract without blocking the core T2 work.

Each section names the originating OpenSpec change, the tier the
implementation is scheduled for, and the wire contract.

---

## Cycle-count mobile scanner

- **Origin:** `inventory-cycle-count` (REQ-ICC-009)
- **Tier:** T4 (mobile)
- **Primary path today:** manual count-line entry via the Cycle Counts
  detail page in the manifest (REQ-ICC-003 + REQ-ICC-006).

The cycle-count register is designed so a mobile-scanner companion app can
push per-scan observations into an open count batch over a single REST
endpoint. The endpoint is **not yet served** — the spec documents the
shape so partner teams can build against the stable contract.

### Endpoint

```
POST /index.php/apps/shillinq/api/cycle-count/{countId}/count-line
Accept: application/json
Content-Type: application/json
```

### Request body

```json
{
  "sku": "SKU-4521",
  "countedQuantity": 145,
  "timestamp": "2026-05-20T14:30:00Z",
  "deviceId": "scanner-001"
}
```

| Field             | Type     | Required | Purpose                                                                                                       |
| ----------------- | -------- | -------- | ------------------------------------------------------------------------------------------------------------- |
| `sku`             | string   | yes      | Product SKU being counted. MUST match an InventoryCycleCountLine in the count for the operator's administration. |
| `countedQuantity` | number   | yes      | Physical count observed at the scanner. MUST be >= 0.                                                          |
| `timestamp`       | string   | yes      | ISO-8601 UTC of the scan. Used for audit on the line's `notes` field.                                          |
| `deviceId`        | string   | no       | Optional opaque scanner identifier. Recorded on the line's `notes` field when present.                         |

### Response body (planned)

```json
{
  "lineId": "CC-2026-05-00001-001",
  "countId": "CC-2026-05-00001",
  "sku": "SKU-4521",
  "countedQuantity": 145,
  "requiresReason": false,
  "status": "recorded"
}
```

`requiresReason` is recomputed server-side (REQ-ICC-004) so the mobile
client can prompt the operator for a reason code in-line, before the
supervisor reviews the count.

### Behaviour

1. Authenticate as a user with `warehouse_manager` or `inventory`
   permission on the InventoryCycleCountLine schema (`x-openregister-rbac`,
   REQ-ICC-005).
2. Reject when the count is not in state `submitted` or `counting`
   (REQ-ICC-006).
3. Locate the InventoryCycleCountLine matching (administrationId,
   countId, sku) — there's exactly one per (sku, locationId) pair in a
   given count batch.
4. Patch `countedQuantity` with the new value; recompute
   `countedValue` / `quantityVariance` / `valueVariance` /
   `requiresReason` via `OCA\Shillinq\Service\CycleCountService::recalculateLine`.
5. Return the refreshed line.

### Why deferred

Server-side wiring of the controller + RBAC + multi-scan idempotency
key (which scan wins when two scanners hit the same SKU?) requires its
own design pass and is owned by the T4 mobile-scanner roadmap. The
endpoint is recorded here so the contract stays stable across
intervening releases.

---

## Authoring conventions

- Every documented future endpoint links back to the originating spec
  + REQ id so reviewers can trace contract changes.
- Endpoints not yet served MUST be flagged "planned" in the section
  heading and SHOULD include a "Why deferred" subsection.
- When an endpoint goes live, move it to the per-area docs (eg.
  `docs/api/`) and replace the section here with a one-line back
  reference.
