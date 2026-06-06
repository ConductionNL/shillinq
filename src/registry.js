// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// 5-kind component registry for v2 manifest (per hydra ADR-036).
//
// Mostly empty by policy. Every shillinq page is a declarative manifest
// page type — `dashboard` and `settings` today, `index` / `detail` for the
// business-administration domain pages. An entry here means a page (or
// sidebar tab / widget / modal) that does NOT fit a built-in type; adding
// one requires an explicit justification in the design doc of the change
// that introduces it. Removing entries is the right direction (ADR-024).
//
// Supported kinds: "page" | "widget" | "sidebarTab" | "modal" | "settingsSection"
//
// inventory-mobile-scanner exception (per design.md / proposal.md):
//   The warehouse PWA's four operations are imperative Vue components that
//   own a live camera stream, the IndexedDB layer and an optimistic-update
//   pipeline. None of those concerns fit any built-in manifest page type
//   (`index`, `detail`, `dashboard`, `settings`), and a barcode-scanning
//   form does not benefit from manifest-driven generation. Each page is
//   registered as a `kind:"page"` custom component so the manifest router
//   still owns the URL → component mapping.

import MobileScannerHome from './views/inventory/MobileScannerHome.vue'
import ReceivePage from './views/inventory/ReceivePage.vue'
import TransferPage from './views/inventory/TransferPage.vue'
import PickPage from './views/inventory/PickPage.vue'
import CountPage from './views/inventory/CountPage.vue'
// bookings-resource-calendar exception (#117, per design.md):
//   The booking month/week/day grid and the conflict-aware booking form are
//   imperative Vue components — they own a custom calendar shell, modal
//   isolation for the conflict dialog, and an inline POST → 409 retry path
//   that does not fit any built-in index/detail/dashboard/settings page
//   type. Registered here as a kind:"page" custom component so the
//   manifest router still owns the URL → component mapping.
import BookingsCalendarPage from './views/bookings/CalendarPage.vue'

// invoice-from-time-and-expense (issue #111): drafting form + admin list
// + detail page are imperative because the generator combines multi-source
// dynamic look-ups (time entries + expenses + rate card + retainer) into
// a single editable preview, which does not fit `index` / `detail`.
import InvoiceGenerator from './components/invoice/InvoiceGenerator.vue'
import AdminInvoiceList from './views/invoice/AdminInvoiceList.vue'
import AdminInvoiceDetail from './views/invoice/AdminInvoiceDetail.vue'

export default {
	MobileScannerHome: { kind: 'page', component: MobileScannerHome },
	MobileScannerReceive: { kind: 'page', component: ReceivePage },
	MobileScannerTransfer: { kind: 'page', component: TransferPage },
	MobileScannerPick: { kind: 'page', component: PickPage },
	MobileScannerCount: { kind: 'page', component: CountPage },

	InvoiceGenerator: { kind: 'page', component: InvoiceGenerator },
	AdminInvoiceList: { kind: 'page', component: AdminInvoiceList },
	AdminInvoiceDetail: { kind: 'page', component: AdminInvoiceDetail },

	BookingsCalendarPage: { kind: 'page', component: BookingsCalendarPage },
}
