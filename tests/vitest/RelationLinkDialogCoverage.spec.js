/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * RelationLinkDialog driven through its template: rendered with recording
 * stand-ins for @nextcloud/vue (tests/vitest/helpers/standInRender.js), so
 * the suggestion buttons, the supplier select, Link, Remove link, Close and
 * the dialog's own closing are fired through the handlers the template
 * bound, with the exact requests they send, the page refresh and the close.
 * Only HTTP, the event bus and the @nextcloud/vue components are replaced.
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns, vnodeText } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), delete: vi.fn() }))
const bus = vi.hoisted(() => ({ emit: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (all, key) => (key in vars ? String(vars[key]) : all)),
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, vars = {}) => '/index.php' + url.replace(/\{(\w+)\}/g, (all, key) => (key in vars ? vars[key] : all)),
}))
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return {
		NcButton: standIn('NcButton'),
		NcDialog: standIn('NcDialog'),
		NcNoteCard: standIn('NcNoteCard'),
		NcSelect: standIn('NcSelect'),
	}
})

const { default: RelationLinkDialog } = await import('../../src/modals/RelationLinkDialog.vue')

const LINKS = '/index.php/apps/shillinq/api/relations/links'

/**
 * Render the dialog open for customer c-1.
 *
 * @param {object} [data] The data to start from.
 * @param {object} [props] Extra props (listeners).
 * @param {string} [slots] 'vnode' or 'ssr'.
 * @return {Promise<object>} The rendered view.
 */
function render(data = {}, props = {}, slots = 'vnode') {
	return renderWithStandIns(RelationLinkDialog, { data, props: { open: true, objectId: 'c-1', ...props }, slots })
}

/**
 * The button vnodes the template built, by test id.
 *
 * @param {object} view The rendered view.
 * @param {string} testId The data-testid.
 * @return {Array<object>} The vnodes.
 */
function buttons(view, testId) {
	return view.find((vnode) => vnode.props?.['data-testid'] === testId)
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	http.delete.mockReset()
	bus.emit.mockReset()
})

describe('RelationLinkDialog template', () => {
	it('renders nothing while closed', async () => {
		const view = await renderWithStandIns(RelationLinkDialog, { props: { open: false } })
		expect(view.standIns('NcDialog')).toEqual([])
		expect(view.html).not.toContain('relation-link')
	})

	it('links a suggested supplier through its own button, then refreshes and closes', async () => {
		const onClose = vi.fn()
		const view = await render({
			suggestions: [
				{ payeeId: 'p-1', payeeName: 'Bakkerij Jansen', matchedOn: 'kvk' },
				{ payeeId: 'p-2', payeeName: 'Jansen Holding', matchedOn: 'vat' },
			],
		}, { onClose })
		http.post.mockResolvedValue({ data: {} })

		expect(view.find((vnode) => vnode.type === 'li').map(vnodeText)).toEqual([
			'Bakkerij Jansen, same KvK number',
			'Jansen Holding, same VAT number',
		])
		const suggested = buttons(view, 'relation-link-suggested')
		expect(suggested).toHaveLength(2)
		await suggested[1].props.onClick()

		expect(http.post).toHaveBeenCalledWith(LINKS, { customerId: 'c-1', payeeId: 'p-2', matchedOn: 'vat' })
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(onClose).toHaveBeenCalledTimes(1)
		expect(view.vm.busy).toBe(false)
	})

	it('links the supplier picked in the select by hand', async () => {
		const view = await render({ payees: [{ id: 'p-9', name: 'Drukkerij' }] })
		const select = view.standIns('NcSelect')[0]
		expect(select).toMatchObject({ options: [{ id: 'p-9', name: 'Drukkerij' }], label: 'name', inputLabel: 'Supplier', placeholder: 'Pick a supplier', modelValue: null })
		expect(buttons(view, 'relation-link-picked')[0].props.disabled).toBe(true)

		select['onUpdate:modelValue']({ id: 'p-9', name: 'Drukkerij' })
		expect(view.vm.picked).toEqual({ id: 'p-9', name: 'Drukkerij' })

		const again = await view.rerender()
		const link = buttons(again, 'relation-link-picked')[0]
		expect(link.props.disabled).toBe(false)
		http.post.mockResolvedValue({ data: {} })
		await link.props.onClick()
		expect(http.post).toHaveBeenCalledWith(LINKS, { customerId: 'c-1', payeeId: 'p-9', matchedOn: 'manual' })
	})

	it('shows the current link and removes it through Remove link', async () => {
		const onClose = vi.fn()
		const view = await render({ linked: { payeeName: 'Bakkerij Jansen', matchedOn: 'manual', confirmedBy: 'anna' } }, { onClose })
		http.delete.mockResolvedValue({ data: {} })

		expect(vnodeText(buttons(view, 'relation-link-current')[0])).toBe('Linked to Bakkerij Jansen, matched on by hand, confirmed by anna.')
		expect(buttons(view, 'relation-link-picked')).toEqual([])
		expect(view.standIns('NcSelect')).toEqual([])

		const unlink = buttons(view, 'relation-unlink')[0]
		expect(unlink.props.disabled).toBe(false)
		await unlink.props.onClick()

		expect(http.delete).toHaveBeenCalledWith('/index.php/apps/shillinq/api/relations/links/c-1')
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(onClose).toHaveBeenCalledTimes(1)
	})

	it('shows a refused write in the error note and stays open', async () => {
		const onClose = vi.fn()
		const view = await render({ linked: { payeeName: 'X', matchedOn: 'kvk', confirmedBy: 'a' } }, { onClose })
		http.delete.mockRejectedValue({ response: { data: { error: 'Link is locked' } } })

		await buttons(view, 'relation-unlink')[0].props.onClick()

		expect(onClose).not.toHaveBeenCalled()
		const again = await view.rerender()
		expect(again.standIns('NcNoteCard')[0].type).toBe('error')
		expect(again.html).toContain('Link is locked')
		expect(buttons(view, 'relation-unlink')[0].props.disabled).toBe(false)
		expect(buttons(await render({ linked: { payeeName: 'X', matchedOn: 'kvk', confirmedBy: 'a' }, busy: true }), 'relation-unlink')[0].props.disabled).toBe(true)
	})

	it('closes through Close and through the dialog', async () => {
		const onClose = vi.fn()
		const view = await render({}, { onClose })
		const close = view.find((vnode) => vnode.type?.name === 'NcButton' && !vnode.props?.['data-testid'])

		expect(close).toHaveLength(1)
		close[0].props.onClick()
		view.standIns('NcDialog')[0].onClosing()
		expect(onClose).toHaveBeenCalledTimes(2)
		expect(view.standIns('NcDialog')[0].name).toBe('Link to a supplier')
	})

	it('renders the same states the server way', async () => {
		const unlinked = await render({ suggestions: [{ payeeId: 'p-1', payeeName: 'Bakkerij Jansen', matchedOn: 'vat' }], error: 'Boom' }, {}, 'ssr')
		expect(unlinked.html).toContain('Bakkerij Jansen, same VAT number')
		expect(unlinked.html).toContain('When this customer is also a supplier')
		expect(unlinked.html).toContain('Boom')

		const linked = await render({ linked: { payeeName: 'Acme', matchedOn: 'other', confirmedBy: 'bob' } }, {}, 'ssr')
		expect(linked.html).toContain('Linked to Acme, matched on other, confirmed by bob.')
		expect(linked.html).toContain('Remove link')
		expect(linked.html).not.toContain('When this customer is also a supplier')
	})
})
