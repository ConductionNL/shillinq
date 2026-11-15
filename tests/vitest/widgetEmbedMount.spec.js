/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The booking widget loader mounting the widget (REQ-WSW-004): into a
 * partner's element or the container named by id, on a mount point of its
 * own beside the markup already there, with the loader's defaults under the
 * partner's config. The suite has no DOM, so the page is a stand-in and
 * Vue's createApp is recorded rather than run.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const created = vi.hoisted(() => [])
vi.mock('vue', async (importOriginal) => {
	const vue = await importOriginal()
	return {
		...vue,
		createApp: (root) => {
			const app = { root, mount: vi.fn(), unmount: vi.fn() }
			created.push(app)
			return app
		},
	}
})
vi.mock('../../src/styles/widget.css', () => ({}))
vi.mock('../../src/components/widget/SelfServiceWidget.vue', () => ({ default: { name: 'SelfServiceWidget' } }))

const { default: BookingWidget } = await import('../../src/components/widget/WidgetEmbed.js')
const { default: SelfServiceWidget } = await import('../../src/components/widget/SelfServiceWidget.vue')

const valid = {
	businessId: 'salon-001',
	apiBase: 'https://shillinq.example.com/index.php/apps/shillinq/',
	apiKey: 'bk_live_1',
}

/**
 * A stand-in page element that records what is appended to it.
 *
 * @return {object} The element.
 */
function element() {
	return { children: ['partner markup'], appendChild(child) { this.children.push(child) } }
}

beforeEach(() => {
	created.splice(0)
	globalThis.document = { createElement: (tag) => ({ tag }), getElementById: vi.fn() }
})

afterEach(() => {
	delete globalThis.document
})

describe('BookingWidget.init mounts the widget', () => {
	it('into the element the partner hands over, on a mount point of its own, with the defaults', () => {
		const container = element()

		const app = BookingWidget.init({ ...valid, element: container })

		expect(app).toBe(created[0])
		expect(container.children).toEqual(['partner markup', { tag: 'div' }])
		expect(app.mount).toHaveBeenCalledWith(container.children[1])
		expect(globalThis.document.getElementById).not.toHaveBeenCalled()

		const vnode = app.root.render()
		expect(vnode.type).toBe(SelfServiceWidget)
		expect(vnode.props).toEqual({
			...valid,
			resourceId: 'res-001',
			lang: 'en',
			primaryColor: '',
			darkMode: false,
			translations: {},
		})
	})

	it('into the container named by id, with the partner\'s own settings', () => {
		const container = element()
		globalThis.document.getElementById.mockReturnValue(container)

		const app = BookingWidget.init({ ...valid, containerId: 'booking-widget', resourceId: 'res-9', lang: 'nl', primaryColor: '#123456', darkMode: 1, translations: { book: 'Boek' } })

		expect(globalThis.document.getElementById).toHaveBeenCalledWith('booking-widget')
		expect(app.mount).toHaveBeenCalledWith(container.children[1])
		expect(app.root.render().props).toMatchObject({
			resourceId: 'res-9',
			lang: 'nl',
			primaryColor: '#123456',
			darkMode: true,
			translations: { book: 'Boek' },
		})
	})
})
