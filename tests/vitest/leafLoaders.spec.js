/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the lazy half of the three leaf registrations in
 * src/integrations/: each descriptor's mount pair loads its own panel SFC, and
 * only when the host first mounts it. Also pins the registry falling back to
 * the browser `window` when no global is passed.
 *
 * `vue` is mocked (as in leafMount.spec.js) so the mount pair runs in the node
 * environment; the loader each pair hands `defineAsyncComponent` is then
 * called for real and must resolve to the panel module the leaf names.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const vueCalls = vi.hoisted(() => ({ asyncLoaders: [] }))

vi.mock('vue', () => ({
	defineAsyncComponent: (loader) => {
		vueCalls.asyncLoaders.push(loader)
		return { name: 'AsyncLeaf', loader }
	},
	h: (component, props) => ({ component, props }),
	createApp: () => ({
		config: { globalProperties: {} },
		mount: vi.fn(),
		unmount: vi.fn(),
	}),
}))

const { sharedRegistry } = await import('../../src/integrations/leafMount.js')
const { contractsPanelDescriptor } = await import('../../src/integrations/registerContractLeaf.js')
const { financialDocumentsPanelDescriptor } = await import('../../src/integrations/registerFinancialDocumentsLeaf.js')
const { paymentRequestsPanelDescriptor } = await import('../../src/integrations/registerPaymentRequestsLeaf.js')

describe('leaf descriptors load their own panel on first mount', () => {
	beforeEach(() => {
		vueCalls.asyncLoaders.length = 0
	})

	it.each([
		['contract', 'ShillinqContractPanel', () => contractsPanelDescriptor],
		['financial documents', 'ShillinqFinancialDocumentsPanel', () => financialDocumentsPanelDescriptor],
		['payment requests', 'ShillinqPaymentRequestsPanel', () => paymentRequestsPanelDescriptor],
	])('the %s leaf resolves to %s', async (_label, panelName, descriptorOf) => {
		const descriptor = descriptorOf()
		const el = {}

		descriptor.mount(el, { objectId: 'o-1' })

		expect(vueCalls.asyncLoaders).toHaveLength(1)
		const loaded = await vueCalls.asyncLoaders[0]()
		expect(loaded.default.name).toBe(panelName)
		expect(typeof loaded.default.methods).toBe('object')

		descriptor.unmount(el)
	})
})

describe('sharedRegistry without a global', () => {
	afterEach(() => {
		delete globalThis.window
	})

	it('attaches the queue stub to the browser window', () => {
		globalThis.window = {}

		const registry = sharedRegistry()

		expect(registry).toBe(globalThis.window.OCA.OpenRegister.integrations)
		registry.register({ id: 'x' })
		expect(registry._queue).toEqual([{ id: 'x' }])
	})

	it('returns null outside a browser', () => {
		expect(sharedRegistry()).toBeNull()
	})
})
