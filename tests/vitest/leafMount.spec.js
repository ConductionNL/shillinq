/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for src/integrations/leafMount.js and the three leaf
 * registrations that use it: one app per host element, a clean unmount,
 * the queue stub when OpenRegister has not loaded, and a duplicate
 * registration that warns instead of throwing.
 *
 * `vue` is mocked so the mount pair can be exercised in the node environment
 * without a DOM: the assertions are on what the pair asks Vue to do.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const vueCalls = vi.hoisted(() => ({ apps: [], asyncLoaders: [] }))

vi.mock('vue', () => ({
	defineAsyncComponent: (loader) => {
		vueCalls.asyncLoaders.push(loader)
		return { name: 'AsyncLeaf', loader }
	},
	h: (component, props) => ({ component, props }),
	createApp: (root) => {
		const app = {
			root,
			config: { globalProperties: {} },
			mount: vi.fn(),
			unmount: vi.fn(),
		}
		vueCalls.apps.push(app)
		return app
	},
}))

const { mountPairFor, sharedRegistry } = await import('../../src/integrations/leafMount.js')
const { registerContractLeaf } = await import('../../src/integrations/registerContractLeaf.js')
const {
	FINANCIAL_DOCUMENTS_PANEL_ID,
	financialDocumentsPanelDescriptor,
	registerFinancialDocumentsLeaf,
} = await import('../../src/integrations/registerFinancialDocumentsLeaf.js')
const { registerPaymentRequestsLeaf } = await import('../../src/integrations/registerPaymentRequestsLeaf.js')

describe('mountPairFor', () => {
	beforeEach(() => {
		vueCalls.apps.length = 0
		vueCalls.asyncLoaders.length = 0
	})

	it('roots one app at the host element with the forwarded props and t/n installed', () => {
		const loader = () => Promise.resolve({})
		const pair = mountPairFor(loader)
		const el = { id: 'host' }

		pair.mount(el, { objectId: 'o-1' })

		expect(vueCalls.apps).toHaveLength(1)
		const app = vueCalls.apps[0]
		expect(app.mount).toHaveBeenCalledWith(el)
		expect(typeof app.config.globalProperties.t).toBe('function')
		expect(typeof app.config.globalProperties.n).toBe('function')
		const rendered = app.root.render()
		expect(rendered.component.loader).toBe(loader)
		expect(rendered.props).toEqual({ objectId: 'o-1' })
	})

	it('mounts once per element, and a second element gets its own app', () => {
		const pair = mountPairFor(() => Promise.resolve({}))
		const first = {}
		const second = {}

		pair.mount(first, {})
		pair.mount(first, {})
		pair.mount(second)

		expect(vueCalls.apps).toHaveLength(2)
		expect(vueCalls.apps[1].root.render().props).toEqual({})
	})

	it('defines the async component once, on the first mount', () => {
		const pair = mountPairFor(() => Promise.resolve({}))

		expect(vueCalls.asyncLoaders).toHaveLength(0)
		pair.mount({}, {})
		pair.mount({}, {})

		expect(vueCalls.asyncLoaders).toHaveLength(1)
	})

	it('ignores a missing element', () => {
		const pair = mountPairFor(() => Promise.resolve({}))

		pair.mount(undefined, {})
		pair.mount(null, {})

		expect(vueCalls.apps).toHaveLength(0)
	})

	it('unmounts the app at an element and releases it, so the element can mount again', () => {
		const pair = mountPairFor(() => Promise.resolve({}))
		const el = {}

		pair.mount(el, {})
		pair.unmount(el)
		pair.unmount(el)

		expect(vueCalls.apps[0].unmount).toHaveBeenCalledTimes(1)

		pair.mount(el, {})
		expect(vueCalls.apps).toHaveLength(2)
	})

	it('does nothing when unmounting an element it never mounted', () => {
		const pair = mountPairFor(() => Promise.resolve({}))

		expect(() => pair.unmount({})).not.toThrow()
	})
})

describe('sharedRegistry', () => {
	it('is null outside a browser when no global is given', () => {
		expect(sharedRegistry()).toBeNull()
	})

	it('installs the queue stub on an empty global and queues registrations', () => {
		const target = {}

		const registry = sharedRegistry(target)
		registry.register({ id: 'a' })

		expect(target.OCA.OpenRegister.integrations).toBe(registry)
		expect(registry._queue).toEqual([{ id: 'a' }])
	})

	it('replaces a null registry with the stub but keeps the rest of the namespace', () => {
		const target = { OCA: { OpenRegister: { integrations: null, other: 1 } } }

		const registry = sharedRegistry(target)

		expect(registry._queue).toEqual([])
		expect(target.OCA.OpenRegister.other).toBe(1)
	})

	it('returns the real registry when OpenRegister installed one', () => {
		const real = { register: vi.fn() }

		expect(sharedRegistry({ OCA: { OpenRegister: { integrations: real } } })).toBe(real)
	})
})

describe('leaf registrations', () => {
	let warn

	beforeEach(() => {
		warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
	})

	afterEach(() => {
		warn.mockRestore()
	})

	it('registers nothing and does not throw outside a browser', () => {
		expect(() => registerContractLeaf()).not.toThrow()
		expect(() => registerFinancialDocumentsLeaf()).not.toThrow()
		expect(() => registerPaymentRequestsLeaf()).not.toThrow()
	})

	it('puts the financial documents panel on the shared registry', () => {
		const register = vi.fn()

		registerFinancialDocumentsLeaf({ OCA: { OpenRegister: { integrations: { register } } } })

		expect(register).toHaveBeenCalledWith(financialDocumentsPanelDescriptor)
		expect(financialDocumentsPanelDescriptor.id).toBe(FINANCIAL_DOCUMENTS_PANEL_ID)
		expect(financialDocumentsPanelDescriptor.renderMode).toBe('mount')
		expect(typeof financialDocumentsPanelDescriptor.mount).toBe('function')
		expect(typeof financialDocumentsPanelDescriptor.unmount).toBe('function')
	})

	it('warns, rather than throws, on a duplicate registration of each leaf', () => {
		const duplicate = () => ({
			OCA: {
				OpenRegister: {
					integrations: {
						register() {
							throw new Error('duplicate')
						},
					},
				},
			},
		})

		registerContractLeaf(duplicate())
		registerFinancialDocumentsLeaf(duplicate())
		registerPaymentRequestsLeaf(duplicate())

		expect(warn).toHaveBeenCalledTimes(3)
		expect(warn.mock.calls.map((call) => call[0])).toEqual([
			'[shillinq] the contract panel was already registered',
			expect.stringContaining('[shillinq]'),
			'[shillinq] the payment requests panel was already registered',
		])
		expect(warn.mock.calls.every((call) => call[1] instanceof Error)).toBe(true)
	})
})
