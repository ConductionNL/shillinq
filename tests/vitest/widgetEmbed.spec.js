/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pins the booking widget embed loader (REQ-WSW-004): init refuses a config
 * without its credentials or container and says why, and iframeUrl composes
 * the iframe address from the config. The loader also exposes itself as
 * window.BookingWidget when there is a window.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

let BookingWidget
let errors

const valid = {
	businessId: 'salon-001',
	apiBase: 'https://shillinq.example.com/index.php/apps/shillinq/',
	apiKey: 'bk_live_1',
	containerId: 'booking-widget',
}

beforeEach(async () => {
	vi.resetModules()
	globalThis.window = {}
	BookingWidget = (await import('../../src/components/widget/WidgetEmbed.js')).default
	errors = vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	errors.mockRestore()
	delete globalThis.window
	delete globalThis.document
})

describe('BookingWidget.init', () => {
	it('is exposed on the window for the script-tag embed', () => {
		expect(globalThis.window.BookingWidget).toBe(BookingWidget)
	})

	it.each([
		[null, 'BookingWidget.init requires a config object.'],
		['salon', 'BookingWidget.init requires a config object.'],
		[{ ...valid, businessId: '' }, 'BookingWidget.init requires a businessId.'],
		[{ ...valid, apiBase: '' }, 'BookingWidget.init requires an apiBase URL.'],
		[{ ...valid, apiKey: '' }, 'BookingWidget.init requires an apiKey.'],
		[{ ...valid, containerId: '' }, 'BookingWidget.init requires a containerId or element.'],
	])('refuses %j and says why', (config, message) => {
		expect(BookingWidget.init(config)).toBeNull()
		expect(errors).toHaveBeenCalledWith('[BookingWidget] ' + message)
	})

	it('refuses when the container is not on the page', () => {
		globalThis.document = { getElementById: vi.fn(() => null) }

		expect(BookingWidget.init(valid)).toBeNull()
		expect(globalThis.document.getElementById).toHaveBeenCalledWith('booking-widget')
		expect(errors).toHaveBeenCalledWith('[BookingWidget] Container element not found.')
	})
})

describe('BookingWidget.iframeUrl', () => {
	it('composes the iframe address without a doubled slash', () => {
		expect(BookingWidget.iframeUrl({ ...valid, lang: 'nl', primaryColor: '#ff6b6b' })).toBe(
			'https://shillinq.example.com/index.php/apps/shillinq/widget/iframe?businessId=salon-001&apiKey=bk_live_1&lang=nl&primaryColor=%23ff6b6b',
		)
	})

	it('leaves out an unset language and colour, and needs no container', () => {
		const { containerId, ...noContainer } = valid
		expect(containerId).toBe('booking-widget')
		expect(BookingWidget.iframeUrl({ ...noContainer, apiBase: 'https://x.example' })).toBe(
			'https://x.example/widget/iframe?businessId=salon-001&apiKey=bk_live_1',
		)
		expect(errors).not.toHaveBeenCalled()
	})

	it('returns no address for a config without credentials, and says why', () => {
		expect(BookingWidget.iframeUrl({ ...valid, apiKey: '' })).toBe('')
		expect(errors).toHaveBeenCalledWith('[BookingWidget] BookingWidget.init requires an apiKey.')
	})
})
