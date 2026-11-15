/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * ProposeDirectDebitModal.vue driven through its template: the collection
 * date before proposing, the draft batches with their links and totals, the
 * invoices left out with their reasons, nothing to collect, the error line
 * and the buttons wired to the real handlers. Only HTTP, the toast and the
 * @nextcloud/vue components are replaced; src/utils/directDebitApi.js and
 * downPaymentApi.js run.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns, vnodeText } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => {
	const fill = (text, vars = {}) => text.replace(/\{(\w+)\}/g, (all, key) => (key in vars ? String(vars[key]) : all))
	return {
		translate: (app, text, vars) => fill(text, vars),
		translatePlural: (app, one, many, count, vars) => fill(count === 1 ? one : many, vars),
	}
})
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return { NcButton: standIn('NcButton'), NcDialog: standIn('NcDialog'), NcTextField: standIn('NcTextField') }
})

const { default: ProposeDirectDebitModal } = await import('../../src/modals/ProposeDirectDebitModal.vue')

const RESULT = {
	batches: [
		{ id: 'b 1', scheme: 'CORE', sequenceType: 'FRST', collectionCount: 1, controlSum: 10.1 },
		{ id: 'b2', scheme: 'B2B', sequenceType: 'RCUR', collectionCount: 3, controlSum: 20.25 },
	],
	skipped: [
		{ invoiceId: 'i1', invoiceNumber: 'F-1', reason: 'no-mandate' },
		{ invoiceId: 'i2', invoiceNumber: 'F-2', reason: 'mandate-ended', detail: '2026-01-31' },
	],
}

/**
 * The text of the element carrying a test id.
 *
 * @param {object} view The rendered view.
 * @param {string} id The data-testid.
 * @return {string} Its text.
 */
function textOf(view, id) {
	return vnodeText(view.byTestId(id))
}

/**
 * The label of every action button, in order.
 *
 * @param {object} view The rendered view.
 * @return {Array<string>} The labels.
 */
function buttons(view) {
	return view.find((vnode) => vnode.type?.name === 'NcButton').map((vnode) => [].concat(vnode.children.default()).map(vnodeText).join(' ').trim())
}

/**
 * The markup with the slots rendered the server way.
 *
 * @param {object} data The state.
 * @return {Promise<string>} The HTML without fragment comments.
 */
async function ssr(data) {
	return (await renderWithStandIns(ProposeDirectDebitModal, { data, slots: 'ssr' })).html.replace(/<!--[[\]]-->/g, '')
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
})

describe('ProposeDirectDebitModal before proposing', () => {
	it('asks for the collection date and proposes only for a known administration', async () => {
		const view = await renderWithStandIns(ProposeDirectDebitModal, { data: { collectionDate: '2026-12-01' } })
		expect(view.standIns('NcDialog')[0].name).toBe('Propose direct debit')
		expect(view.byTestId('propose-dd-date').props.label).toBe('Collection date')
		expect(view.byTestId('propose-dd-date').props.modelValue).toBe('2026-12-01')
		expect(view.byTestId('propose-dd-submit').props.disabled).toBe(true)
		expect(buttons(view)).toEqual(['Cancel', 'Propose'])

		const ready = await renderWithStandIns(ProposeDirectDebitModal, { data: { administrationId: 'adm-1', collectionDate: '2026-12-01' } })
		expect(ready.byTestId('propose-dd-submit').props.disabled).toBe(false)

		const busy = await renderWithStandIns(ProposeDirectDebitModal, { data: { administrationId: 'adm-1', submitting: true } })
		expect(busy.byTestId('propose-dd-submit').props.disabled).toBe(true)

		const noDate = await renderWithStandIns(ProposeDirectDebitModal, { data: { administrationId: 'adm-1', collectionDate: '' } })
		expect(noDate.byTestId('propose-dd-submit').props.disabled).toBe(true)
	})

	it('loads the active administration on mount, or says why it could not', async () => {
		http.get.mockResolvedValueOnce({ data: { activeAdministrationId: 9 } })
		const self = { ...ProposeDirectDebitModal.data() }
		await ProposeDirectDebitModal.mounted.call(self)
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/administrations/context')
		expect(self.administrationId).toBe('9')

		http.get.mockRejectedValueOnce(new Error('offline'))
		const failed = { ...ProposeDirectDebitModal.data() }
		await ProposeDirectDebitModal.mounted.call(failed)
		expect(failed.administrationId).toBe('')
		expect(failed.error).toBe('The administration could not be loaded.')
	})
})

describe('ProposeDirectDebitModal after proposing', () => {
	it('lists the draft batches with their totals and links, and what was left out', async () => {
		const view = await renderWithStandIns(ProposeDirectDebitModal, { data: { result: RESULT } })

		expect(textOf(view, 'propose-dd-created')).toBe('2 draft batches, total EUR 30.35.')
		const links = view.find((vnode) => vnode.type === 'a')
		expect(links.map((a) => a.props.href)).toEqual([
			'/index.php/apps/shillinq/bookkeeping/direct-debit-batches/b%201',
			'/index.php/apps/shillinq/bookkeeping/direct-debit-batches/b2',
		])
		expect(links.map(vnodeText)).toEqual(['CORE FRST: 1 collection, EUR 10.10', 'B2B RCUR: 3 collections, EUR 20.25'])
		expect(textOf(view, 'propose-dd-skipped')).toBe('F-1: No direct debit mandate F-2: The mandate ended on 2026-01-31')
		expect(view.byTestId('propose-dd-date')).toBeUndefined()
		expect(view.byTestId('propose-dd-submit')).toBeUndefined()
		expect(buttons(view)).toEqual(['Close'])
	})

	it('says when nothing can be collected and shows no left-out list without entries', async () => {
		const view = await renderWithStandIns(ProposeDirectDebitModal, { data: { result: { batches: [{ id: 'one', scheme: 'CORE', sequenceType: 'OOFF', collectionCount: 1, controlSum: 5 }] } } })
		expect(textOf(view, 'propose-dd-created')).toBe('1 draft batch, total EUR 5.00.')
		expect(view.byTestId('propose-dd-skipped')).toBeUndefined()

		const nothing = await renderWithStandIns(ProposeDirectDebitModal, { data: { result: { batches: [], skipped: [] } } })
		expect(textOf(nothing, 'propose-dd-nothing')).toBe('No invoice can be collected on that date.')
		expect(nothing.byTestId('propose-dd-batches')).toBeUndefined()
	})

	it('writes the same states when the slots render the server way', async () => {
		const proposed = await ssr({ result: RESULT, error: 'Let op' })
		expect(proposed).toContain('2 draft batches, total EUR 30.35.')
		expect(proposed).toContain('B2B RCUR: 3 collections, EUR 20.25')
		expect(proposed).toContain('Left out')
		expect(proposed).toContain('Let op')
		expect(proposed).toContain('Close')

		const nothing = await ssr({ result: { batches: [] } })
		expect(nothing).toContain('No invoice can be collected on that date.')

		const before = await ssr({ administrationId: 'adm-1' })
		expect(before).toContain('Every open invoice paid by direct debit')
		expect(before).toContain('Propose')
		expect(before).toContain('Cancel')
	})
})

describe('ProposeDirectDebitModal handlers', () => {
	it('proposes for the administration and date, then closes with the result', async () => {
		http.post.mockResolvedValue({ data: RESULT })
		const onClose = vi.fn()
		const view = await renderWithStandIns(ProposeDirectDebitModal, { props: { onClose }, data: { administrationId: 'adm-1', collectionDate: '2026-12-01' } })

		view.byTestId('propose-dd-date').props['onUpdate:modelValue']('2026-12-15')
		await view.byTestId('propose-dd-submit').props.onClick()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/direct-debit-batches/propose', { administrationId: 'adm-1', collectionDate: '2026-12-15' })
		expect(view.vm.result).toEqual(RESULT)
		expect(view.vm.submitting).toBe(false)

		view.standIns('NcDialog')[0].onClosing()
		expect(onClose).toHaveBeenCalledWith(RESULT)
	})

	it('shows the reason when the proposal fails, and the server message when there is one', async () => {
		const view = await renderWithStandIns(ProposeDirectDebitModal, { data: { administrationId: 'adm-1' } })

		http.post.mockRejectedValueOnce(new Error('500'))
		await view.byTestId('propose-dd-submit').props.onClick()
		expect(view.vm.error).toBe('The direct debit could not be proposed.')
		expect(view.vm.result).toBeNull()

		http.post.mockRejectedValueOnce({ response: { status: 403, data: { message: 'Geen recht' } } })
		await view.byTestId('propose-dd-submit').props.onClick()
		expect(view.vm.error).toBe('Geen recht')

		const again = await view.rerender()
		expect(textOf(again, 'propose-dd-error')).toBe('Geen recht')
		expect(again.byTestId('propose-dd-error').props.role).toBe('alert')
	})
})
