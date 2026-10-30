# Design: platform-mobile-pwa

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

- **Warehouse PWA.** `public/manifest.webmanifest` ("Shillinq Inventory Mobile", scope `/apps/shillinq/inventory/`, `display: standalone`); `public/inventoryServiceWorker.js` caches static assets cache-first with a versioned cache name and serves data network-first with cache fallback; `src/composables/useServiceWorker.js` registers it on that scope; `src/composables/useInventoryDb.js` wraps IndexedDB with no helper dependency; queued scans sync through `POST /api/inventory/scan` (`appinfo/routes.php:628`, `lib/Controller/InventoryScanController.php:189`). Pages: `MobileScannerHome`, `MobileScannerReceive`, `MobileScannerTransfer` in `src/manifest.d/inventory-mobile-scanner.json`.
- **Receipts.** `src/views/ReceiptCapture.vue` captures a receipt in the browser and creates a `Receipt`.
- **Quick draft.** `src/modals/InvoiceQuickDraftModal.vue` posts an `ARInvoice` draft with customer, lines and VAT to the OpenRegister objects endpoint (line 472).
- **Issue.** `ARInvoice.issue` takes its number from a locked sequence once `sales-invoice-issue-controls` lands.

## Goals / Non-Goals

**Goals**
- Install "Shillinq Mobile" on a phone; capture receipts and draft invoices with or without a connection; issue when online.

**Non-Goals**
- Store apps, offline issue, offline desktop.

## Decisions

### D1. A second app on its own scope

`public/mobile.webmanifest` ("Shillinq Mobile", `start_url`
`/apps/shillinq/mobile`, scope `/apps/shillinq/mobile/`, standalone) and
`public/mobileServiceWorker.js` following the inventory worker's cache
rules. The inventory PWA is left as it is: a warehouse employee and an
entrepreneur install different apps.

Alternative considered: widen the inventory PWA's scope. Rejected: one app
for two jobs with different users.

### D2. Three screens

Custom pages `MobileHome` (my drafts and receipts with sync state),
`MobileReceipt` (camera input with `capture="environment"`, amount, date,
category, the same fields as `ReceiptCapture.vue`) and `MobileInvoice`
(customer, lines and VAT as in `InvoiceQuickDraftModal.vue`, and Issue when
online).

### D3. One offline queue

`src/composables/useOfflineQueue.js`, built like `useInventoryDb.js`: items
`{clientId, kind (receipt | invoice-draft), payload, image?, state
(queued | syncing | synced | failed), error?}`. On `online` and on start it
sends queued items in order; a receipt uploads its image first. The server
stores `clientId` on `Receipt` and `ARInvoice` and returns the existing
object for a repeated `clientId`.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Pages | Declarative: manifest pages of type custom | Page registration. |
| Offline queue and sync | Imperative, client-side | Browser storage and connectivity. |
| Duplicate protection | Declarative: a unique `clientId` on the schemas | Data rule. |

## Seed Data

No schema is added; `Receipt.clientId` and `ARInvoice.clientId` are added.
Example: Kapsalon Mooi's owner photographs a EUR 24.20 receipt from
Kruidvat on a train with no signal and drafts an invoice of EUR 90.75 to a
bridal customer; both show queued, and both show synced three minutes later
with links to the records.

## Risks / Trade-offs

- [Browsers without IndexedDB or service workers] → the app works online only and says so, as `useServiceWorker.js` already no-ops.

## Migration Plan

None.

## Open Questions

None.
