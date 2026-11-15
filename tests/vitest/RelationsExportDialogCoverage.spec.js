/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * RelationsExportDialog.vue driven through its template
 * (reporting-relation-both-sides REQ-RRBS-003): the period fields wired to
 * the download link, the refusal of an empty or reversed period, and the
 * buttons and dialog closing it. Only the router and the @nextcloud/vue
 * components are replaced.
 */

import { describe, expect, it, vi } from 'vitest'
import { renderWithStandIns } from './helpers/standInRender.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return { NcButton: standIn('NcButton'), NcDialog: standIn('NcDialog') }
})

const { default: RelationsExportDialog } = await import('../../src/modals/RelationsExportDialog.vue')

/**
 * The download button's attributes for a period.
 *
 * @param {object} data The period.
 * @return {Promise<object>} The attributes.
 */
async function download(data) {
	const view = await renderWithStandIns(RelationsExportDialog, { props: { open: true }, data })
	return view.standIns('NcButton').find((attrs) => attrs['data-testid'] === 'relations-export-download')
}

describe('RelationsExportDialog', () => {
	it('renders no dialog while closed', async () => {
		const view = await renderWithStandIns(RelationsExportDialog)
		expect(view.standIns('NcDialog')).toEqual([])
	})

	it('links the CSV for the period typed into the fields', async () => {
		const view = await renderWithStandIns(RelationsExportDialog, { props: { open: true }, data: { from: '2025-01-01', to: '2025-03-31' } })
		const from = view.find((vnode) => vnode.props?.id === 'relations-export-from')[0]
		const to = view.find((vnode) => vnode.props?.id === 'relations-export-to')[0]
		expect(from.props.type).toBe('date')

		from.props['onUpdate:modelValue']('2025-02-01')
		to.props['onUpdate:modelValue']('2025-02-28')
		const again = await view.rerender()
		const link = again.standIns('NcButton').find((attrs) => attrs['data-testid'] === 'relations-export-download')
		expect(link.href).toBe('/index.php/apps/shillinq/api/relations/both-sides?format=csv&from=2025-02-01&to=2025-02-28')
		expect(link.disabled).toBe(false)
	})

	it.each([
		['a missing start', { from: '', to: '2025-12-31' }],
		['a missing end', { from: '2025-01-01', to: '' }],
		['an end before the start', { from: '2025-06-01', to: '2025-05-31' }],
	])('refuses a download with %s', async (label, data) => {
		expect((await download(data)).disabled).toBe(true)
	})

	it('allows a single-day period', async () => {
		expect((await download({ from: '2025-06-01', to: '2025-06-01' })).disabled).toBe(false)
	})

	it('closes from the dialog, the cancel button and after the download', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(RelationsExportDialog, { props: { open: true, onClose } })
		view.standIns('NcDialog')[0].onClosing()
		for (const button of view.standIns('NcButton')) {
			button.onClick()
		}
		expect(onClose).toHaveBeenCalledTimes(3)
	})

	it('writes the fields and buttons when the slots render the server way', async () => {
		const html = (await renderWithStandIns(RelationsExportDialog, { props: { open: true }, data: { from: '2025-01-01', to: '2025-12-31' }, slots: 'ssr' })).html
		expect(html).toContain('for="relations-export-from"')
		expect(html).toContain('value="2025-12-31"')
		expect(html).toContain('Download CSV')
		expect(html).toContain('Cancel')
	})
})
