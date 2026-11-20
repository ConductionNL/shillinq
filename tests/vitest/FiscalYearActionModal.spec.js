/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * FiscalYearActionModal.vue driven through its template: the year field,
 * the post to the action's endpoint for the open record, the done line with
 * what was skipped or not read, the refusal the server gives, and the
 * widget refresh. Only HTTP, the event bus and Nextcloud globals are mocked.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns, vnodeText } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ post: vi.fn() }))
const bus = vi.hoisted(() => ({ emit: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (all, key) => (key in vars ? String(vars[key]) : all)),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return {
		NcButton: standIn('NcButton'),
		NcDialog: standIn('NcDialog'),
		NcTextField: standIn('NcTextField'),
	}
})

const { default: FiscalYearActionModal } = await import('../../src/modals/FiscalYearActionModal.vue')

const assess = {
	open: true,
	endpoint: '/api/cbcr/entities/:id/assess-scope',
	title: 'Assess scope',
	hint: 'Checks whether the group is in scope.',
	submitLabel: 'Assess',
	refreshWidget: 'GroupEntityDetail',
	objectId: 'ge holding',
}

beforeEach(() => {
	http.post.mockReset()
	bus.emit.mockReset()
})

describe('FiscalYearActionModal', () => {
	it('renders nothing while closed', async () => {
		const view = await renderWithStandIns(FiscalYearActionModal, { props: { ...assess, open: false } })
		expect(view.standIns('NcDialog')).toEqual([])
	})

	it('posts the year for the record, says it is done and refreshes the page', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(FiscalYearActionModal, { props: { ...assess, onClose } })
		expect(view.standIns('NcDialog')[0].name).toBe('Assess scope')
		expect(view.html).toContain('Checks whether the group is in scope.')

		view.standIns('NcTextField')[0]['onUpdate:modelValue']('2026')
		http.post.mockResolvedValue({ data: { cbcrInScope: true } })
		await view.byTestId('fiscal-year-action-submit').props.onClick()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/cbcr/entities/ge%20holding/assess-scope', { fiscalYear: 2026 })
		expect(bus.emit).toHaveBeenCalledWith('cn:widget:refresh', { widget: 'GroupEntityDetail' })
		const done = await view.rerender()
		expect(vnodeText(done.byTestId('fiscal-year-action-done'))).toBe('Done for 2026.')

		view.vm.onClose()
		expect(onClose).toHaveBeenCalledWith({ cbcrInScope: true })
	})

	it('names what was left alone or not read', async () => {
		const view = await renderWithStandIns(FiscalYearActionModal, { props: assess, data: { fiscalYear: '2026' } })
		http.post.mockResolvedValue({ data: { skipped: ['DE'], notRead: { 'ge-ie': 'No access' } } })

		await view.byTestId('fiscal-year-action-submit').props.onClick()

		expect(view.vm.doneText).toBe('Done for 2026. Left as they were (reconciled or locked): DE. Not read: ge-ie.')
	})

	it('shows the refusal the server gave, or a general line', async () => {
		const view = await renderWithStandIns(FiscalYearActionModal, { props: assess, data: { fiscalYear: '2026' } })
		http.post.mockRejectedValueOnce({ response: { data: { error: 'Enter the average and closing USD to EUR rate first.' } } })
		await view.byTestId('fiscal-year-action-submit').props.onClick()
		expect(vnodeText((await view.rerender()).byTestId('fiscal-year-action-error'))).toBe('Enter the average and closing USD to EUR rate first.')

		http.post.mockRejectedValueOnce(new Error('network'))
		await view.byTestId('fiscal-year-action-submit').props.onClick()
		expect(view.vm.error).toBe('The action could not be completed.')
		expect(bus.emit).not.toHaveBeenCalled()
	})

	it('allows only a plausible year and a record', async () => {
		const view = await renderWithStandIns(FiscalYearActionModal, { props: assess, data: { fiscalYear: '26' } })
		expect(view.byTestId('fiscal-year-action-submit').props.disabled).toBe(true)

		const none = await renderWithStandIns(FiscalYearActionModal, { props: { ...assess, objectId: '' }, data: { fiscalYear: '2026' } })
		expect(none.vm.canSubmit).toBe(false)

		const ok = await renderWithStandIns(FiscalYearActionModal, { props: assess, data: { fiscalYear: '2026' } })
		expect(ok.byTestId('fiscal-year-action-submit').props.disabled).toBe(false)
	})
})
