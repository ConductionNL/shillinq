# Design — Inventory Mobile Scanner PWA

## Context

Warehouse operations are among the most time-sensitive and error-prone activities in inventory management. Currently, staff rely on:
- Printed pick lists → error-prone manual transcription
- Desktop check-ins → requires leaving the warehouse floor
- Batch uploads → hours of latency between physical count and system update

The two dependency specs (`inventory-stock-tracking` and `inventory-barcode-sku`) provide:
1. Real-time InventoryStock schema with location-aware quantities
2. Barcode → SKU resolution and validation

This spec delivers the **mobile app, offline layer, and sync protocol** to make real-time warehouse operations practical for field staff with intermittent network access.

The app is **spec-only** at this stage. Implementation lands via `opsx-apply` per the task list; this doc explains the design choices.

## Goals

- **Offline-first**: staff complete operations without network; sync when reconnected
- **Real-time barcode scanning**: capture data 10× faster than manual entry
- **Conflict-resistant sync**: concurrent updates don't corrupt data; last-write-wins is deterministic
- **Role-based access**: warehouse_manager (receive), inventory_operator (transfer), counter (count) with permission checks at sync boundary
- **Visual feedback**: staff always know sync status, pending changes, and conflicts
- **Zero downtime**: PWA continues to work even if the app server is temporarily unavailable

## Non-Goals

- **Offline resilience for 3+ days** — staff sync at least daily (warehouse is open 5 days/week); >3 days offline is not a warehouse use case
- **Cross-organization transfers** — a single organization manages its own locations; inter-org transfers are future work
- **Real-time push notifications** — server-pushed alerts for low stock are future work
- **Voice picking** — audio-directed picking is a later specialization
- **Labor analytics** — time-per-operation is tracked at the backend via audit trail; mobile app doesn't instrument it

## Decisions

### D1 — Offline-first: IndexedDB local cache + delta-sync protocol

Every warehouse operation (scan, quantity change, location transfer) writes to the local IndexedDB first. Network I/O (sync) happens asynchronously in the background. This eliminates network latency as a blocking wait.

**Local schema** (IndexedDB):
```
inventoryStock (sku, location, quantity, lastModified)
inventoryItem (sku, name, category, unitPrice)
location (code, name, warehouse)
pendingOps (id, type, sku, location, oldQty, newQty, timestamp, synced)
```

**Sync protocol**:
- `GET /inventory/sync?since=<timestamp>` — server returns all InventoryStock records modified since timestamp. Client merges: if local version is newer (lastModified), keep local; else take server version.
- `POST /inventory/sync` — client sends all pendingOps with `transactionId` (UUID). Server writes to InventoryStock, marks pendingOps as synced, returns ACK. Client deletes synced rows from pendingOps.
- Sync runs every 30 seconds if network available, or on manual trigger if offline.

**Alternative considered**: Real-time sync to server with optimistic rendering. Rejected — warehouse network is intermittent; staff would spend time waiting for ACKs, defeating the offline-first benefit.

### D2 — Conflict resolution: Last-Write-Wins (LWW) by server timestamp

If both client and server modify the same InventoryStock.quantity concurrently, the server's timestamp wins. Client sees "your local change was overwritten" in the UI but can re-apply the change immediately (staff doesn't lose data, just sees the last-write).

**Example**:
- 14:00 Client scans +5 units locally (server sees 100, client's lastModified = 14:00:00)
- 14:00:01 Server is updated by another client to +3 units (server's lastModified = 14:00:01, quantity = 103)
- 14:05 Network reconnects, sync runs: client sees server lastModified > local, applies server value (103), displays "synced, your local +5 was overwritten; current qty is 103"
- Staff can manually correct if needed (e.g., re-scan the +5)

LWW is deterministic and simple. The alternative (operational-transformation, CRDT) is overkill for warehouse use (changes are not that frequent, and manual review of conflicts is acceptable).

**Alternative considered**: Client-always-wins or server-always-wins without timestamp. Rejected — either choice can silently corrupt inventory. LWW with server truth is standard (AWS DynamoDB, Firebase, Exact, AFAS).

### D3 — Barcode scanning: QR + 1D decoding with fallback to manual entry

The app uses the device camera to scan QR codes and 1D barcodes (EAN-13, Code-128). Decoding is client-side (jsQR for QR, quagga for 1D). If decoding fails or camera is unavailable, staff enter the barcode/SKU manually (text input).

SKU resolution is delegated to the dependent spec `inventory-barcode-sku` — the resolver returns a match or "not found" error.

**Workflow**:
1. Staff taps "Scan Barcode" button
2. Live camera preview appears
3. Barcode detected → auto-submit or tap to confirm
4. SKU lookup via barcode-sku resolver (network call, must be cached offline)
5. If lookup fails, offer manual SKU text entry

**Why QR + 1D?** Warehouse codes may be affixed as QR (newer facilities) or 1D EAN/Code-128 (legacy). Supporting both avoids customer friction.

**Why client-side decoding?** Instant feedback (no network latency), works offline, and the libraries are lightweight (~50 KB).

**Alternative considered**: Server-side barcode decoding via image upload. Rejected — requires round-trip network call for every scan (defeats offline), and image upload is slow on mobile networks.

### D4 — Four operations as distinct Vue components with shared InventoryStock mutations

Each operation (Receive, Transfer, Pick, Count) is a separate Vue component with its own UI flow. All four mutate the same InventoryStock local cache but with different permission guards:

| Operation | Mutates | Permission | Workflow |
|-----------|---------|-----------|----------|
| **Receive** | InventoryStock qty ↑ + GoodsReceipt | warehouse_manager | scan → qty → confirm → post |
| **Transfer** | InventoryStock qty (decr source, incr dest) | inventory_operator | select source → scan → select dest → confirm |
| **Pick** | InventoryStock qty ↓ + order mark-picked | inventory_operator | scan or select → reduce qty → confirm |
| **Count** | InventoryStock qty ↑/↓ + reconciliation | counter | manual entry or scan → compare variance → confirm |

Each operation is idempotent: if staff scans the same barcode twice in 5 seconds, the second scan is rejected ("already scanned in this batch"). This is enforced at the sync boundary via `transactionId` deduplication.

**Alternative considered**: Single "generic" operation that handles all four. Rejected — warehouse staff are trained on specific flows (receive is different from transfer); separate UIs reduce errors.

### D5 — Permission gates at the sync boundary, not the local operation boundary

Local writes are not permission-gated (staff can scan anything locally). Permission checks happen at sync time: before uploading pendingOps to the server, the app verifies that the user role (warehouse_manager, inventory_operator, counter) matches the operation type. If not, the operation is rejected and a toast shows "you don't have permission for this operation."

**Why not gate local writes?** Offline-first means staff might not have connectivity when starting an operation; checking permissions would require network. Deferring checks to sync time ensures staff can work offline and permission checks happen when network is available.

**Alternative considered**: Pre-fetch permissions on app launch. Rejected — permissions can change (user role updated); checking at sync time is fresher.

### D6 — Service worker: cache-first for assets, network-first for API

Service worker strategy:
- **Static assets** (JS, CSS, HTML): cache-first (serve from cache if available, fall back to network)
- **API calls** (GET /inventory/sync, barcode-sku lookup, SKU metadata): network-first (try network first, fall back to stale cache)
- **Icons & images**: cache-first with 7-day expiry

This ensures the app shell loads instantly offline, but inventory data is always as fresh as the last sync.

**Alternative considered**: Stale-while-revalidate (serve cache immediately, update in background). Rejected — inventory data must be fresh; staff shouldn't pick items that don't exist.

### D7 — Conflict UI: show sync status badge, manual review on LWW overwrite

App chrome includes a sync status indicator (top-right badge):
- 🟢 **Green "synced"** — last sync completed < 2 min ago
- 🟡 **Yellow "pending"** — changes pending upload, last sync > 2 min ago or sync in progress
- 🔴 **Red "offline"** — no network (based on `navigator.onLine`)

When an LWW conflict occurs (server overwrote local change):
1. Toast notification: "Stock was updated by another user (103 units, 14:00:01). Your local change (+5) was merged."
2. User can tap "review" to see both versions side-by-side
3. If staff disagree, they immediately re-scan to adjust

This is non-blocking (staff can continue scanning) but visible (staff are aware of the change).

**Alternative considered**: Block all operations until conflict is resolved. Rejected — too disruptive; warehouse staff need to keep moving.

### D8 — Manifest & PWA installability: shortcut per operation

App manifest (`src/manifest.json`) includes:
- App name: "Inventory Mobile"
- Icons: 192×192 and 512×512 PNG
- Shortcuts (home screen quick-launch):
  - "Receive" → /mobile/receive
  - "Transfer" → /mobile/transfer
  - "Pick" → /mobile/pick
  - "Count" → /mobile/count
- Display: "standalone" (full-screen, no browser chrome)
- Orientation: "portrait"

Staff "Add to Home Screen" → app installs as a standalone app → tapping "Receive" shortcut launches straight to the receive form. This reduces friction (no login loop, no navigation).

**Alternative considered**: Single web app URL. Rejected — shortcuts reduce navigation steps; every second counts in a warehouse.

## Seed Data

Example inventory for testing:

**Warehouse locations (Location entity)**:
```json
[
  {"code": "WH-A1", "name": "Warehouse A, Aisle 1", "warehouse": "Amsterdam", "organization": "org-123"},
  {"code": "WH-A2", "name": "Warehouse A, Aisle 2", "warehouse": "Amsterdam", "organization": "org-123"},
  {"code": "WH-B1", "name": "Warehouse B, Aisle 1", "warehouse": "Benelux Hub", "organization": "org-123"}
]
```

**Inventory items (InventoryItem entity)**:
```json
[
  {"sku": "WIDGET-001", "name": "Blue Widget", "category": "Widgets", "unitPrice": 12.50, "currency": "EUR"},
  {"sku": "GADGET-002", "name": "Silver Gadget", "category": "Gadgets", "unitPrice": 25.00, "currency": "EUR"},
  {"sku": "PART-003", "name": "Replacement Part", "category": "Parts", "unitPrice": 5.75, "currency": "EUR"}
]
```

**Stock levels (InventoryStock entity)**:
```json
[
  {"sku": "WIDGET-001", "location": "WH-A1", "quantity": 45, "lastRestockDate": "2026-05-19", "status": "active"},
  {"sku": "GADGET-002", "location": "WH-A1", "quantity": 12, "lastRestockDate": "2026-05-18", "status": "active"},
  {"sku": "WIDGET-001", "location": "WH-A2", "quantity": 8, "lastRestockDate": "2026-05-15", "status": "active"}
]
```

## Phases

### Phase 1: Data & Sync Layer (Week 1–2)
- Implement IndexedDB schema
- Implement delta-sync protocol: GET /inventory/sync, POST /inventory/sync
- Implement transactionId deduplication on server
- Unit tests for sync conflict resolution (LWW)

### Phase 2: Barcode Scanning (Week 3)
- Barcode scanner component (camera, QR + 1D decoding, manual fallback)
- SKU resolver integration (depend on inventory-barcode-sku spec)
- Camera permission handling

### Phase 3: Operations UI (Week 3–4)
- Receive operation component
- Transfer operation component
- Pick operation component
- Count operation component
- Shared InventoryStock mutations

### Phase 4: Offline & Sync UX (Week 4)
- Service worker registration
- Sync status indicator
- Conflict UI
- Manual sync button + retry logic
- Optimistic updates (show result immediately, sync in background)

### Phase 5: Permissions & Security (Week 5)
- RBAC gate at sync boundary (warehouse_manager, inventory_operator, counter)
- Permission checks per operation type
- Audit trail logging (via OpenRegister audit-immutable)

### Phase 6: Testing (Week 5–6)
- Integration tests: offline simulation, sync conflict scenarios
- Permission tests: role-based access control
- Barcode recognition tests (EAN-13, QR, Code-128)
- PWA manifest & service worker tests

## Data Flow Example: Receive Operation

**Scenario**: Staff scans 50 units of WIDGET-001 into Warehouse A, Aisle 1 (WH-A1).

**Step 1: Local Write (offline)**
```
User taps "Receive" → enters location WH-A1 → scans barcode WIDGET-001 → enters qty 50 → taps "Confirm"

Local IndexedDB mutation:
- pendingOps.insert({
    id: uuid(),
    type: "receive",
    sku: "WIDGET-001",
    location: "WH-A1",
    oldQty: 45,
    newQty: 95,  // 45 + 50
    timestamp: 2026-05-21T14:23:00Z,
    synced: false
  })
- inventoryStock.update({
    sku: "WIDGET-001",
    location: "WH-A1",
    quantity: 95,  // optimistic update
    lastModified: 2026-05-21T14:23:00Z
  })

UI shows: "✓ Received 50 units (pending sync)"
```

**Step 2: Background Sync (when network available)**
```
Service worker wakes every 30 seconds, calls sync():

POST /api/v1/inventory/sync {
  "operations": [
    {
      "id": "<uuid>",
      "type": "receive",
      "sku": "WIDGET-001",
      "location": "WH-A1",
      "oldQty": 45,
      "newQty": 95,
      "timestamp": "2026-05-21T14:23:00Z",
      "transactionId": "<uuid>"
    }
  ]
}

Server receives:
- Checks auth: user is warehouse_manager? ✓
- Checks idempotency: transactionId already processed? No ✓
- Writes InventoryStock.quantity = 95 for (WIDGET-001, WH-A1)
- Creates GoodsReceipt record
- Returns ACK with server timestamp

Client receives ACK:
- pendingOps.update(id, { synced: true })
- UI shows: "✓ Synced at 14:23"
```

**Step 3: Conflict Scenario (Concurrent Edit)**
```
Assume at 14:23:00, another warehouse staff updated WIDGET-001@WH-A1 to 98 units.

Local edit timestamp: 2026-05-21T14:23:00Z (client)
Server edit timestamp: 2026-05-21T14:22:59Z (server)

When sync runs:
- GET /inventory/sync?since=<last_sync_time>
- Server returns: WIDGET-001@WH-A1 quantity=98, lastModified=2026-05-21T14:22:59Z
- Client compares: local lastModified (14:23:00) > server (14:22:59)
- Client keeps local value (95) due to LWW
- No conflict, sync continues

BUT if server edit was later:
- Server returns: WIDGET-001@WH-A1 quantity=98, lastModified=2026-05-21T14:24:00Z
- Client compares: local lastModified (14:23:00) < server (14:24:00)
- Client overwrites local with server value (98)
- UI shows: "Stock was updated by another user. Current: 98 units."
- Staff can re-scan +5 if they disagree
```

## Testing Strategy

### Unit Tests
- Sync conflict resolution (LWW): local newer → keep local; server newer → overwrite
- TransactionId deduplication: duplicate sync post is rejected
- IndexedDB schema initialization
- Barcode decoding (jsQR, quagga mock inputs)

### Integration Tests
- Offline scenario: complete receive operation, no network, verify pendingOps written
- Online scenario: perform operation, network available, verify sync completes
- Concurrent edits: two clients edit same InventoryStock, verify LWW resolution
- Permission gate: inventory_operator attempts receive operation, verify sync rejected with 403

### E2E Tests (Selenium/Playwright)
- Full receive flow: scan barcode, enter qty, confirm, verify UI synced badge
- Transfer flow: select source location, scan barcode, select destination, confirm
- Pick flow: scan order item, reduce qty, confirm
- Count flow: manual entry, compare variance
- Offline simulation: disable network mid-operation, verify operation completes locally, re-enable network, verify sync
- Barcode scanning: use QR code / EAN-13 image capture, verify decoded correctly

### Permission Tests
- warehouse_manager role: can receive ✓
- inventory_operator role: cannot receive ✗, can transfer ✓
- counter role: can count ✓, cannot transfer ✗

## Open Design Questions

1. **Multi-location sync scope** — should the sync only download InventoryStock for the current location, or all locations? Scoping reduces data transfer and sync time, but staff occasionally need to look up items in other locations. REQ-SYNC-005 assumes location-scoped; confirm during implementation.

2. **Barcode-SKU resolver offline capability** — does inventory-barcode-sku support offline resolution (cached barcode lookup) or require network? If network-required, manual SKU entry is the fallback. Clarify during spec integration.

3. **Quota management UX** — if IndexedDB quota is exceeded, should staff auto-clear old counts, or is a warning sufficient? REQ-DATA-001 declares auto-clear of >7-day-old records; confirm this is acceptable.

4. **Sync frequency** — 30 seconds is a reasonable default, but should it be configurable per warehouse (high-volume vs. low-volume)? REQ-SYNC-006 assumes fixed 30s; future work to make tunable.

