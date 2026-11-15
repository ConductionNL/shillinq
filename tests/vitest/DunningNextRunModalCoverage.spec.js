/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * DunningNextRunModal.vue driven through its template: loading, the error
 * line, no administration, each administration with its switch state, last
 * run and the reminders the next run would send, and the switch-on flow with
 * its buttons wired to the real handlers. Only HTTP, the toast and the
 * @nextcloud/vue components are replaced; src/utils/dunningApi.js runs.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns, vnodeText } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ get: vi.fn(), patch: vi.fn() }))
const toast = vi.hoisted(() => ({ showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (all, key) => (key in vars ? String(vars[key]) : all)),
}))
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return { NcButton: standIn('NcButton'), NcDialog: standIn('NcDialog') }
})

const { default: DunningNextRunModal } = await import('../../src/modals/DunningNextRunModal.vue')

const OFF = {
	id: 'obj-1',
	administrationId: 'adm-1',
	name: 'Gemeente Noord',
	enabled: false,
	lastRun: { ranAt: '2026-11-14T06:00:00Z', markedOverdue: 2, sent: 1, manual: 0, failed: 0, skipped: 3 },
	rows: [
		{ invoiceId: 'i1', invoiceNumber: 'F-1', customer: 'Bakker BV', dueDate: '2026-10-01', stageNr: 1, channel: 'EMAIL' },
		{ invoiceId: 'i2', customer: 'Jansen', dueDate: '2026-09-01', stageNr: 2, channel: 'FAX' },
	],
}

const ON = { administrationId: 'adm-2', enabled: true, lastRun: { ranAt: '2026-11-14T06:00:00Z', locked: true }, rows: [] }

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
 * The text of each rendered administration section.
 *
 * @param {object} view The rendered view.
 * @return {Array<string>} The texts.
 */
function sections(view) {
	return view.find((vnode) => vnode.props?.['data-testid'] === 'dunning-next-run-administration').map(vnodeText)
}

beforeEach(() => {
	http.get.mockReset()
	http.patch.mockReset()
	toast.showSuccess.mockReset()
})

describe('DunningNextRunModal rendered', () => {
	it('says it is loading, under the plain title', async () => {
		const view = await renderWithStandIns(DunningNextRunModal, { data: { loading: true } })
		expect(view.standIns('NcDialog')[0].name).toBe('Next run')
		expect(vnodeText(view.byTestId('dunning-next-run-loading'))).toBe('Loading the next run…')
		expect(view.html).not.toContain('You have no administration to show.')
		expect(buttons(view)).toEqual(['Close'])
	})

	it('shows an error as an alert and nothing else', async () => {
		const view = await renderWithStandIns(DunningNextRunModal, { data: { error: 'Kapot' } })
		expect(view.byTestId('dunning-next-run-error').props.role).toBe('alert')
		expect(vnodeText(view.byTestId('dunning-next-run-error'))).toBe('Kapot')
		expect(view.html).not.toContain('You have no administration to show.')
	})

	it('says when there is no administration to show', async () => {
		const view = await renderWithStandIns(DunningNextRunModal)
		expect(view.html).toContain('You have no administration to show.')
	})

	it('shows each administration with its switch, its last run and the reminders to send', async () => {
		const view = await renderWithStandIns(DunningNextRunModal, { data: { administrations: [OFF, ON] } })
		const [off, on] = sections(view)

		expect(off).toContain('Gemeente Noord')
		expect(off).toContain('Automatic reminders are off. Nothing is sent until you switch them on.')
		expect(off).toContain('Last run 2026-11-14: 2 marked overdue, 1 sent, 0 to send by hand, 0 failed, 3 with nothing due.')
		expect(off).toContain('Reminders the next run would send')
		expect(off).toContain('F-1 Bakker BV 2026-10-01 1 Email')
		expect(off).toContain('i2 Jansen 2026-09-01 2 FAX')

		expect(on).toContain('adm-2')
		expect(on).toContain('Automatic reminders are on.')
		expect(on).toContain('Last run 2026-11-14: skipped, another run was still busy.')
		expect(on).toContain('No invoice gets a reminder in the next run.')
	})

	it('offers to switch reminders on only for an administration that has them off', async () => {
		const view = await renderWithStandIns(DunningNextRunModal, { props: { switchOn: true }, data: { administrations: [OFF] } })
		expect(view.standIns('NcDialog')[0].name).toBe('Check the next run, then switch reminders on')
		expect(buttons(view)).toEqual(['Cancel', 'Switch on reminders'])
		expect(view.byTestId('dunning-switch-on-submit').props.disabled).toBe(false)

		for (const data of [{ administrations: [ON] }, { administrations: [] }, { administrations: [OFF], loading: true }, { administrations: [OFF], submitting: true }]) {
			const blocked = await renderWithStandIns(DunningNextRunModal, { props: { switchOn: true }, data })
			expect(blocked.byTestId('dunning-switch-on-submit').props.disabled).toBe(true)
		}
	})

	it('writes the same states when the slots render the server way', async () => {
		const html = async (props, data) => (await renderWithStandIns(DunningNextRunModal, { props, data, slots: 'ssr' })).html.replace(/<!--[[\]]-->/g, '')

		const full = await html({ switchOn: true }, { administrations: [OFF, { ...ON, lastRun: null }], loading: true, error: 'Let op' })
		expect(full).toContain('Loading the next run…')
		expect(full).toContain('Let op')
		expect(full).toContain('Bakker BV')
		expect(full).toContain('The daily run has not run for this administration yet.')
		expect(full).toContain('No invoice gets a reminder in the next run.')
		expect(full).toContain('Switch on reminders')
		expect(full).toContain('Cancel')

		const empty = await html({}, {})
		expect(empty).toContain('You have no administration to show.')
		expect(empty).toContain('Close')
	})
})

describe('DunningNextRunModal handlers', () => {
	it('loads the next run for its administration', async () => {
		http.get.mockResolvedValue({ data: { administrations: [OFF] } })
		const view = await renderWithStandIns(DunningNextRunModal, { props: { administrationId: 'adm-1' } })

		await view.vm.load()

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/dunning/next-run', { params: { administrationId: 'adm-1' } })
		expect(view.vm.administrations).toEqual([OFF])
		expect(view.vm.loading).toBe(false)
	})

	it('says the next run could not be loaded, with the server reason when there is one', async () => {
		const view = await renderWithStandIns(DunningNextRunModal)

		http.get.mockRejectedValueOnce(new Error('offline'))
		await view.vm.load()
		expect(view.vm.error).toBe('The next run could not be loaded.')

		http.get.mockRejectedValueOnce({ response: { data: { error: 'Geen toegang' } } })
		await view.vm.load()
		expect(view.vm.error).toBe('Geen toegang')
		expect(view.vm.loading).toBe(false)
	})

	it('switches reminders on for the shown administration and closes with true', async () => {
		http.patch.mockResolvedValue({ data: {} })
		const onClose = vi.fn()
		const view = await renderWithStandIns(DunningNextRunModal, { props: { switchOn: true, onClose }, data: { administrations: [OFF] } })

		await view.byTestId('dunning-switch-on-submit').props.onClick()

		expect(http.patch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/Administration/obj-1', { dunningEnabled: true })
		expect(toast.showSuccess).toHaveBeenCalledWith('Reminders are on. The next daily run sends the list above.')
		expect(onClose).toHaveBeenCalledWith(true)
		expect(view.vm.submitting).toBe(false)
	})

	it('falls back to the administration id it was given, and keeps the dialog open on failure', async () => {
		http.patch.mockRejectedValue(new Error('500'))
		const onClose = vi.fn()
		const view = await renderWithStandIns(DunningNextRunModal, { props: { switchOn: true, administrationId: 'adm-9', onClose }, data: { administrations: [{ ...OFF, id: undefined }] } })

		await view.vm.submit()

		expect(http.patch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/Administration/adm-9', { dunningEnabled: true })
		expect(view.vm.error).toBe('Reminders could not be switched on.')
		expect(onClose).not.toHaveBeenCalled()
		expect(toast.showSuccess).not.toHaveBeenCalled()
	})

	it('does nothing when there is no administration to switch on', async () => {
		const view = await renderWithStandIns(DunningNextRunModal, { props: { switchOn: true } })
		await view.vm.submit()
		expect(http.patch).not.toHaveBeenCalled()
		expect(view.vm.submitting).toBe(false)
	})

	it('closes with false from the dialog and the close button', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(DunningNextRunModal, { props: { onClose } })
		view.standIns('NcDialog')[0].onClosing()
		view.standIns('NcButton')[0].onClick()
		expect(onClose).toHaveBeenNthCalledWith(1, false)
		expect(onClose).toHaveBeenNthCalledWith(2, false)
	})
})
