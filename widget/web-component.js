/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @conduction/bookings-widget/web-component — Custom-element wrapper
 * around the Vue widget (REQ-WSW-004 method 4).
 *
 * Usage:
 *
 *   <nextcloud-booking-widget
 *     business-id="salon-001"
 *     api-base="https://shillinq.example.com/index.php/apps/shillinq"
 *     api-key="bk_live_xxx"
 *     lang="nl"
 *     primary-color="#ff6b6b">
 *   </nextcloud-booking-widget>
 *
 * The element reads its attributes and delegates to BookingWidget.init().
 * Attribute changes after mount do NOT re-render — partners that need
 * reactive props should use the npm or Vue entrypoints instead.
 */

import { BookingWidget } from './index.js'

class NextcloudBookingWidget extends HTMLElement {
	connectedCallback() {
		const config = {
			businessId: this.getAttribute('business-id') || '',
			apiBase: this.getAttribute('api-base') || '',
			apiKey: this.getAttribute('api-key') || '',
			resourceId: this.getAttribute('resource-id') || 'res-001',
			lang: this.getAttribute('lang') || 'en',
			primaryColor: this.getAttribute('primary-color') || '',
			darkMode: this.getAttribute('dark-mode') === 'true',
			element: this,
		}
		this._instance = BookingWidget.init(config)
	}

	disconnectedCallback() {
		if (this._instance && typeof this._instance.$destroy === 'function') {
			this._instance.$destroy()
		}
	}
}

if (typeof window !== 'undefined' && typeof window.customElements !== 'undefined') {
	if (!window.customElements.get('nextcloud-booking-widget')) {
		window.customElements.define(
			'nextcloud-booking-widget',
			NextcloudBookingWidget,
		)
	}
}

export { NextcloudBookingWidget }
export default NextcloudBookingWidget
