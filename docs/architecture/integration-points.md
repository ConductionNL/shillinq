# Architecture: Integration Points

This document describes the external integration points and webhook endpoints
exposed by Shillinq for integration with third-party systems.

## Inventory Cycle Count — Mobile Scanner Webhook (T4, future)

**Status:** Documented for T4 integration; not implemented in T2.  
**Spec:** `openspec/changes/inventory-cycle-count/specs/inventory-cycle-count/spec.md` (REQ-ICC-009)

The primary path for count-line data entry in T2 is manual input via the UI. The
webhook shape below is documented as the integration point for a future T4
mobile-scanner app.

### Endpoint

```
POST /api/cycle-count/{countId}/count-line
```

### Request Body

```json
{
  "sku": "SKU-4521",
  "countedQuantity": 145,
  "timestamp": "2026-05-20T14:30:00Z",
  "deviceId": "scanner-001"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `sku` | string | Yes | Product SKU being scanned |
| `countedQuantity` | number | Yes | Quantity counted by the scanner |
| `timestamp` | string (ISO 8601) | Yes | When the count was captured on device |
| `deviceId` | string | No | Scanner device identifier for audit trail |

### Response

```json
{
  "lineId": "CC-2026-05-00001-001",
  "countId": "CC-2026-05-00001",
  "sku": "SKU-4521",
  "countedQuantity": 145,
  "status": "recorded"
}
```

### Design Notes

- The `InventoryCycleCount` must be in `counting` state for the webhook to accept input.
- Multiple scan events for the same SKU within a count overwrite the previous
  `countedQuantity` (last-write wins at the line level).
- The `InventoryCycleCountLine.requiresReason` calculation fires automatically
  after each update; the UI reflects the updated flag in real time.
- Authentication: standard Nextcloud session or app-password; the scanner device
  must authenticate with a valid Nextcloud account that has `warehouse-staff` role.
- **T2 scope**: This endpoint is documented only. Implementation is deferred to T4
  along with the mobile scanner app. In T2, operators enter `countedQuantity` manually
  via the Shillinq UI.

---

*See also:* `openspec/changes/inventory-cycle-count/design.md` §D6 for the
deferral rationale, and the `inventory-cycle-count` spec REQ-ICC-009 for the
full acceptance scenario.
