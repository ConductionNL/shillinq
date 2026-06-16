/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @conduction/bookings-widget/vue — Vue component re-export.
 *
 * Partners using Vue directly import the Single-File Component and mount
 * it themselves; this entrypoint exists so the import surface is stable
 * across the four embed methods (REQ-WSW-004).
 */

// eslint-disable-next-line n/no-unpublished-import -- bundled by parent webpack into the published widget bundle (see widget/package.json scripts.build)
export { default as BookingWidget } from '../src/components/widget/SelfServiceWidget.vue'
// eslint-disable-next-line n/no-unpublished-import -- bundled by parent webpack into the published widget bundle (see widget/package.json scripts.build)
export { default } from '../src/components/widget/SelfServiceWidget.vue'
