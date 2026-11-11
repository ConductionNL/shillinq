/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pins how the three finance leaf panels read their host object's leaf: the
 * refusal without a resolvable host, the URL read, what each keeps from the
 * envelope, the read failure, the loading flag, and the refusal text of a
 * failed act.
 *
 * @spec openspec/specs/leaves-library-card/spec.md
 */

import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import ShillinqContractPanel from '../../src/integrations/ShillinqContractPanel.vue'
import ShillinqFinancialDocumentsPanel from '../../src/integrations/ShillinqFinancialDocumentsPanel.vue'
import ShillinqPaymentRequestsPanel from '../../src/integrations/ShillinqPaymentRequestsPanel.vue'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

const originalGet = axios.get

afterEach(() => {
	axios.get = originalGet
})

/**
 * A fake panel instance: its data, the given props, bound methods.
 *
 * @param {object} component The SFC.
 * @param {object} props The props.
 * @return {object}
 */
function vm(component, props) {
	const layers = [...(component.mixins || []), component]
	const declared = Object.assign({}, ...layers.map((layer) => layer.props || {}))
	const $props = {}
	for (const [name, def] of Object.entries(declared)) {
		$props[name] = name in props ? props[name] : (typeof def.default === 'function' ? def.default() : def.default)
	}
	const ctx = { ...component.data.call({}), $props, ...$props }
	for (const layer of layers) {
		for (const [name, fn] of Object.entries(layer.methods || {})) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

const HOST = { register: 'r', schema: 's', objectId: 7 }
const PANELS = [
	['contract', ShillinqContractPanel, 'shillinq-contracts', { items: [{ id: 'c' }] }, (ctx) => ctx.contracts, 'The contract could not be read.'],
	['payment requests', ShillinqPaymentRequestsPanel, 'shillinq-payment-requests', { items: [{ id: 'p' }], fee: { amount: 5 } }, (ctx) => [ctx.requests, ctx.fee], 'The payment requests could not be read.'],
	['financial documents', ShillinqFinancialDocumentsPanel, 'shillinq-financial-documents', { items: [{ id: 'f' }], acts: { send: true } }, (ctx) => [ctx.rows, ctx.acts], 'The financial documents could not be read.'],
]

describe.each(PANELS)('the %s panel load()', (name, component, leaf, envelope, kept, failure) => {
	it('refuses without a resolvable host', async () => {
		const get = vi.fn()
		axios.get = get
		const ctx = vm(component, { register: 'r' })
		await ctx.load()
		expect(ctx.error).toBe('This panel needs a record to stand on.')
		expect(ctx.loading).toBe(false)
		expect(get).not.toHaveBeenCalled()
	})

	it('reads the leaf of the host, from props or the integration context', async () => {
		const urls = []
		axios.get = vi.fn(async (url) => {
			urls.push(url)
			return { data: envelope }
		})
		const ctx = vm(component, { integrationContext: { ...HOST } })
		ctx.error = 'old'
		await ctx.load()
		expect(urls).toEqual([`/index.php/apps/openregister/api/objects/r/s/7/integrations/${leaf}`])
		expect([ctx.error, ctx.loading]).toEqual(['', false])
		expect(kept(ctx)).toMatchSnapshot()
	})

	it('keeps an empty list when the envelope has none', async () => {
		axios.get = vi.fn(async () => ({ data: { items: 'x' } }))
		const ctx = vm(component, HOST)
		await ctx.load()
		expect(kept(ctx)).toMatchSnapshot()
	})

	it('reports a failed read', async () => {
		axios.get = vi.fn(async () => {
			throw new Error('down')
		})
		const ctx = vm(component, HOST)
		await ctx.load()
		expect([ctx.error, ctx.loading]).toEqual([failure, false])
	})
})

describe.each([
	['payment requests', ShillinqPaymentRequestsPanel],
	['financial documents', ShillinqFinancialDocumentsPanel],
])('the %s panel refusal()', (name, component) => {
	it.each([
		[{ response: { data: { error: 'Not yours' } } }, 'Not yours'],
		[{ response: { data: { error: '' } } }, 'That did not go through.'],
		[{ response: { data: { error: 42 } } }, 'That did not go through.'],
		[{ response: {} }, 'That did not go through.'],
		[null, 'That did not go through.'],
	])('%j', (error, text) => {
		expect(vm(component, HOST).refusal(error)).toBe(text)
	})
})
