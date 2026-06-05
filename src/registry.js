// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// 5-kind component registry for v2 manifest (per hydra ADR-036).
//
// EMPTY ON PURPOSE. Every shillinq page is a declarative manifest page
// type — `dashboard` and `settings` today, `index` / `detail` for the
// business-administration domain pages. An entry here means a page
// (or sidebar tab / widget / modal) that does NOT fit a built-in type;
// adding one requires an explicit justification in the design doc of the
// change that introduces it. Removing entries is the right direction
// (ADR-024).
//
// Supported kinds: "page" | "widget" | "sidebarTab" | "modal" | "settingsSection"
//
// Example entry:
//   'SomePage': { kind: 'page', component: SomePage },
//
// ConfirmationPortal (bookings-confirm-flow): justified custom page — the
// customer-facing confirmation portal is token-driven (validate-on-load,
// confirm-on-click) with no register list/detail equivalent, so it cannot be
// expressed as a built-in declarative page type. See the component's docblock.

// BookingWidgetKeys (bookings-self-service-widget): the widget API-key admin
// page needs custom action buttons (generate / rotate / revoke) and one-time
// plaintext-key display, which no declarative index/detail type expresses —
// hence an explicit custom-page registration per ADR-024/ADR-036.

import ConfirmationPortal from './views/ConfirmationPortal.vue'
import BookingWidgetKeys from './views/BookingWidgetKeys.vue'

export default {
	ConfirmationPortal: { kind: 'page', component: ConfirmationPortal },
	BookingWidgetKeys: { kind: 'page', component: BookingWidgetKeys },
// Bookings module (bookings-resource-calendar): the calendar (month/week/day)
// and booking-form pages are genuinely custom — neither fits the declarative
// `index`/`detail` page types because they render a time grid with conflict
// highlighting and an inline conflict-resolution flow (design REQ-006/REQ-007).
// Justified per ADR-024: a custom page kind is the minimal surface that covers
// the calendar grid; the underlying Resource/Calendar/Booking lists still use
// declarative index pages in the manifest fragment.
import CalendarView from './views/bookings/CalendarView.vue'
import BookingForm from './views/bookings/BookingForm.vue'
	BookingsCalendar: { kind: 'page', component: CalendarView },
	BookingsForm: { kind: 'page', component: BookingForm },
}
