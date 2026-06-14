# Spec: Inventory Mobile Scanner (PWA, offline)

## Metadata
- **Change**: inventory-mobile-scanner
- **Type**: PWA Feature (Mobile-first app with offline support)
- **Dependencies**: inventory-stock-tracking, inventory-barcode-sku
- **Status**: Specification phase

## User Stories

### Story 1: Receive Goods (High Priority)
**As a** warehouse receiving clerk
**I want to** quickly log received goods by scanning barcodes and entering quantities
**So that** stock levels are updated in real-time and receiving delays are minimized

**Acceptance Criteria**:

#### GIVEN: Warehouse staff with "warehouse_manager" role, offline
#### WHEN: Staff scans a product barcode (QR or 1D code)
#### THEN: 
- Barcode is decoded within 2 seconds (jsQR or quagga)
- SKU is resolved via inventory-barcode-sku service
- Product name and current quantity (WH-A1) are displayed
- User can confirm or cancel the scan

#### GIVEN: Product found and quantity confirmed
#### WHEN: Staff enters received quantity and taps "Confirm"
#### THEN:
- InventoryStock.quantity for (SKU, location) is incremented locally
- A GoodsReceipt record is created locally with (timestamp, sku, location, quantity, user)
- UI shows "✓ Received 50 units (pending sync)"
- pendingOps entry is created for sync

#### GIVEN: Network becomes available
#### WHEN: Sync runs automatically
#### THEN:
- POST /api/v1/inventory/sync uploads pendingOps with transactionId
- Server deduplicates on transactionId
- Server writes InventoryStock and GoodsReceipt
- Client receives ACK with server timestamp
- pendingOps is marked synced
- UI updates to "✓ Synced at 14:23"

#### GIVEN: Barcode scan fails (poor image quality)
#### WHEN: Staff sees "Barcode not recognized" message
#### THEN:
- Manual SKU entry field appears
- Staff types SKU manually
- SKU lookup proceeds as normal

---

### Story 2: Transfer Inventory Between Locations (Medium Priority)
**As a** warehouse operator
**I want to** move inventory from one location to another (e.g., restocking aisle 2 from bulk storage)
**So that** stock is distributed efficiently and pickers have items ready

**Acceptance Criteria**:

#### GIVEN: Warehouse operator with "inventory_operator" role
#### WHEN: Staff taps "Transfer" operation
#### THEN:
- UI prompts "From location?" with list of locations
- User selects location (WH-A1)

#### GIVEN: Source location selected
#### WHEN: Staff scans item barcode
#### THEN:
- SKU is resolved and current quantity at source is displayed
- UI prompts "To location?" with list of locations

#### GIVEN: Destination location selected
#### WHEN: Staff enters transfer quantity (e.g., 20 units)
#### THEN:
- Two InventoryStock mutations occur locally:
  - Decrement (SKU, from_location) by 20
  - Increment (SKU, to_location) by 20
- UI shows "✓ Transferred 20 units WH-A1 → WH-A2 (pending sync)"

#### GIVEN: Sync runs
#### WHEN: Server receives the transfer operation
#### THEN:
- Permission check: is user inventory_operator? ✓
- Both InventoryStock records are updated atomically (or both fail)
- An InventoryTransfer record is created with (from, to, sku, qty, timestamp)
- Client receives ACK

#### GIVEN: User attempts transfer but is not inventory_operator
#### WHEN: Sync runs
#### THEN:
- Sync returns 403 Forbidden
- UI shows "You don't have permission to transfer inventory"
- pendingOps remains unsync'd for manual review

---

### Story 3: Pick Items for Order (High Priority)
**As a** warehouse picker
**I want to** scan items and confirm quantities for outgoing orders
**So that** orders are picked accurately and shipped on time

**Acceptance Criteria**:

#### GIVEN: Picker with "inventory_operator" role, with an order list visible
#### WHEN: Staff scans a barcode matching an order item
#### THEN:
- SKU is resolved
- Current quantity at this location is displayed
- UI prompts "Quantity to pick?" with default = order qty

#### GIVEN: User confirms pick quantity
#### WHEN: Staff taps "Confirm"
#### THEN:
- InventoryStock.quantity is decremented locally
- Order line item is marked "picked"
- UI shows "✓ Picked 1 × Widget (pending sync)"

#### GIVEN: User scans the same barcode again within 5 seconds
#### WHEN: Sync deduplication runs
#### THEN:
- Duplicate transactionId is detected
- Second scan is rejected with "Already scanned in this batch"
- No double-decrement occurs

#### GIVEN: Picked quantity > available quantity
#### WHEN: Staff attempts to confirm
#### THEN:
- UI shows warning "Only 5 units available, you entered 10"
- User can reduce quantity or cancel

---

### Story 4: Inventory Count & Reconciliation (Medium Priority)
**As a** inventory counter
**I want to** manually count physical inventory and compare to system quantities
**So that** discrepancies are identified and resolved

**Acceptance Criteria**:

#### GIVEN: Counter with "counter" role, at a location
#### WHEN: Staff taps "Count" operation
#### THEN:
- UI prompts "SKU or scan barcode?"
- Counter can enter SKU manually or scan barcode

#### GIVEN: SKU is resolved
#### WHEN: Staff enters physical count quantity (e.g., 42 units)
#### THEN:
- System shows "System qty: 45, Physical count: 42"
- Variance is -3 units
- UI prompts "Create reconciliation record?"

#### GIVEN: Reconciliation is confirmed
#### WHEN: Staff taps "Save"
#### THEN:
- A InventoryCount record is created locally with (sku, location, systemQty, physicalQty, variance, timestamp, user)
- InventoryStock.quantity is updated to physical count (42)
- UI shows "✓ Count recorded: -3 units (pending sync)"

#### GIVEN: Multiple counts for the same SKU/location
#### WHEN: Sync runs
#### THEN:
- All InventoryCount records are uploaded with unique transactionIds
- Server deduplicates and creates records for each count
- No loss of variance history

---

### Story 5: Offline Operation & Sync Status (Medium Priority)
**As a** warehouse staff
**I want to** see sync status and know when my changes are safely on the server
**So that** I have confidence in data integrity

**Acceptance Criteria**:

#### GIVEN: Network is available
#### WHEN: Staff completes an operation
#### THEN:
- Sync status badge (top-right) shows 🟢 "Green: Synced < 2 min"

#### GIVEN: Network becomes unavailable (WiFi disconnected)
#### WHEN: Staff scans another item
#### THEN:
- UI shows "Offline" notification
- Sync badge turns 🔴 "Red: Offline"
- Operation completes locally (pendingOps written)
- UI shows "⏳ Pending sync"

#### GIVEN: Network becomes available again
#### WHEN: Sync runs
#### THEN:
- Sync badge turns 🟡 "Yellow: Syncing"
- Sync completes (all pendingOps uploaded, ACK'd, deleted)
- Badge turns 🟢 "Green: Synced at 14:35"
- No manual intervention required

#### GIVEN: Staff wants to manually trigger sync
#### WHEN: Staff taps the sync badge (or a "Sync now" button)
#### THEN:
- Manual sync is triggered immediately
- If network unavailable, shows "No network available"
- If sync fails, shows "Sync failed; retry in X seconds"

#### GIVEN: User clears browser data or uninstalls app
#### WHEN: App is reinstalled and opened
#### THEN:
- IndexedDB is re-initialized from last synced state (from server)
- pendingOps with synced=true are discarded
- pendingOps with synced=false (if any) are retried
- No data loss (source of truth is server)

---

### Story 6: Barcode Scanning with Camera (High Priority)
**As a** warehouse staff
**I want to** scan barcodes using my phone's camera
**So that** I avoid manual entry errors and work faster

**Acceptance Criteria**:

#### GIVEN: Device has a camera
#### WHEN: Staff taps "Scan" button
#### THEN:
- Camera permission prompt appears (first time only)
- User grants permission
- Live camera preview is displayed
- Instructions: "Point at barcode"

#### GIVEN: Barcode is visible in camera frame
#### WHEN: Barcode is decoded (QR or 1D)
#### THEN:
- Barcode value is auto-captured within 2 seconds
- Camera feed closes automatically
- SKU resolution proceeds

#### GIVEN: Camera permission denied
#### WHEN: Staff tries to scan
#### THEN:
- "Camera permission denied" message appears
- Manual SKU/barcode entry textbox is shown instead
- User can type SKU manually

#### GIVEN: Barcode decoding fails (blurry, poor lighting, etc.)
#### WHEN: Staff is looking at an unreadable barcode
#### THEN:
- After 5 seconds without detection, a "Can't read barcode" button appears
- User taps button to switch to manual entry
- User types barcode or SKU manually

#### GIVEN: Multiple barcode formats are in use (EAN-13, QR, Code-128)
#### WHEN: Staff scans any format
#### THEN:
- jsQR detects QR codes
- quagga detects 1D barcodes (EAN-13, Code-128, Code-39, etc.)
- Any valid format is decoded correctly

---

## Requirements

### Requirement: REQ-OFFLINE-001: Offline-First Architecture
The app MUST complete all warehouse operations (receive, transfer, pick, count) without network connectivity. Local writes to IndexedDB MUST occur before any sync attempt. Sync MUST be asynchronous and non-blocking.

**Rationale**: Warehouse network is intermittent. Staff cannot afford to wait for network acknowledgments during fast-paced operations.

#### Scenario:
GIVEN: Network is unavailable
WHEN: Staff scans barcode and taps "Confirm"
THEN: Operation completes locally within 500 ms, UI shows "pending sync", and staff can continue to next operation

---

### Requirement: REQ-OFFLINE-002: Sync Delta Protocol
The app MUST sync changes using a delta protocol:
- `GET /api/v1/inventory/sync?since=<timestamp>` — returns all InventoryStock records modified since timestamp
- `POST /api/v1/inventory/sync` — uploads pendingOps with transactionId; server deduplicates on transactionId

**Rationale**: Delta-based sync is bandwidth-efficient and supports offline reconnection.

#### Scenario:
GIVEN: 50 pendingOps in client cache after 2 hours offline
WHEN: Network becomes available and sync runs
THEN: Client sends 50 operations to server, server ACKs, client deletes synced records in under 10 seconds

---

### Requirement: REQ-OFFLINE-003: Conflict Resolution — Last-Write-Wins
When both client and server modify the same InventoryStock record concurrently, the timestamp-based last-write-wins (LWW) conflict resolution MUST apply: the version with the later timestamp is preserved. Client MUST compare `lastModified` timestamps and overwrite local if server is newer.

**Rationale**: LWW is deterministic and simple. Manual review is available if needed, but auto-apply prevents data loss.

#### Scenario:
GIVEN: Client edits InventoryStock.quantity at 14:23:00; server edits at 14:24:00
WHEN: Sync runs and server returns the later version
THEN: Client overwrites local quantity with server value and displays "Stock was updated by another user. Current: 98 units."

---

### Requirement: REQ-SYNC-001: Transaction ID Deduplication
Every operation uploaded via `POST /api/v1/inventory/sync` MUST include a unique `transactionId` (UUID). Server MUST deduplicate on transactionId: duplicate requests within 24 hours MUST return the same ACK without re-applying the operation.

**Rationale**: Network retries and app crashes can cause duplicate uploads. Deduplication prevents double-counting.

#### Scenario:
GIVEN: Client uploads receive operation with transactionId X, then network fails
WHEN: App retries and sends same operation with same transactionId
THEN: Server detects duplicate, returns same ACK without incrementing quantity twice

---

### Requirement: REQ-SYNC-002: Idempotent Operations
All four operations (receive, transfer, pick, count) MUST be idempotent: running the same operation twice MUST result in the same final state. Receiving 50 units twice is not valid; duplicate scans within 5 seconds MUST be rejected.

**Rationale**: Prevents accidental duplicates if staff taps "confirm" twice due to slow network feedback.

#### Scenario:
GIVEN: Staff scans barcode, sees "pending sync" (but no toast notification yet due to lag)
WHEN: Staff taps "Confirm" again
THEN: App checks if same (SKU, qty, location) was submitted in last 5 seconds, rejects with "Already submitted; waiting for server ACK"

---

### Requirement: REQ-BARCODE-001: Camera-Based Barcode Scanning
The app MUST support barcode scanning using the device's camera. The following formats MUST be decoded:
- QR codes (jsQR library)
- 1D barcodes: EAN-13, Code-128, Code-39, Code-93 (quagga library)

Decoding MUST occur client-side within 2 seconds. If decoding fails, manual text entry MUST be provided as fallback.

**Rationale**: Barcode scanning is 10× faster than manual entry. Client-side decoding eliminates network latency.

#### Scenario:
GIVEN: Staff scans EAN-13 barcode with device camera
WHEN: Barcode is visible in frame for > 1 second
THEN: jsQR or quagga decodes it, camera closes, and SKU resolution proceeds

---

### Requirement: REQ-BARCODE-002: Manual SKU Entry Fallback
If camera is unavailable, disabled, or barcode decoding fails, the app MUST provide a manual text input field where staff can enter the barcode or SKU directly. This field MUST be searchable (filter matching SKU or product name).

**Rationale**: Some locations lack adequate lighting or camera functionality. Manual entry is the fallback.

#### Scenario:
GIVEN: Camera permission is denied
WHEN: Staff taps "Scan" button
THEN: Camera is not accessed; manual entry textbox appears with placeholder "Enter barcode or SKU"

---

### Requirement: REQ-SKU-001: SKU Resolution via Barcode
The app MUST resolve scanned barcodes to SKUs using the dependent spec `inventory-barcode-sku` service. If barcode is not found, the app MUST display "Barcode not found in system" and offer manual SKU entry.

**Rationale**: Barcode → SKU mapping is external; this spec delegates to the upstream service.

#### Scenario:
GIVEN: Staff scans barcode "5901234123457"
WHEN: SKU resolver is called
THEN: Resolver returns SKU "WIDGET-001" or "not found" error; app displays product name or error accordingly

---

### Requirement: REQ-INVENTORY-001: Receive Operation
The receive operation MUST increment InventoryStock.quantity for a given (SKU, location) and create a GoodsReceipt record. Permission gate: only users with "warehouse_manager" role.

**Rationale**: Goods receipt is a critical operation; only managers should authorize.

#### Scenario:
GIVEN: Warehouse manager scans barcode "WIDGET-001", enters qty 50, location "WH-A1"
WHEN: Confirm is tapped
THEN:
- InventoryStock (WIDGET-001, WH-A1) quantity is incremented by 50 locally
- GoodsReceipt record is created with (sku, location, qty, timestamp, user)
- pendingOps entry is queued for sync

---

### Requirement: REQ-INVENTORY-002: Transfer Operation
The transfer operation MUST decrement InventoryStock at source location and increment at destination location. A single atomic InventoryTransfer record MUST be created. Permission gate: only users with "inventory_operator" role.

**Rationale**: Transfers require auditing; a single record tracks both sides.

#### Scenario:
GIVEN: Operator selects from WH-A1, scans WIDGET-001, selects to WH-A2, enters qty 20
WHEN: Confirm is tapped
THEN:
- InventoryStock (WIDGET-001, WH-A1) decrements by 20
- InventoryStock (WIDGET-001, WH-A2) increments by 20
- InventoryTransfer record is created
- Both mutations are in same pendingOp batch (atomic)

---

### Requirement: REQ-INVENTORY-003: Pick Operation
The pick operation MUST decrement InventoryStock.quantity and mark an order line item as picked. Permission gate: "inventory_operator" role.

**Rationale**: Picking is a fast operation; operators execute it.

#### Scenario:
GIVEN: Picker with order list, scans barcode "WIDGET-001", enters qty 1
WHEN: Confirm is tapped
THEN:
- InventoryStock (WIDGET-001, current_location) decrements by 1
- Order line item is marked picked=true
- pendingOp is queued

---

### Requirement: REQ-INVENTORY-004: Count Operation
The count operation MUST capture physical inventory count, compare to system quantity, compute variance, and create an InventoryCount record. Permission gate: "counter" role. The operation MUST NOT automatically correct InventoryStock; a manual review step is required.

**Rationale**: Counts are reference points for variance, not automatic corrections.

#### Scenario:
GIVEN: Counter scans SKU, enters physical count 42; system shows 45
WHEN: Counter taps "Save"
THEN:
- Variance is computed: 42 - 45 = -3
- InventoryCount record is created with variance
- Counter is prompted "Update InventoryStock to 42?" (not auto-applied)

---

### Requirement: REQ-DATA-001: IndexedDB Local Cache
The app MUST cache InventoryStock, InventoryItem, and Location data in IndexedDB with the following schema:

```
inventoryStock: { sku, location, quantity, lastModified, status }
inventoryItem: { sku, name, category, unitPrice, currency }
location: { code, name, warehouse, organization }
pendingOps: { id, type, sku, location, oldQty, newQty, timestamp, transactionId, synced }
```

**Rationale**: IndexedDB enables offline reads and atomic batch writes.

#### Scenario:
GIVEN: App is offline
WHEN: Staff selects location "WH-A1"
THEN: App queries IndexedDB for all InventoryStock records at WH-A1 and displays them immediately (< 100 ms)

---

### Requirement: REQ-DATA-002: Quota Management
The app MUST monitor IndexedDB quota usage and warn staff if usage exceeds 80% of available quota (typically 50 MB). If quota is exceeded, the app MUST automatically delete InventoryCount records older than 7 days to free space. Staff MUST be notified of auto-cleanup.

**Rationale**: Large volume counts can exceed IndexedDB limits on older devices.

#### Scenario:
GIVEN: IndexedDB usage is 44 MB (88% of 50 MB)
WHEN: App computes quota
THEN: Warning toast appears "Storage nearly full; old counts will be cleared automatically"

---

### Requirement: REQ-PERM-001: Role-Based Access Control
The app MUST enforce role-based permission gates at the sync boundary. The three roles are:

| Role | Allowed Operations |
|------|-------------------|
| warehouse_manager | Receive |
| inventory_operator | Transfer, Pick |
| counter | Count |

A user attempting an operation without permission MUST receive a 403 error from the server during sync, and the operation MUST be rejected locally with a user-friendly message.

**Rationale**: Different warehouse roles have different responsibilities. Permissions prevent unauthorized operations.

#### Scenario:
GIVEN: Operator with inventory_operator role attempts a receive operation
WHEN: Sync runs
THEN: Server returns 403 "Permission denied: receive requires warehouse_manager role"

---

### Requirement: REQ-PERM-002: Audit Trail
Every operation (receive, transfer, pick, count) MUST be logged in the audit trail (via OpenRegister's audit-trail-immutable abstraction) with:
- Operation type
- User ID / username
- Timestamp
- Affected records (SKU, location, quantities before/after)
- Sync ACK timestamp (when server confirmed)

**Rationale**: Audit trail is required for compliance and variance investigation.

#### Scenario:
GIVEN: Staff receives 50 units at 14:23
WHEN: Audit trail is queried
THEN: Record shows "Receive | user: john.smith | WIDGET-001 | WH-A1 | qty: 50 | system_confirmed: 14:23:05"

---

### Requirement: REQ-UI-001: Sync Status Indicator
The app MUST display a persistent sync status badge in the top-right corner:
- 🟢 **Green** (Synced): last sync completed < 2 min ago
- 🟡 **Yellow** (Pending): pending changes exist or last sync > 2 min ago
- 🔴 **Red** (Offline): `navigator.onLine === false`

Tapping the badge MUST trigger a manual sync attempt. If sync fails, the badge MUST show a retry countdown.

**Rationale**: Staff need to know if their changes are safe on the server.

#### Scenario:
GIVEN: Staff completes a receive operation
WHEN: Sync completes
THEN: Badge turns green and shows "Synced at 14:23"

---

### Requirement: REQ-UI-002: Conflict Notification
When a server write overwrites a local change (LWW conflict), the app MUST display a non-blocking toast notification:
"Stock was updated by another user (102 units, 14:01:00). Your local change (+50) was merged."

The notification MUST include a "Review" link to see both versions side-by-side. The notification MUST NOT block further operations.

**Rationale**: Staff need to be aware of overwrites but shouldn't be blocked from continuing work.

#### Scenario:
GIVEN: LWW conflict occurs during sync
WHEN: Conflict is resolved
THEN: Toast shows "Stock was updated…" and staff can continue scanning

---

### Requirement: REQ-UI-003: PWA Manifest & Install
The app MUST ship as a Progressive Web App with:
- Web app manifest at `src/manifest.json` with name "Inventory Mobile"
- 192×192 and 512×512 PNG app icons
- Shortcuts for each operation (Receive, Transfer, Pick, Count) accessible from home screen
- Display mode: "standalone" (full-screen, no browser chrome)
- Orientation: "portrait"

**Rationale**: PWA install removes the need for app store distribution and provides native-like UX.

#### Scenario:
GIVEN: Staff visits the app in a browser
WHEN: Browser detects manifest
THEN: "Add to Home Screen" prompt appears; tapping it installs the app; home screen shortcut "Receive" opens the receive form directly

---

### Requirement: REQ-SW-001: Service Worker & Offline Cache
The app MUST register a service worker that implements:
- **Static assets** (JS, CSS, HTML): cache-first strategy (serve from cache if available)
- **API calls** (GET /inventory/sync, barcode-sku lookup): network-first strategy (try network first, fall back to cached data)
- **Icons & images**: cache-first with 7-day expiry

The service worker MUST run sync every 30 seconds if network is available.

**Rationale**: Service worker enables offline-first behavior and background sync.

#### Scenario:
GIVEN: App is opened after 3 days offline
WHEN: Network becomes available
THEN: Service worker automatically syncs all pending operations in the background

---

## Implementation Notes

- All four operations (receive, transfer, pick, count) share the same InventoryStock mutation logic but with different permission gates and audit log categories.
- The barcode scanner component is reusable across all operations; it returns a resolved SKU or manual entry.
- Sync is non-blocking; operations complete locally before any network I/O.
- The dependent specs `inventory-stock-tracking` and `inventory-barcode-sku` must be implemented first; this spec consumes their outputs.
- All timestamps use ISO 8601 UTC format (e.g., `2026-05-21T14:23:00Z`).
- TransactionIds are UUIDs (RFC 4122).
- All monetary amounts (unitPrice) are stored as EUR (from ADR-000); multi-currency support is future work.

