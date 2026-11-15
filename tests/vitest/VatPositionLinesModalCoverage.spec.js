/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * VatPositionLinesModal rendered server-side (no DOM): the posted lines
 * behind one box of the running VAT position, their totals in euros, the
 * empty state and the close action (tax-vat-position-and-suppletie 1.3).
 */

import { describe, expect, it, vi } from 'vitest'
import { renderWithStandIns, vnodeText } from './helpers/standInRender.js'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match)),
}))
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return { NcButton: standIn('NcButton'), NcDialog: standIn('NcDialog') }
})

const { default: VatPositionLinesModal } = await import('../../src/modals/VatPositionLinesModal.vue')

/**
 * Render the modal for a box, its slots the server way.
 *
 * @param {object} box The position box.
 * @return {Promise<string>} The HTML, without fragment comments or scope ids.
 */
async function render(box) {
	const view = await renderWithStandIns(VatPositionLinesModal, { props: { box }, slots: 'ssr' })
	return view.html.replace(/<!--[[\]]-->/g, '').replace(/ data-v-[0-9a-f]+/g, '')
}

/**
 * The euro amount as the modal writes it.
 *
 * @param {number} value The amount.
 * @return {string} The formatted amount.
 */
function euro(value) {
	return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'EUR' }).format(value)
}

describe('VatPositionLinesModal', () => {
	it('names the box it shows the lines of', async () => {
		expect((await renderWithStandIns(VatPositionLinesModal, { props: { box: { returnBox: '1a', lines: [] } } })).standIns('NcDialog')[0].name).toBe('Lines behind box 1a')
	})

	it('says no lines make up the box when there are none, and draws no table', async () => {
		const html = await render({ returnBox: '1b', lines: [] })
		expect(html).toContain('No posted lines make up this box yet.')
		expect(html).not.toContain('data-testid="vat-position-lines"')
	})

	it('treats a box without a line list as empty', async () => {
		const html = await render({ returnBox: '' })
		expect((await renderWithStandIns(VatPositionLinesModal, { props: { box: {} } })).standIns('NcDialog')[0].name).toBe('Lines behind box ')
		expect(html).toContain('No posted lines make up this box yet.')
	})

	it('lists every line with its ledger, entry and amounts, and totals them to the cent', async () => {
		const html = await render({
			returnBox: '1a',
			lines: [
				{ glAccountNumber: '8000', description: 'Omzet hoog', glTransactionId: 'gl-1', lineNumber: 1, taxableAmount: 100.1, vatAmount: 21.02 },
				{ glAccountNumber: '8010', description: 'Omzet laag', glTransactionId: 'gl-2', taxableAmount: '0.2', vatAmount: 'x' },
			],
		})

		expect(html).toContain('data-testid="vat-position-lines"')
		expect(html).not.toContain('No posted lines make up this box yet.')
		expect(html).toContain('<td>8000</td><td>Omzet hoog</td><td>gl-1</td>')
		expect(html).toContain('<td>8010</td><td>Omzet laag</td><td>gl-2</td>')
		expect(html).toContain(euro(100.1))
		expect(html).toContain(euro(0.2))
		expect(html).toContain(euro(0))
		// 100.10 + 0.20 and 21.02 + nothing.
		expect(html).toContain(euro(100.3))
		expect(html).toContain(euro(21.02))
		expect(html).toContain('Close')
	})

	it('builds the same table as vnodes and closes from the dialog and the button', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(VatPositionLinesModal, {
			props: { onClose, box: { returnBox: '2a', lines: [{ glAccountNumber: '1500', description: 'Voorbelasting', glTransactionId: 'gl-9', taxableAmount: 10, vatAmount: 2.1 }] } },
		})

		expect(vnodeText(view.byTestId('vat-position-lines'))).toContain('1500 Voorbelasting gl-9')
		expect(vnodeText(view.byTestId('vat-position-lines'))).toContain(euro(2.1))

		view.standIns('NcDialog')[0].onClosing()
		view.standIns('NcButton')[0].onClick()
		expect(onClose).toHaveBeenCalledTimes(2)

		const empty = await renderWithStandIns(VatPositionLinesModal, { props: { box: { lines: [] } } })
		expect(empty.byTestId('vat-position-lines')).toBeUndefined()
		expect(empty.html).toContain('No posted lines make up this box yet.')
	})

	it('writes an amount that is not a number as zero euros', () => {
		expect(VatPositionLinesModal.methods.money('abc')).toBe(euro(0))
		expect(VatPositionLinesModal.methods.money(12.5)).toBe(euro(12.5))
	})
})
