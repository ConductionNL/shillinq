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

import ConfirmationPortal from './views/ConfirmationPortal.vue'

export default {
	ConfirmationPortal: { kind: 'page', component: ConfirmationPortal },
}
