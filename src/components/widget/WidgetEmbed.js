/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Booking Self-service Widget — script-tag embed loader (REQ-WSW-004).
 *
 * Partner sites drop:
 *
 *   <div id="booking-widget"></div>
 *   <script src="https://shillinq.example.com/apps/shillinq/widget.js"></script>
 *   <script>
 *     BookingWidget.init({
 *       businessId: 'salon-001',
 *       apiBase: 'https://shillinq.example.com/index.php/apps/shillinq',
 *       apiKey: 'bk_live_xxx...',
 *       containerId: 'booking-widget',
 *       lang: 'nl',
 *       primaryColor: '#ff6b6b',
 *     })
 *   </script>
 *
 * The loader mounts a Vue instance of `SelfServiceWidget` into the
 * configured container. The same module is exposed by the npm package
 * (`widget/`) so all four embed methods share the same surface (REQ-WSW-004).
 */

import { createApp, h } from 'vue'
import SelfServiceWidget from './SelfServiceWidget.vue'

import '../../styles/widget.css'

const DEFAULTS = {
	lang: 'en',
	primaryColor: '',
	darkMode: false,
	resourceId: 'res-001',
}

// Validate the operator-supplied config. We reject silently if the bare
// minimum (containerId / businessId / apiBase / apiKey) is missing — the
// loader can never substitute "sensible defaults" for credentials.
/**
 *
 * @param config
 */
function validateConfig(config) {
	if (!config || typeof config !== 'object') {
		return 'BookingWidget.init requires a config object.'
	}
	if (!config.businessId) {
		return 'BookingWidget.init requires a businessId.'
	}
	if (!config.apiBase) {
		return 'BookingWidget.init requires an apiBase URL.'
	}
	if (!config.apiKey) {
		return 'BookingWidget.init requires an apiKey.'
	}
	if (!config.containerId && !config.element) {
		return 'BookingWidget.init requires a containerId or element.'
	}
	return null
}

/**
 *
 * @param config
 */
function resolveContainer(config) {
	if (config.element) {
		return config.element
	}
	return document.getElementById(config.containerId)
}

/**
 *
 * @param container
 * @param config
 */
function mountInto(container, config) {
	const merged = { ...DEFAULTS, ...config }
	// Inject a child mount-point so Vue does not replace partner-supplied
	// container markup (some partners use the same div for analytics).
	const mountPoint = document.createElement('div')
	container.appendChild(mountPoint)

	// Vue 3: props go directly in `h()`'s second argument — the Vue 2
	// `{ props: {...} }` wrapper would be passed through as a single attr
	// named "props" and every real prop would arrive undefined.
	const app = createApp({
		render() {
			return h(SelfServiceWidget, {
				businessId: merged.businessId,
				apiBase: merged.apiBase,
				apiKey: merged.apiKey,
				resourceId: merged.resourceId,
				lang: merged.lang,
				primaryColor: merged.primaryColor,
				darkMode: !!merged.darkMode,
				translations: merged.translations || {},
			})
		},
	})
	app.mount(mountPoint)
	return app
}

export const BookingWidget = {
	/**
	 * Initialise a widget instance. Returns the Vue application instance for
	 * caller lifecycle control (`app.unmount()` on SPA route change, etc.).
	 *
	 * @param {object} config Widget configuration.
	 * @return {object|null} Vue app handle, or null on validation failure.
	 */
	init(config) {
		const error = validateConfig(config)
		if (error) {
			// eslint-disable-next-line no-console
			console.error('[BookingWidget] ' + error)
			return null
		}
		const container = resolveContainer(config)
		if (!container) {
			// eslint-disable-next-line no-console
			console.error('[BookingWidget] Container element not found.')
			return null
		}
		return mountInto(container, config)
	},

	/**
	 * Compose the iframe embed URL for the iframe-mode partners (REQ-WSW-004).
	 *
	 * @param {object} config Widget configuration.
	 * @return {string} Iframe src URL.
	 */
	iframeUrl(config) {
		const error = validateConfig({ ...config, containerId: 'iframe' })
		if (error) {
			// eslint-disable-next-line no-console
			console.error('[BookingWidget] ' + error)
			return ''
		}
		const params = new URLSearchParams()
		params.set('businessId', config.businessId)
		params.set('apiKey', config.apiKey)
		if (config.lang) {
			params.set('lang', config.lang)
		}
		if (config.primaryColor) {
			params.set('primaryColor', config.primaryColor)
		}
		return (
			config.apiBase.replace(/\/$/, '') + '/widget/iframe?' + params.toString()
		)
	},
}

// Expose as a side-effecting global for the script-tag embed mode.
if (typeof window !== 'undefined') {
	window.BookingWidget = BookingWidget
}

export default BookingWidget
