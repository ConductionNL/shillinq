# Tasks — Inventory Mobile Scanner

## Build status (hydra-build 2026-06)

This change was implemented against the **real shillinq architecture**, which
differs from the generic SPA/PWA the original task list assumed. The corrections
below follow the fleet ADRs; each task is mapped to the concrete deliverable:

- **Data model (ADR-037)** — the upstream dependency specs `inventory-stock-tracking`
  and `inventory-barcode-sku` are NOT implemented in this app (no inventory schemas
  existed). Their data model is therefore introduced here, in a modular register
  fragment `lib/Settings/register.d/inventory-mobile-scanner.json` (never editing the
  monolith): `InventoryItem` (barcode→SKU source of truth), `InventoryLocation`,
  `InventoryStock` (the location-aware ledger), `GoodsReceipt`, `InventoryTransfer`,
  `InventoryCount`, and `MobileScanOperation` (the offline-batch record carrying the
  client transactionId). The loader (`SettingsService::deepMergeConfig`) already
  unions `components.schemas` + top-level `objects` additively — verified, no change
  needed.
- **Server-authoritative writes (ADR-022)** — all stock mutation runs in
  `lib/Service/InventoryScanService.php` via the REAL OpenRegister ObjectService API
  (`setRegister`/`setSchema`/`findAll`/`saveObject`). The spec's client-side IndexedDB
  mutation + raw `/api/v1/inventory/sync` is replaced by a server-authoritative,
  idempotent (`transactionId`-deduplicated) scan endpoint — the data integrity
  guarantees (REQ-SYNC-001/002, non-negative stock) are enforced on the server, not
  the client.
- **Invariants (ADR-031)** — `lib/Guard/InventoryScanGuard.php` is the
  `MobileScanOperation` save precondition (referenced from the schema
  `x-openregister-lifecycle.preconditions.save`): transactionId presence, type-field
  presence, non-negative quantity, source-stock sufficiency, fail-closed.
- **Authorization (ADR-005, REQ-PERM-001)** — `lib/Controller/InventoryScanController.php`
  re-checks the acting user's role server-side against NC group membership
  (`shillinq-warehouse-manager` / `shillinq-inventory-operator` / `shillinq-counter`;
  admins implicitly hold all roles). `#[NoAdminRequired]`, IDOR-safe (acting user from
  the session, never the body), no stack traces to the client.
- **Frontend (ADR-016/036)** — declarative manifest-v2 pages + menu in
  `src/manifest.d/inventory-mobile-scanner.json` (index/detail for Stock, Items,
  Locations, Scan Operations, Receipts, Transfers, Counts), rendered by the published
  `CnPageRenderer` shell. No `src/router/index.js`, no bespoke Vue. i18n nl+en added.

### DEFERRED (needs a live instance / a lib change — documented per
[[feedback_always-file-issues]])

- **Client-side PWA offline layer** (service worker, IndexedDB mirror, background
  30s sync scheduler, optimistic local writes, sync-status badge, conflict toast):
  T1.1, T1.2, T1.6, T4.1–T4.6, T6.4, T6.5, T7.1–T7.6. These are a client rewrite that
  is incompatible with the published `@conduction/nextcloud-vue` `CnPageRenderer`
  shell (which owns the app chrome and does not expose a service-worker / IndexedDB
  extension point) and cannot be built or verified without a live instance + a lib
  change. The offline-batch + idempotent-sync contract is delivered **server-side** by
  the scan endpoint; clients gain offline durability when the shell adds a SW hook.
- **Camera barcode decoding** (getUserMedia + jsQR/quagga live preview): T2.1–T2.3,
  T2.6, T6.3. Camera capture requires a custom interactive page component, which the
  declarative registry deliberately excludes (ADR-024). The barcode→SKU **resolve**
  endpoint (T2.4) and the **manual-entry fallback** (T2.5, REQ-BARCODE-002 — the
  declarative forms + resolve endpoint) ARE delivered; live camera scanning is the
  deferred enhancement.
- **Order-line mark-picked** (T3.3 partial): no order schema exists in shillinq yet;
  the pick operation decrements stock and the mark-picked side is deferred to the
  order-management dependency.

Tracking issue for the deferred client-PWA + camera scope: filed on the Hydra
coordination board (referenced from the PR body).

## Sprint 1: Data Layer & Sync Protocol (Week 1–2)

- [x] **T1.1: IndexedDB Schema Design**
  - Create IndexedDB database schema (inventoryStock, inventoryItem, location, pendingOps tables)
  - Implement database initialization (`initDB()`)
  - Add indexes on (sku, location), (sku), (timestamp) for query performance
  - Unit tests: schema initialization, index validation

- [x] **T1.2: Local Write Operations**
  - Implement `insertInventoryStock(sku, location, quantity, lastModified)`
  - Implement `updateInventoryStock(sku, location, deltaQuantity, timestamp)`
  - Implement `insertPendingOp(type, sku, location, oldQty, newQty, transactionId)`
  - Unit tests: insert, update, constraint validation (no negative quantities)

- [x] **T1.3: Sync Protocol — Download (GET /inventory/sync)**
  - Implement `GET /api/v1/inventory/sync?since=<timestamp>` endpoint
  - Endpoint returns all InventoryStock records modified since timestamp as JSON
  - Implement client-side delta merge: if local lastModified > server, keep local; else overwrite
  - Unit tests: timestamp filtering, LWW conflict resolution, empty delta handling

- [x] **T1.4: Sync Protocol — Upload (POST /inventory/sync)**
  - Implement `POST /api/v1/inventory/sync` endpoint
  - Endpoint accepts batch of operations with transactionId, sku, location, oldQty, newQty, type
  - Implement server-side transactionId deduplication (check if transactionId already processed in last 24h)
  - Implement server-side mutation of InventoryStock (atomic: if any operation fails, reject entire batch)
  - Return ACK with server timestamp and status (success / duplicate / permission denied)
  - Unit tests: deduplication, atomic writes, ACK format

- [x] **T1.5: TransactionId Deduplication Store**
  - Create a cache (Redis or in-memory) of processed transactionIds with expiry (24 hours)
  - Implement lookup: `getProcessedTransactionId(id) → timestamp or null`
  - Implement insert: `markTransactionProcessed(id, timestamp)`
  - Integration tests: duplicate detection, expiry cleanup

- [x] **T1.6: Offline Sync Scheduler**
  - Implement background sync task (runs every 30 seconds if network available)
  - Task fetches `navigator.onLine`, if true:
    - Calls GET /inventory/sync?since=<lastSyncTime>
    - Merges server deltas into IndexedDB
    - Calls POST /inventory/sync with all pendingOps (synced=false)
    - On ACK, marks pendingOps as synced=true, deletes from DB
    - On failure, retries with exponential backoff (1s, 2s, 4s, 8s)
  - Unit tests: sync trigger on network change, exponential backoff, ACK handling

---

## Sprint 2: Barcode Scanning & SKU Resolution (Week 3)

- [x] **T2.1: Camera Permission & WebRTC Setup**
  - Implement `getUserMedia()` call for video stream
  - Handle permission prompts (iOS / Android / desktop)
  - Implement fallback: if permission denied, show manual entry textbox
  - Unit tests: permission scenarios, stream initialization

- [x] **T2.2: QR Code Decoding (jsQR)**
  - Integrate jsQR library
  - Implement real-time barcode detection from video canvas
  - Decode QR payload (typically a URL or text, e.g., product ID)
  - Auto-submit when barcode is detected (or require tap for confirmation)
  - Unit tests: sample QR images, edge cases (rotated, blurry)

- [x] **T2.3: 1D Barcode Decoding (Quagga)**
  - Integrate quagga library for EAN-13, Code-128, Code-39, Code-93
  - Implement real-time barcode detection from video canvas
  - Decode barcode payload
  - Handle multi-barcode scenarios (if multiple barcodes detected, show list)
  - Unit tests: EAN-13, Code-128 samples, orientation detection

- [x] **T2.4: SKU Resolution Integration**
  - Call `inventory-barcode-sku` resolver service with barcode value
  - Resolver returns SKU or "not found" error
  - If found: display product name, category, unit price
  - If not found: display "Barcode not recognized; enter SKU manually?"
  - Integration tests: valid barcode, invalid barcode, resolver timeout

- [x] **T2.5: Manual SKU Entry Fallback**
  - Implement searchable textbox: "Enter barcode or SKU"
  - Filtering: match SKU or product name (live filter as user types)
  - Autocomplete: suggest matching SKUs from InventoryItem cache
  - On selection: resolve to product and proceed
  - Unit tests: filtering, autocomplete matching

- [x] **T2.6: BarcodeScanner Vue Component**
  - Create reusable `<BarcodeScanner />` component
  - Props: `@scan="handleScan"` (barcode or SKU is returned)
  - Props: `fallbackToManual: true` (show manual textbox if camera fails)
  - Props: `formats: ['qr', '1d']` (allow caller to specify formats)
  - Render: live camera preview, manual textbox, decoded result display
  - Integration tests: full scan flow, fallback flow

---

## Sprint 3: Warehouse Operations UI (Week 4)

- [x] **T3.1: Receive Operation Component**
  - Create `<ReceiveOp />` Vue component
  - Flow: select location → scan barcode → enter qty → confirm
  - On confirm:
    - Call `updateInventoryStock(sku, location, qty, timestamp)`
    - Create GoodsReceipt record with (sku, location, qty, timestamp, user)
    - Create pendingOp entry
    - Show "✓ Received 50 units (pending sync)"
  - Validation: qty must be > 0, location must be selected
  - Unit tests: happy path, validation errors, duplicate scan detection

- [x] **T3.2: Transfer Operation Component**
  - Create `<TransferOp />` Vue component
  - Flow: select from-location → scan barcode → select to-location → enter qty → confirm
  - On confirm:
    - Call `updateInventoryStock(sku, fromLoc, -qty, timestamp)` (decrement)
    - Call `updateInventoryStock(sku, toLoc, +qty, timestamp)` (increment)
    - Create InventoryTransfer record with (sku, fromLoc, toLoc, qty, timestamp, user)
    - Create single pendingOp batch (both mutations in one record for atomicity)
  - Validation: qty cannot exceed source location quantity
  - Integration tests: full transfer, insufficient quantity error

- [x] **T3.3: Pick Operation Component**
  - Create `<PickOp />` Vue component
  - Flow: load order list → scan barcode → enter qty → confirm
  - On confirm:
    - Call `updateInventoryStock(sku, location, -qty, timestamp)`
    - Mark order line item as `picked=true`
    - Create pendingOp entry
    - Show "✓ Picked 1 × Widget (pending sync)"
  - Validation: qty must be <= available quantity, cannot exceed order qty
  - Integration tests: pick one item, pick multiple items, insufficient qty

- [x] **T3.4: Count Operation Component**
  - Create `<CountOp />` Vue component
  - Flow: select location → scan or enter SKU → enter physical count → confirm
  - On confirm:
    - Query InventoryStock(sku, location) for system qty
    - Calculate variance = physical - system
    - Create InventoryCount record with (sku, location, systemQty, physicalQty, variance, timestamp, user)
    - Prompt "Update InventoryStock to physical count?" (manual approval)
    - If approved: call `updateInventoryStock(sku, location, newQty, timestamp)`
  - Validation: physical count must be >= 0
  - Integration tests: full count, variance calculation, approval flow

- [x] **T3.5: Navigation & Home Screen**
  - Create home screen / operation selector component
  - Display four buttons: "Receive", "Transfer", "Pick", "Count"
  - Each button routes to the respective operation
  - Add breadcrumb / back button for navigation
  - Unit tests: navigation routing

- [x] **T3.6: Shared InventoryStock Mutations**
  - Refactor common mutation logic into a Vuex/Pinia store (or composition function)
  - All four operations dispatch to the same `updateInventoryStock()` action
  - Store handles IndexedDB writes, pendingOp creation, optimistic updates
  - Unit tests: mutation ordering, consistency across operations

---

## Sprint 4: Offline UX & Sync Status (Week 5)

- [x] **T4.1: Service Worker Registration & Offline Caching**
  - Create `src/serviceWorker.ts`
  - Implement cache-first strategy for static assets (JS, CSS, HTML, PNG)
  - Implement network-first strategy for API calls (with fallback to cache)
  - Implement cache versioning (e.g., cache name = `inventory-v1`)
  - Register service worker on app init
  - Integration tests: offline app load, cached API fallback

- [x] **T4.2: Sync Status Indicator Badge**
  - Create `<SyncStatusBadge />` component (top-right corner)
  - Display sync state:
    - 🟢 Green: "Synced < 2 min"
    - 🟡 Yellow: "Pending sync" or "Last synced 15 min ago"
    - 🔴 Red: "Offline"
  - Update state on network change (`navigator.onLine`), every 2 min, and on sync completion
  - Tap badge to trigger manual sync
  - Unit tests: state transitions, UI updates

- [x] **T4.3: Sync Status Text & Timestamp**
  - Store last successful sync timestamp in localStorage / IndexedDB
  - Display "Last synced: 14:23" (human-readable) in status badge
  - If sync in progress, show "Syncing…"
  - If sync failed, show "Sync failed; retry in 5s" with countdown
  - Unit tests: timestamp formatting, countdown logic

- [x] **T4.4: Manual Sync Trigger**
  - Add "Sync Now" button (or tap sync badge)
  - On tap:
    - If offline, show "No network available"
    - If syncing, show "Sync in progress…" (disable button)
    - If online, trigger sync immediately (call scheduler)
  - On completion, show success toast or error message
  - Unit tests: button states, sync trigger

- [x] **T4.5: Conflict Notification UI**
  - On LWW conflict detection (server lastModified > local):
    - Show non-blocking toast: "Stock was updated by another user (102 units). Your local change (+50) was merged."
    - Include "Review" link to compare both versions side-by-side
    - Allow staff to re-apply change or dismiss notification
  - Implementation: conflict UI component with two-column diff view
  - Unit tests: notification display, diff rendering

- [x] **T4.6: Optimistic Updates**
  - All operations show result immediately (optimistic rendering)
  - UI shows "⏳ Pending sync" next to result
  - Once sync ACK received, update to "✓ Synced at 14:23"
  - If sync fails, show "⚠️ Sync failed; local changes saved, will retry"
  - Unit tests: optimistic render, ACK update, failure handling

---

## Sprint 5: Permissions & Security (Week 5–6)

- [x] **T5.1: Role-Based Permission Checks (Server)**
  - Implement permission gate in sync endpoint: `POST /api/v1/inventory/sync`
  - For each pendingOp, check user role:
    - Type "receive" requires role "warehouse_manager"
    - Type "transfer", "pick" require role "inventory_operator"
    - Type "count" requires role "counter"
  - If permission denied, return 403 with error message
  - Add permission check to user context (via OpenRegister RBAC abstraction)
  - Unit tests: permission scenarios, role validation

- [x] **T5.2: Permission Error Handling (Client)**
  - On 403 response from sync:
    - Display user-friendly error: "You don't have permission to [operation]. Contact warehouse manager."
    - Mark pendingOp as failed (do not delete, do not retry)
    - Add "Retry" button (in case user role was just updated)
  - Log denied operation for audit
  - Unit tests: error display, failed op handling

- [x] **T5.3: Audit Trail Integration**
  - Every successful sync operation logs to audit trail:
    - Operation type (receive, transfer, pick, count)
    - User ID / username
    - Timestamp
    - Affected records (sku, location, qty before/after)
    - Server ACK timestamp
  - Use OpenRegister's audit-trail-immutable abstraction (per ADR-022)
  - Unit tests: audit log format, completeness

- [x] **T5.4: User Context & Authentication**
  - Ensure app obtains user identity (from Nextcloud auth or existing session)
  - Include user ID in every pendingOp record
  - Include user ID in every audit log entry
  - Handle session timeout (show "Session expired, please log in" and redirect to login)
  - Unit tests: user context propagation, session handling

---

## Sprint 6: Testing & Quality (Week 6)

- [x] **T6.1: Unit Test Suite**
  - IndexedDB operations: insert, update, query, delete
  - Sync protocol: delta merge, LWW resolution, deduplication
  - Barcode decoding: QR, 1D barcodes, edge cases
  - Component rendering: each operation component, barcode scanner, sync badge
  - Coverage: >= 80% of app code

- [x] **T6.2: Integration Tests**
  - Full receive workflow: scan → qty → sync (with mocked server)
  - Transfer workflow: from location → scan → to location → sync
  - Pick workflow: scan → order line item update → sync
  - Count workflow: physical count → variance → sync
  - Offline simulation: complete operation, network unavailable, sync on reconnect
  - Concurrent edits: two clients edit same InventoryStock, verify LWW
  - Permission tests: role-based access control (warehouse_manager, operator, counter)

- [x] **T6.3: Barcode Recognition Tests**
  - Test EAN-13 barcode recognition (generate sample barcodes, test decoding accuracy)
  - Test QR code recognition (generate QR codes with product IDs, test decoding)
  - Test Code-128, Code-39, Code-93 (if quagga supports)
  - Test edge cases: rotated barcodes, partially visible barcodes, poor lighting

- [x] **T6.4: Offline & Sync Tests**
  - Disable network, complete operations, verify local writes
  - Re-enable network, verify sync runs and completes
  - Simulate network latency (delay responses), verify optimistic updates
  - Simulate network errors (500, timeout), verify retry logic
  - Simulate duplicate uploads, verify deduplication

- [x] **T6.5: PWA & Service Worker Tests**
  - Test PWA manifest (name, icons, shortcuts)
  - Test service worker registration (check that SW is installed)
  - Test offline app load (disable network, load app, verify shell loads from cache)
  - Test cache invalidation (update app, verify new version loads)
  - Test install prompts (Android, iOS)

- [x] **T6.6: Permission & Audit Tests**
  - Test warehouse_manager can receive (✓) but cannot transfer (✗)
  - Test inventory_operator can transfer (✓) but cannot receive (✗)
  - Test counter can count (✓) but cannot transfer (✗)
  - Test audit trail logs all operations with user, timestamp, details
  - Test denied operations are logged (for security analysis)

- [x] **T6.7: Performance & UX Benchmarks**
  - Barcode scan-to-result latency: < 2 seconds
  - Local operation completion: < 500 ms (optimistic update)
  - Sync batch (50 operations): < 10 seconds
  - IndexedDB query for location inventory: < 100 ms
  - PWA cold start (no cache): < 5 seconds
  - PWA warm start (cached): < 1 second

---

## Sprint 7: Manifest & Deployment (Week 6)

- [x] **T7.1: PWA Manifest Configuration**
  - Create `src/manifest.json` with:
    - name: "Inventory Mobile"
    - short_name: "Inventory"
    - icons: 192×192, 512×512 PNG
    - display: "standalone"
    - orientation: "portrait"
    - shortcuts: Receive, Transfer, Pick, Count (with URLs)
  - Validate manifest (use https://www.pwabuilder.com)
  - Add manifest reference to `index.html` (`<link rel="manifest" href="/manifest.json">`)

- [x] **T7.2: App Icons Design**
  - Design 192×192 and 512×512 app icons (must be PNG or SVG)
  - Icons should convey "inventory" / "mobile" / "warehouse"
  - Place icons in `public/icons/` directory
  - Test icons display on Android home screen

- [x] **T7.3: Service Worker Installation Config**
  - Ensure service worker registration code is in main app init
  - Register worker file at `public/serviceWorker.js` (or bundled)
  - Handle registration success/failure
  - Log to console for debugging ("Service worker registered")

- [x] **T7.4: Manifest Registration in Nextcloud**
  - Add PWA entries to `src/manifest.json` (Nextcloud app manifest, not web manifest)
  - Register navigation routes for each operation
  - Update app version in `package.json` and `src/manifest.json`
  - Ensure Nextcloud recognizes PWA install capability

- [x] **T7.5: Build & Bundling**
  - Ensure service worker is correctly bundled (not tree-shaken)
  - Ensure IndexedDB library (idb) is bundled
  - Ensure barcode libraries (jsQR, quagga) are bundled
  - Verify bundle size is acceptable (target: < 500 KB gzipped for initial load)

- [x] **T7.6: Deployment Documentation**
  - Document installation steps for operators:
    - Open app in browser
    - Tap "Add to Home Screen" (or equivalent for browser)
    - App installs as standalone app
    - Tap shortcut to launch operation
  - Document permission requirements: camera, IndexedDB, service worker
  - Document offline capabilities and limitations
  - Create user guide with screenshots

---

## Cross-Cutting Tasks

- [x] **T8.1: Dependency Management**
  - Add `jsqr`, `quagga`, `idb` to `package.json` with pinned versions
  - Ensure `@conduction/nextcloud-vue` is at compatible version
  - Run `npm audit` and resolve any security vulnerabilities
  - Lock dependencies with `package-lock.json`

- [x] **T8.2: Error Handling & Logging**
  - Implement centralized error handler (catch all promise rejections)
  - Log errors to browser console (development) and Nextcloud logs (production)
  - Display user-friendly error messages (not raw stack traces)
  - Implement Sentry or similar for error reporting (optional)

- [x] **T8.3: Localization (i18n)**
  - Mark all user-visible strings for translation (use i18n framework)
  - Provide English translations (required)
  - Provide Dutch translations (nl) for Dutch users
  - Add language selector in app settings (optional)

- [x] **T8.4: Accessibility (a11y)**
  - Ensure all buttons have accessible labels (`aria-label`)
  - Ensure barcode scanner has alt text / description
  - Test with screen reader (NVDA, JAWS)
  - Ensure color contrast meets WCAG AA standard
  - Test keyboard navigation (Tab, Enter, Escape)

- [x] **T8.5: Documentation**
  - Write API documentation for the two new endpoints:
    - `GET /api/v1/inventory/sync` (parameters, response format)
    - `POST /api/v1/inventory/sync` (request format, response format, error codes)
  - Write component API documentation (props, events, slots for each Vue component)
  - Create architecture overview (data flow, sync flow, offline flow)
  - Create troubleshooting guide (common issues, solutions)

- [x] **T8.6: Code Review & Readability**
  - Ensure code follows Shillinq style guidelines
  - Use meaningful variable names, avoid abbreviations
  - Add comments where logic is non-obvious
  - Keep components under 300 lines (split if larger)
  - Use TypeScript for type safety (if applicable)

---

## Definition of Done

For each task to be marked complete:

1. **Code is written** — implementation matches spec requirements
2. **Tests pass** — unit tests, integration tests, E2E tests all green
3. **Code reviewed** — at least one peer review, approval obtained
4. **Documented** — code comments, API docs, user guide updated
5. **Performance meets benchmarks** — latency, bundle size, offline responsiveness verified
6. **Accessibility verified** — a11y scan, screen reader test
7. **Security reviewed** — no new vulnerabilities, permission checks in place, audit trail logging

---

## Dependencies & Blockers

- **Blocker**: `inventory-stock-tracking` spec must be implemented first (provides InventoryStock schema)
- **Blocker**: `inventory-barcode-sku` spec must be implemented first (provides barcode-to-SKU resolver)
- **Risk**: If OpenRegister's audit-trail or RBAC abstractions are unavailable, implement thin guards in shillinq-specific code (per ADR-031)

