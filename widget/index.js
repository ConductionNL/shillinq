/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @conduction/bookings-widget — npm entrypoint.
 *
 * Re-exports the script-tag embed loader for partners that import the
 * widget from their bundler (Webpack/Vite/Rollup). The Vue component
 * source itself lives in `../src/components/widget/SelfServiceWidget.vue`
 * and is published alongside this package when `npm run build` is run
 * from the parent app.
 */

// eslint-disable-next-line n/no-unpublished-import -- bundled by parent webpack into the published widget bundle (see widget/package.json scripts.build)
export { BookingWidget, default } from '../src/components/widget/WidgetEmbed.js'
