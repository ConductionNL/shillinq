/**
 * Widget embed loader (REQ-WSW-004).
 *
 * One bundle that powers all supported embed methods:
 *  - Script tag : window.BookingWidget.init({ businessId, apiKey, containerId, ... })
 *  - Web component : <nextcloud-booking-widget business-id apiKey lang primary-color>
 *  - npm / framework : `import { BookingWidget, SelfServiceWidget } from '.../embed'`
 *  - Iframe : the iframe document loads this script and calls init() with its
 *             query-string config (handled by the host page that serves the iframe).
 *
 * The widget is mounted as an isolated Vue 3 app per container so multiple
 * widgets can coexist on one page. CSS variables (`--wsw-*`) flow from the host
 * page or from the `primaryColor` config (REQ-WSW-005).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import Vue from 'vue'
import SelfServiceWidget from './SelfServiceWidget.vue'
import './widget.css'

/**
 * Apply per-widget theme overrides as inline CSS variables.
 *
 * @param {HTMLElement} el The widget container.
 * @param {object} config The embed config.
 * @return {void}
 */
function applyTheme(el, config) {
	if (config.primaryColor) {
		el.style.setProperty('--wsw-primary-color', config.primaryColor)
	}
	if (config.fontFamily) {
		el.style.setProperty('--wsw-font-family', config.fontFamily)
	}
	if (config.borderRadius) {
		el.style.setProperty('--wsw-border-radius', config.borderRadius)
	}
}

/**
 * Mount a booking widget into a container element.
 *
 * @param {HTMLElement} el The container element.
 * @param {object} config The embed config (businessId, apiKey, apiBase, lang, dark, primaryColor).
 * @return {object|null} The mounted Vue app instance, or null on bad config.
 */
export function mount(el, config) {
	if (!el || !config || !config.businessId || !config.apiKey) {
		// eslint-disable-next-line no-console
		console.error('[BookingWidget] businessId and apiKey are required')
		return null
	}

	applyTheme(el, config)

	// Vue 2 has no createApp; mount an isolated root instance per
	// container and expose a Vue-3-shaped { unmount } handle so the
	// web-component teardown keeps working unchanged.
	const mountPoint = document.createElement('div')
	el.appendChild(mountPoint)
	const vm = new Vue({
		render: (h) => h(SelfServiceWidget, {
			props: {
				businessId: String(config.businessId),
				apiKey: String(config.apiKey),
				apiBase: String(config.apiBase || ''),
				lang: String(config.lang || 'en'),
				dark: Boolean(config.dark || false),
			},
		}),
	})
	vm.$mount(mountPoint)
	return {
		vm,
		unmount() {
			vm.$destroy()
			if (vm.$el && vm.$el.parentNode) {
				vm.$el.parentNode.removeChild(vm.$el)
			}
		},
	}
}

/**
 * Public script-tag API: BookingWidget.init({ ..., containerId }).
 */
export const BookingWidget = {
	/**
	 * Initialise a widget into the element identified by `containerId`.
	 *
	 * @param {object} config The embed config plus a `containerId`.
	 * @return {object|null} The mounted app instance.
	 */
	init(config) {
		const id = (config && config.containerId) || 'booking-widget'
		const el = document.getElementById(id)
		if (!el) {
			// eslint-disable-next-line no-console
			console.error(`[BookingWidget] container #${id} not found`)
			return null
		}
		return mount(el, config || {})
	},
}

/**
 * Web component: <nextcloud-booking-widget business-id api-key api-base lang primary-color dark>.
 */
export class NextcloudBookingWidgetElement extends HTMLElement {

	connectedCallback() {
		const mountPoint = document.createElement('div')
		this.appendChild(mountPoint)
		this._app = mount(mountPoint, {
			businessId: this.getAttribute('business-id'),
			apiKey: this.getAttribute('api-key'),
			apiBase: this.getAttribute('api-base') || '',
			lang: this.getAttribute('lang') || 'en',
			dark: this.hasAttribute('dark'),
			primaryColor: this.getAttribute('primary-color') || '',
		})
	}

	disconnectedCallback() {
		if (this._app) {
			this._app.unmount()
			this._app = null
		}
	}

}

// Register globals + custom element for script-tag embeds.
if (typeof window !== 'undefined') {
	window.BookingWidget = BookingWidget
	if (window.customElements && !window.customElements.get('nextcloud-booking-widget')) {
		window.customElements.define('nextcloud-booking-widget', NextcloudBookingWidgetElement)
	}
}

export { SelfServiceWidget }
export default BookingWidget
