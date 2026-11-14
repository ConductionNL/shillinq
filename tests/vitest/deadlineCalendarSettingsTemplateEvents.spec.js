/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * DeadlineCalendarSettings driven through its template: the switch and the
 * lead-time field write back into their row, a category switched off loses
 * its lead-time field, and submitting the form posts what the user set and
 * shows the outcome. Only HTTP and the @nextcloud/vue components are
 * replaced.
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { domEvent, renderWithStandIns, vnodeText } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return {
		NcAppContent: standIn('NcAppContent'),
		NcButton: standIn('NcButton'),
		NcCheckboxRadioSwitch: standIn('NcCheckboxRadioSwitch'),
		NcLoadingIcon: standIn('NcLoadingIcon'),
		NcTextField: standIn('NcTextField'),
	}
})

const { default: Settings } = await import('../../src/views/DeadlineCalendarSettings.vue')

const globals = { t: (app, text) => text }
const URL = '/index.php/apps/shillinq/api/deadline-calendar/settings'

/**
 * A loaded row as the view keeps it.
 *
 * @param {string} id The category id.
 * @param {boolean} enabled Whether it is on.
 * @param {string} leadDaysInput The typed lead time.
 * @return {object} The row.
 */
function row(id, enabled, leadDaysInput) {
	return { id, label: id + ' label', description: id + ' text', enabled, leadDays: Number(leadDaysInput), leadDaysInput }
}

const LOADED = {
	loading: false,
	rows: [row('filing', true, '14'), row('payment-run', true, '7'), row('ar-due', false, '7'), row('contract', true, '30')],
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
})

describe('DeadlineCalendarSettings template events', () => {
	it('labels each switch with its category and shows a lead-time field only when enabled', async () => {
		const view = await renderWithStandIns(Settings, { data: LOADED, globals })

		expect(view.standIns('NcCheckboxRadioSwitch').map((attrs) => attrs.modelValue)).toEqual([true, true, false, true])
		expect(view.standIns('NcTextField').map((attrs) => attrs['data-testid'])).toEqual([
			'deadline-lead-filing',
			'deadline-lead-payment-run',
			'deadline-lead-contract',
		])
		expect(vnodeText(view.byTestId('deadline-toggle-ar-due'))).toBe('')
		expect(view.html).toContain('ar-due label')
		expect(view.html).toContain('>Save<')
	})

	it('drops the lead-time field once a category is switched off', async () => {
		const view = await renderWithStandIns(Settings, { data: LOADED, globals })

		view.byTestId('deadline-toggle-filing').props['onUpdate:modelValue'](false)
		expect(view.vm.rows[0].enabled).toBe(false)

		const after = await view.rerender()
		expect(after.byTestId('deadline-lead-filing')).toBeUndefined()
		expect(after.byTestId('deadline-lead-contract')).toBeDefined()
	})

	it('posts the toggles and typed lead times on submit and confirms the save', async () => {
		http.post.mockResolvedValue({
			data: { categories: { 'ar-due': { enabled: true, leadDays: 3 } } },
		})
		const view = await renderWithStandIns(Settings, { data: LOADED, globals })

		view.byTestId('deadline-toggle-ar-due').props['onUpdate:modelValue'](true)
		const shown = await view.rerender()
		shown.byTestId('deadline-lead-ar-due').props['onUpdate:modelValue']('3')
		const form = shown.find((vnode) => vnode.type === 'form')[0]
		await form.props.onSubmit(domEvent())

		expect(http.post).toHaveBeenCalledWith(URL, {
			categories: {
				filing: { enabled: true, leadDays: 14 },
				'payment-run': { enabled: true, leadDays: 7 },
				'ar-due': { enabled: true, leadDays: 3 },
				contract: { enabled: true, leadDays: 30 },
			},
		})
		const saved = await shown.rerender()
		const status = saved.find((vnode) => vnode.props?.role === 'status')
		expect(status.map(vnodeText)).toEqual(['Deadline calendar settings saved.'])
		expect(saved.find((vnode) => vnode.props?.role === 'alert')).toHaveLength(0)
	})

	it('shows the save failure in an alert and labels the button while saving', async () => {
		http.post.mockRejectedValue(new Error('500'))
		const view = await renderWithStandIns(Settings, { data: LOADED, globals })

		await view.find((vnode) => vnode.type === 'form')[0].props.onSubmit(domEvent())
		const failed = await view.rerender()
		expect(failed.find((vnode) => vnode.props?.role === 'alert').map(vnodeText)).toEqual([
			'Failed to save deadline calendar settings.',
		])

		const busy = await renderWithStandIns(Settings, { data: { ...LOADED, saving: true }, globals })
		expect(busy.standIns('NcButton')[0].disabled).toBe(true)
		expect(busy.html).toContain('Saving…')
	})

	it('renders the same states when the components render their slots the server way', async () => {
		const loading = await renderWithStandIns(Settings, { globals, slots: 'ssr' })
		expect(loading.standIns('NcLoadingIcon')).toEqual([{ size: 32, name: 'Loading deadline calendar settings' }])
		expect(loading.html).not.toContain('<form')

		const done = await renderWithStandIns(Settings, {
			data: { ...LOADED, savedMessage: 'Saved it.', errorMessage: 'Broke it.' },
			globals,
			slots: 'ssr',
		})
		expect(done.html).toContain('filing label')
		expect(done.html).toMatch(/role="status"[^>]*>\s*Saved it\.\s*</)
		expect(done.html).toMatch(/role="alert"[^>]*>\s*Broke it\.\s*</)
		expect(done.standIns('NcTextField')).toHaveLength(3)
		expect(done.html).toContain('>Save<')

		const busy = await renderWithStandIns(Settings, { data: { ...LOADED, saving: true }, globals, slots: 'ssr' })
		expect(busy.html).toContain('Saving…')
	})

	it('shows the loading icon alone in the vnode branch too', async () => {
		const view = await renderWithStandIns(Settings, { globals })
		expect(view.standIns('NcLoadingIcon')).toHaveLength(1)
		expect(view.find((vnode) => vnode.type === 'form')).toHaveLength(0)

		const told = await renderWithStandIns(Settings, { data: { ...LOADED, savedMessage: 'Saved it.' }, globals })
		expect(told.find((vnode) => vnode.props?.role === 'status').map(vnodeText)).toEqual(['Saved it.'])
	})
})
