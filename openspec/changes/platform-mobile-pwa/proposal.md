---
kind: code
depends_on: [sales-invoice-issue-controls]
---

# Proposal: platform-mobile-pwa

## Summary

An entrepreneur on the road photographs a receipt and drafts an invoice on
their phone, also where there is no signal, and finds both in the books once
back online. Shillinq has an installable offline app for warehouse scanning
only, and the receipt and invoice screens are desktop pages that need a
connection. This change adds a second installable mobile app for receipts
and invoice drafts, with an offline queue that syncs when the connection
returns.

## Motivation

Two platform rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), decided `build`
by the OpenSpec pass of 2026-09-27.

**`plt-mobile`**, "Create invoices and capture receipts in a mobile app."
Rated partial, built. Matrix evidence: "no native app;
src/manifest.d/inventory-mobile-scanner.json registers a mobile scanner PWA
(four custom pages) and src/views/ReceiptCapture.vue captures receipts in
the browser; the Nextcloud web UI is responsive." Note: "No iOS or Android
app of its own." Four competitors rate it yes:

- exact-online: https://www.exact.com/nl/producten/boekhouden/features-en-prijzen, "Mobiele app", and https://www.exact.com/nl/producten/boekhouden/scan-herken.
- moneybird: https://www.moneybird.nl/functies/mobiele-app/, "Fotografeer het bonnetje via de app en hij staat direct in je administratie ... Stuur je factuur direct".
- snelstart: https://www.snelstart.nl/app, "Met de SnelStart App kun je op locatie of onderweg supersnel facturen maken ... Fotografeer en upload ze direct".
- odoo: odoo/odoo@19.0 `addons/web`, a responsive web client installable as a PWA, and mobile receipt upload in Expenses (https://www.odoo.com/odoo-19-release-notes).

**`plt-offline`**, "Keep working without a connection and have changes sync
when back online." Rated partial, built: "Offline only for warehouse
scanning." Changelog demand: https://www.odoo.com/odoo-20-release-notes
("Offline mode enables create/edit/archive/unarchive/delete while offline",
new in Odoo 20).

## Affected Projects

- [ ] Project: `shillinq`: a second PWA with receipt capture, invoice drafts and an offline queue.

## Scope

### In Scope

- An installable "Shillinq Mobile" app on its own scope with three screens: capture a receipt, draft an invoice, see my drafts and their sync state.
- An offline queue in IndexedDB for receipts (image and fields) and invoice drafts, synced when the connection returns, with a visible state per item.
- Issuing an invoice from the phone when online, through the normal issue transition.

### Out of Scope

- Native iOS and Android store apps. The fleet ships Nextcloud web apps; an installable PWA is the mobile app, as odoo's rating accepts.
- Issuing offline. An issued invoice takes the next number of a locked sequence (`sales-invoice-issue-controls`), which needs the server.
- Offline work in the desktop pages.

## Approach

Reuse the warehouse PWA's pieces: its service worker pattern, its IndexedDB
composable and its manifest, on a second scope. Details are in design.md.

## New Dependencies

None.

## Impact

- `public/`: a second web app manifest and service worker.
- `src/composables/`: an offline queue composable next to `useInventoryDb.js`.
- `src/manifest.d/`: three mobile pages.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: A queued receipt is synced twice
**Severity:** Medium. **Mitigation:** each queued item carries a client id that the server stores; a second sync with the same id is answered with the existing object.

### Risk 2: A draft edited on desktop while the phone is offline
**Severity:** Low. **Mitigation:** the phone only creates drafts offline; editing an existing draft needs a connection, so there is nothing to merge.

## Rollback Strategy

Remove the second manifest and worker. Synced receipts and drafts stay as
ordinary records.

## Open Questions

None.
