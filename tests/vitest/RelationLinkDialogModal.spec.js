/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The customer-to-supplier link dialog: what it renders linked, unlinked
 * with suggestions and with an error (server-side, no DOM), and what its
 * load, link and unlink send and do with the answer.
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */

import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, vars) => '/index.php' + url.replace(/\{(\w+)\}/g, (match, key) => (vars && key in vars ? vars[key] : match)),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => text.replace(/\{(\w+)\}/g, (match, key) => (vars && key in vars ? vars[key] : match)),
}))
vi.mock('@nextcloud/vue', () => {
	const slotted = (name, tag = 'div') => ({
		name,
		inheritAttrs: false,
		props: ['name', 'disabled', 'inputLabel', 'type'],
		render() {
			return h(tag, { 'data-stub': name, 'data-name': this.name, 'data-type': this.type, 'data-testid': this.$attrs['data-testid'], disabled: this.disabled || undefined }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	})
	return { NcDialog: slotted('NcDialog'), NcButton: slotted('NcButton', 'button'), NcNoteCard: slotted('NcNoteCard'), NcSelect: slotted('NcSelect') }
})

const { default: RelationLinkDialog } = await import('../../src/modals/RelationLinkDialog.vue')

/**
 * Render the dialog, open, with its data replaced and its load not run.
 *
 * @param {object} state The data.
 * @return {Promise<string>} The HTML.
 */
async function render(state = {}) {
	const app = createSSRApp({ ...RelationLinkDialog, mounted() {}, data: () => ({ ...RelationLinkDialog.data(), ...state }) }, { open: true, objectId: 'c-1' })
	return (await renderToString(app)).replace(/<!--[[\]]-->/g, '')
}

/**
 * A plain instance of the dialog.
 *
 * @param {object} over Props and data.
 * @return {object} The instance.
 */
function dialog(over = {}) {
	const self = { objectId: 'c-1', $emit: vi.fn() }
	Object.assign(self, RelationLinkDialog.data.call(self), over)
	for (const [name, method] of Object.entries(RelationLinkDialog.methods)) {
		self[name] = typeof method === 'function' ? method.bind(self) : method
	}
	Object.defineProperty(self, 'customerId', { get: RelationLinkDialog.computed.customerId.bind(self) })
	return self
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	axios.delete.mockReset()
	emit.mockReset()
})

describe('RelationLinkDialog renders', () => {
	it('nothing while closed', async () => {
		expect(await renderToString(createSSRApp({ ...RelationLinkDialog, mounted() {} }, { open: false }))).not.toContain('relation-link-dialog')
	})

	it('the current link with how it was matched, and Remove link', async () => {
		const html = await render({ linked: { payeeName: 'Bakkerij Jansen', matchedOn: 'kvk', confirmedBy: 'anna' } })
		expect(html).toContain('Linked to Bakkerij Jansen, matched on KvK number, confirmed by anna.')
		expect(html).toContain('data-testid="relation-unlink"')
		expect(html).not.toContain('relation-link-picked')
		expect(html).not.toContain('data-stub="NcSelect"')
	})

	it('the suggestions and the picker when unlinked, Link disabled until a supplier is picked', async () => {
		const html = await render({ suggestions: [{ payeeId: 'p-1', payeeName: 'Bakkerij Jansen', matchedOn: 'vat' }] })
		expect(html).toContain('Bakkerij Jansen, same VAT number')
		expect(html).toContain('data-testid="relation-link-suggested"')
		expect(html).toContain('data-stub="NcSelect"')
		expect(html).toMatch(/data-testid="relation-link-picked" disabled/)
		expect(await render({ picked: { id: 'p-2', name: 'X' } })).not.toMatch(/data-testid="relation-link-picked" disabled/)
		expect(await render({ picked: { id: 'p-2', name: 'X' }, busy: true })).toMatch(/data-testid="relation-link-picked" disabled/)
	})

	it('an error in a note card', async () => {
		const html = await render({ error: 'The supplier records could not be loaded.' })
		expect(html).toMatch(/data-stub="NcNoteCard" data-type="error"[^>]*>The supplier records could not be loaded./)
		expect(html).not.toContain('relation-link__suggestions')
	})
})

describe('RelationLinkDialog load', () => {
	it('reads the link, this customer\'s suggestions and the suppliers', async () => {
		axios.get
			.mockResolvedValueOnce({ data: { link: { matchedOn: 'manual', confirmedBy: 'anna' }, payee: { id: 'p-1', name: 'Bakkerij Jansen' } } })
			.mockResolvedValueOnce({ data: { suggestions: [{ customerId: 'c-1', payeeId: 'p-1' }, { customerId: 'c-2', payeeId: 'p-9' }] } })
			.mockResolvedValueOnce({ data: { results: [{ id: 'p-1', name: 'Bakkerij Jansen' }, { '@self': { id: 'p-2' }, tradingName: 'Smit BV' }] } })
		const vm = dialog({ error: 'old' })
		await vm.load()
		expect(axios.get.mock.calls).toEqual([
			['/index.php/apps/shillinq/api/relations/c-1/both-sides'],
			['/index.php/apps/shillinq/api/relations/suggestions'],
			['/index.php/apps/openregister/api/objects/shillinq/Payee', { params: { _limit: 500 } }],
		])
		expect(vm.linked).toEqual({ payeeName: 'Bakkerij Jansen', matchedOn: 'manual', confirmedBy: 'anna' })
		expect(vm.suggestions).toEqual([{ customerId: 'c-1', payeeId: 'p-1' }])
		expect(vm.payees).toEqual([{ id: 'p-1', name: 'Bakkerij Jansen' }, { id: 'p-2', name: 'Smit BV' }])
		expect(vm.error).toBe('')
		expect(vm.matchedOnLabel('manual')).toBe('by hand')
		expect(vm.matchedOnLabel('iban')).toBe('iban')
	})

	it('treats a missing link or empty answers as nothing linked, suggested or listed', async () => {
		axios.get.mockResolvedValueOnce({ data: { link: { matchedOn: 'kvk' }, payee: {} } }).mockResolvedValueOnce({}).mockResolvedValueOnce({})
		const vm = dialog({ objectId: '', $route: { params: { id: 'c-7' } } })
		await vm.load()
		expect(axios.get.mock.calls[0][0]).toBe('/index.php/apps/shillinq/api/relations/c-7/both-sides')
		expect([vm.linked, vm.suggestions, vm.payees]).toEqual([null, [], []])
	})

	it('says the records could not be loaded when a read fails', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const vm = dialog()
		await vm.load()
		expect(vm.error).toBe('The supplier records could not be loaded.')
	})
})

describe('RelationLinkDialog link and unlink', () => {
	it('links a supplier, refreshes the page and closes', async () => {
		const vm = dialog({ error: 'old' })
		axios.post.mockImplementationOnce(async () => {
			expect(vm.busy).toBe(true)
			return {}
		})
		await vm.link('p-1', 'kvk')
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/relations/links', { customerId: 'c-1', payeeId: 'p-1', matchedOn: 'kvk' })
		expect(emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(vm.$emit).toHaveBeenCalledWith('close')
		expect([vm.busy, vm.error]).toEqual([false, ''])
	})

	it('removes the link', async () => {
		axios.delete.mockResolvedValue({})
		const vm = dialog()
		await vm.unlink()
		expect(axios.delete).toHaveBeenCalledWith('/index.php/apps/shillinq/api/relations/links/c-1')
		expect(vm.$emit).toHaveBeenCalledWith('close')
	})

	it('stays open with the server error, else a fallback', async () => {
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Already linked' } } })
		const vm = dialog()
		await vm.link('p-1', 'manual')
		expect(vm.error).toBe('Already linked')
		axios.delete.mockRejectedValueOnce(new Error('x'))
		await vm.unlink()
		expect(vm.error).toBe('The link could not be saved.')
		expect(vm.$emit).not.toHaveBeenCalled()
		expect(emit).not.toHaveBeenCalled()
		expect(vm.busy).toBe(false)
	})

	it('loads when mounted', () => {
		const vm = dialog()
		vm.load = vi.fn()
		RelationLinkDialog.mounted.call(vm)
		expect(vm.load).toHaveBeenCalledTimes(1)
	})
})
