# Proposal: inventory-mobile-scanner

`kind: feature` — a new Progressive Web App (PWA) for warehouse operations with full offline support, barcode scanning, and real-time inventory synchronization.

## Summary

Deliver a **mobile-first PWA for warehouse staff** supporting four core warehouse operations — receive, transfer, pick, and count — with:
- **Offline-first architecture**: all critical operations work offline; sync when network returns
- **Barcode/QR scanning**: quick data capture via device camera
- **Real-time stock updates**: immediate visibility into on-hand quantities at any warehouse location
- **Multi-location support**: transfer inventory between warehouses or storage bins within a location
- **Sync state tracking**: visual feedback on sync status and conflict resolution

The app targets warehouse operators, receiving staff, and inventory counters — roles where desktop access is impractical. It consumes the existing `InventoryStock`, `InventoryItem`, `Location`, `Product`, and `Organization` data models (per ADR-000) and the barcode-SKU resolution system from the dependent spec `inventory-barcode-sku`.

This change covers the app specification, manifest registration, offline data layer, sync protocol, and permission/authorization model. Implementation lands via `opsx-apply` per the task list.

## Motivation

Warehouse operations are the highest-churn, highest-error-rate activity in inventory management. Field staff with mobile devices can reduce stock discrepancies by 60–80% vs. batch-processed physical counts (competitor research, assetbots, odoo, snipe-it). However:

1. **Network is unreliable in warehouses** — WiFi dead zones, metal shelving interference, and site handoffs mean staff frequently lose connectivity mid-operation.
2. **Context switching is expensive** — stopping to wait for data or network costs 2–5 min per operation. Offline mode eliminates this cost.
3. **Barcode scanning is 10× faster than manual entry** — but requires device camera + real-time SKU resolution, not a web form.
4. **Real-time visibility drives compliance** — 19/22 competitors in the market intelligence report ship mobile barcode scanners; customers expect this.

The two dependencies (`inventory-stock-tracking` and `inventory-barcode-sku`) deliver the trustworthy stock ledger and barcode resolution. This spec delivers the **mobile UX and offline sync** on top.

See `openspec/intelligence-db/shillinq-research-gap-report.md` for full competitor analysis.

## Affected Projects

- [x] **Project: shillinq** — adds:
  - PWA manifest, service worker, offline-capable Vue SPA
  - Offline-first sync layer (IndexedDB local cache, delta-push on reconnect)
  - Camera-based barcode scanner component
  - Inventory operations UI (receive, transfer, pick, count workflows)
  - Permission gates (warehouse manager, operator, counter roles)
  - Integration with OpenRegister `InventoryStock` + `Location` schema for live inventory
  - Manifest entries for PWA in `src/manifest.json` and install config in `src/serviceWorker.ts`

- [ ] **Project: openregister** — no source changes; this change consumes existing `InventoryStock`, `InventoryItem`, and `Location` schemas (defined in dependent specs).

## Scope

### In Scope

- **Offline-first PWA app**:
  - Service worker with cache-first + network-fallback strategy
  - IndexedDB schema mirroring `InventoryStock` + `InventoryItem` + `Location` entities
  - Delta-sync protocol (timestamp-based, conflict detection for concurrent updates)
  - Visual sync status indicator and manual sync trigger

- **Four warehouse operations**:
  1. **Receive**: scan barcode → lookup SKU → confirm quantity → create InventoryStock increment + GoodsReceipt record
  2. **Transfer**: select source location → scan item → select destination → update InventoryStock records (decrement source, increment dest)
  3. **Pick**: scan barcode → reduce InventoryStock quantity → mark as picked in order
  4. **Count**: manual entry or scan → compare vs. on-hand → record variance, create reconciliation record

- **Barcode scanning**:
  - Device camera access (via WebRTC / `getUserMedia`)
  - QR + 1D barcode decoding (consume `jsQR` + `quagga` libraries)
  - SKU lookup via barcode-sku resolver (from dependent spec)
  - Fallback manual entry if camera unavailable

- **Permission model**:
  - Role-based access gates: warehouse_manager, inventory_operator, counter
  - Permission checks at API + offline-sync boundaries (per ADR-023)

- **Manifest integration**:
  - PWA metadata in `src/manifest.json` (name, icons, shortcuts for each operation)
  - Install prompts for Android/iOS (add-to-homescreen)

### Out of Scope

- **Receiving documents (PO matching)** — the receive operation accepts any quantity; PO validation is future work
- **Voice picking** — voice-directed picking is a T5 specialization; barcode scan is the UI layer
- **Robot/MHE integration** — conveyor and AGV coordination is out of scope
- **Analytics and labor tracking** — time-per-operation metrics are future enhancements
- **Multi-warehouse inter-site transfers** — single-organization scope; inter-organization transfers are T5
- **Native app (iOS/Android)** — PWA runs on any browser; native wrappers are deferred
- **Real-time push notifications** — server push for stock alerts is future work

## Approach

### Phase 1: Data & Sync (design.md Phase 1)
1. Define offline-first data schema in IndexedDB (InventoryStock, InventoryItem, Location mirrors)
2. Implement delta-sync protocol: timestamp-based change detection, conflict resolution (last-write-wins), server ACK
3. Define API endpoints for sync: `GET /inventory/sync?since=<timestamp>` → returns deltas, `POST /inventory/sync` → client uploads local changes
4. Permission gates at sync boundary (only receive warehouse_manager, transfer needs any operator, count needs counter role)

### Phase 2: Operations UI (design.md Phase 2)
1. **Receive operation**: barcode scan → SKU lookup → quantity input → post to InventoryStock + GoodsReceipt
2. **Transfer operation**: select from-location → barcode scan → select to-location → decrement/increment InventoryStock
3. **Pick operation**: barcode scan → reduce qty → mark order item as picked
4. **Count operation**: manual input or scan → compare variance → post reconciliation record

### Phase 3: Barcode Scanning (design.md Phase 3)
1. Camera permission + live preview using `getUserMedia`
2. Real-time barcode detection with `jsQR` (QR) / `quagga` (1D codes)
3. SKU resolution via dependent spec `inventory-barcode-sku`
4. Fallback: manual SKU/barcode entry textbox

### Phase 4: Offline & Sync UX (design.md Phase 4)
1. Offline indicator badge (green = synced, yellow = pending sync, red = offline)
2. Manual sync button with retry logic
3. Conflict UI: if server version differs from local, show diff + choose version
4. Optimistic updates: show result immediately; sync in background

### Phase 5: Testing & Permissions (design.md Phase 5)
1. Permission tests: warehouse_manager can receive, operator can transfer, counter can count
2. Offline simulation: disable network, complete operation, re-enable, verify sync
3. Barcode recognition tests: standard EAN-13, QR codes
4. Conflict tests: concurrent edits to same InventoryStock, verify last-write-wins

## New Dependencies

- **@quagga/quagga2**: Barcode scanning (1D codes, QR)
- **jsqr**: QR code decoding (lightweight fallback)
- **idb**: IndexedDB wrapper (local offline storage)
- **service-worker**: Built-in browser API (offline & caching)

Bump `@conduction/nextcloud-vue` to latest if PWA manifest helpers are needed.

## Impact

- **Frontend**: New SPA module `src/views/MobileScanner.vue` + sub-components (ReceiveOp, TransferOp, PickOp, CountOp, BarcodeScanner)
- **Service Worker**: `src/serviceWorker.ts` — offline cache strategy + delta-sync scheduler
- **API**: Two new endpoints: `GET /api/v1/inventory/sync` (download deltas), `POST /api/v1/inventory/sync` (upload changes)
- **IndexedDB schema**: `inventoryStock`, `inventoryItem`, `location` tables
- **Manifest**: PWA icon set, app shortcuts for each operation, install config
- **Permissions**: Three new roles: `warehouse_manager`, `inventory_operator`, `counter` (registered via RBAC abstraction)
- **No database migrations** — all data is read from existing InventoryStock / InventoryItem / Location registers

## Cross-Project Dependencies

- **inventory-stock-tracking** (dependent spec): delivers `InventoryStock` schema with location-aware quantities
- **inventory-barcode-sku** (dependent spec): delivers barcode → SKU lookup resolver
- **OpenRegister** — consumes `InventoryStock`, `InventoryItem`, `Location` schemas; no new OR extensions required
- **@conduction/nextcloud-vue** — uses generic form/list components from the component library; no custom rendering needed if possible

## Risks

### Risk 1: Offline sync conflicts are frequent and UX for resolution is unclear

**Severity**: Medium
**Mitigation**: REQ-SYNC-001 declares last-write-wins conflict resolution (simple, deterministic). If both server and client edited the same InventoryStock quantity concurrently, server wins after download. Staff receives a "local changes were overwritten by server updates" notification. The `design.md` Phase 4 includes a conflict UI showing both versions side-by-side for manual review if needed. Integration tests include concurrent-edit scenarios.

### Risk 2: Barcode scanning fails due to poor lighting or camera quality

**Severity**: Low
**Mitigation**: Every scan operation includes a manual SKU/barcode text input fallback (REQ-SCAN-002). If QR/barcode fails to decode, staff type the barcode manually (same UX as entering a PO number). Fallback is instant, no re-scanning loop.

### Risk 3: Service worker caching strategy interferes with live inventory updates

**Severity**: Medium
**Mitigation**: Service worker uses cache-first for static assets (JS, CSS) and network-first for API calls (inventory deltas, SKU lookup). REQ-SYNC-003 declares that every operation triggers a sync check; if network is available, server data is fresh. Offline operations use cached local data; sync happens immediately on reconnect.

### Risk 4: IndexedDB quota limits (50 MB typical, browser-dependent) may be exceeded on high-volume warehouse counts

**Severity**: Low
**Mitigation**: REQ-DATA-001 declares that the local cache stores only the current location's inventory + recent transactions (last 7 days). Staff can manually clear old counts via settings. If quota is exceeded, a non-blocking warning appears ("Storage full, sync recommended"); operations continue until sync completes. Quota is monitored per REQ-DATA-002.

### Risk 5: Network latency during sync causes duplicate transactions (user scans item twice thinking first scan didn't go through)

**Severity**: Low
**Mitigation**: REQ-SYNC-002 declares idempotency: each sync operation includes a client-generated `transactionId` (UUID). Server deduplicates on transactionId; duplicate scans within 5 seconds are rejected with "item already scanned" feedback. REQ-SYNC-004 adds visible sync ACK: after each operation, a toast shows "✓ synced at 14:23" or "⏳ pending sync" to disambiguate.

## Rollback Strategy

PWA-only change (no backend schema changes). To roll back:
1. Revert the commit
2. Unregister service worker (browsers will auto-unregister after 24h if manifest is removed)
3. Delete PWA shortcut from homescreen manually (browser handles this)
4. No data loss (all InventoryStock / InventoryItem / Location data remain in the register)

Post-implementation rollback: clear all staff browsers' IndexedDB and service worker cache via browser DevTools; no data recovery needed (source of truth is the server register).

## Open Questions

1. **Multi-location sync scope** — if staff is at warehouse A but the cache contains inventory from warehouse B (from a prior sync), should the sync be location-scoped? REQ-SYNC-005 assumes location-scoped deltas (faster, less data). Confirm during design phase.

2. **Barcode → SKU resolution latency** — does the barcode-sku resolver support offline mode, or does it require a network call? If it requires network, fallback is manual SKU entry. Clarify during `opsx-ff`.

3. **Conflict UI complexity** — if a quantity conflict arises (user edited locally while server updated), do we show a diff UI or auto-apply server value? REQ-SYNC-001 says last-write-wins (auto-apply), but staff feedback may prefer review. Defer to UX phase.

4. **Role granularity** — should "receive" require a distinct role (warehouse_manager) or should it be permission-gated per PO/location? REQ-PERM-001 declares role-based; feature-gated permissions are future work.

