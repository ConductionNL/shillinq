/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BookingWidgetKeys driven through its buttons, with their labels rendered
 * the server way: which buttons are enabled for the identifiers entered,
 * Generate posting to the create endpoint and showing the key once, and
 * Revoke clearing a shown key. Only HTTP (fetch), the request token and the
 * @nextcloud/vue button are replaced.
 *
 * @spec openspec/specs/bookings-self-service-widget/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns } from './helpers/standInRender.js'

vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return { NcButton: standIn('NcButton') }
})

const { default: Keys } = await import('../../src/views/BookingWidgetKeys.vue')

const globals = { t: (app, text) => text }
const fetchMock = vi.fn()

beforeEach(() => {
	fetchMock.mockReset()
	vi.stubGlobal('fetch', fetchMock)
	vi.stubGlobal('OC', { requestToken: 'tok' })
})

afterEach(() => {
	vi.unstubAllGlobals()
})

/**
 * The three buttons' attributes, in template order.
 *
 * @param {object} view A rendered view.
 * @return {object} generate, rotate and revoke.
 */
function buttons(view) {
	const [generate, rotate, revoke] = view.standIns('NcButton')
	return { generate, rotate, revoke }
}

describe('BookingWidgetKeys template events', () => {
	it('enables the buttons only for the identifiers each one needs', async () => {
		const none = buttons(await renderWithStandIns(Keys, { globals, slots: 'ssr' }))
		expect([none.generate.disabled, none.rotate.disabled, none.revoke.disabled]).toEqual([true, true, true])

		const business = buttons(await renderWithStandIns(Keys, { data: { businessId: 'biz-1' }, globals, slots: 'ssr' }))
		expect([business.generate.disabled, business.rotate.disabled, business.revoke.disabled]).toEqual([true, false, false])

		const both = await renderWithStandIns(Keys, { data: { businessId: 'biz-1', administrationId: 'adm-1' }, globals, slots: 'ssr' })
		expect(buttons(both).generate.disabled).toBe(false)
		expect(both.html).toContain('Generate key')
		expect(both.html).toContain('Rotate key')
		expect(both.html).toContain('Revoke key')

		const busy = buttons(await renderWithStandIns(Keys, { data: { businessId: 'biz-1', administrationId: 'adm-1', busy: true }, globals, slots: 'ssr' }))
		expect([busy.generate.disabled, busy.rotate.disabled, busy.revoke.disabled]).toEqual([true, true, true])
	})

	it('generates a key through the create endpoint and shows it once', async () => {
		fetchMock.mockResolvedValue({ json: async () => ({ success: true, apiKey: 'wsk_123', message: 'Key created.' }) })
		const view = await renderWithStandIns(Keys, { data: { businessId: 'biz-1', administrationId: 'adm-1' }, globals, slots: 'ssr' })

		await buttons(view).generate.onClick()

		expect(fetchMock).toHaveBeenCalledWith('/index.php/apps/shillinq/api/widget/admin/keys/create', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', requesttoken: 'tok' },
			body: JSON.stringify({ businessId: 'biz-1', administrationId: 'adm-1' }),
		})
		const after = await view.rerender()
		expect(after.html).toContain('<code')
		expect(after.html).toContain('wsk_123')
		expect(after.html).toContain('Copy this key now')
		expect(after.html).toContain('Key created.')
	})

	it('revokes through the revoke endpoint and hides a key that was shown', async () => {
		fetchMock.mockResolvedValue({ json: async () => ({ success: true, message: 'Key revoked.' }) })
		const view = await renderWithStandIns(Keys, { data: { businessId: 'biz-1', plaintextKey: 'wsk_old' }, globals, slots: 'ssr' })
		expect(view.html).toContain('wsk_old')

		await buttons(view).revoke.onClick()

		expect(fetchMock.mock.calls[0][0]).toBe('/index.php/apps/shillinq/api/widget/admin/keys/revoke')
		expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ businessId: 'biz-1' })
		const after = await view.rerender()
		expect(after.html).not.toContain('wsk_old')
		expect(after.html).toContain('Key revoked.')
	})
})
