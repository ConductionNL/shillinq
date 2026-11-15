/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * MeterReadingImportModal.vue driven through its template: the file picker
 * and row count before importing, the imported count and refused rows after,
 * the error line, and the file input and buttons wired to the real handlers.
 * Only HTTP and the @nextcloud/vue components are replaced;
 * src/utils/usageBilling.js and downPaymentApi.js run.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns, vnodeText } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (all, key) => (key in vars ? String(vars[key]) : all)),
}))
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return { NcButton: standIn('NcButton'), NcDialog: standIn('NcDialog') }
})

const { default: MeterReadingImportModal } = await import('../../src/modals/MeterReadingImportModal.vue')

const ROW = { customerId: 'c-1', resourceType: 'water', quantity: '12', periodStart: '2026-10-01', periodEnd: '2026-10-31' }

/**
 * A file-input change event carrying one file with this text, or none.
 *
 * @param {string|null} text The file's text, or null for no file.
 * @return {object} The event.
 */
function fileEvent(text) {
	return { target: { files: text === null ? [] : [{ text: async () => text }] } }
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

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
})

describe('MeterReadingImportModal rendered', () => {
	it('asks for a CSV file and imports nothing until it has rows', async () => {
		const view = await renderWithStandIns(MeterReadingImportModal, { data: { administrationId: 'adm-1' } })
		expect(view.standIns('NcDialog')[0].name).toBe('Import readings')
		expect(view.byTestId('meter-reading-file').props.accept).toBe('.csv,text/csv')
		expect(view.byTestId('meter-reading-count')).toBeUndefined()
		expect(view.byTestId('meter-reading-submit').props.disabled).toBe(true)
		expect(buttons(view)).toEqual(['Cancel', 'Import'])
	})

	it('counts the rows and imports them only for a known administration', async () => {
		const ready = await renderWithStandIns(MeterReadingImportModal, { data: { administrationId: 'adm-1', rows: [ROW, ROW] } })
		expect(vnodeText(ready.byTestId('meter-reading-count'))).toBe('2 rows to import.')
		expect(ready.byTestId('meter-reading-submit').props.disabled).toBe(false)

		const noAdministration = await renderWithStandIns(MeterReadingImportModal, { data: { rows: [ROW] } })
		expect(noAdministration.byTestId('meter-reading-submit').props.disabled).toBe(true)

		const busy = await renderWithStandIns(MeterReadingImportModal, { data: { administrationId: 'adm-1', rows: [ROW], submitting: true } })
		expect(busy.byTestId('meter-reading-submit').props.disabled).toBe(true)
	})

	it('reports what was imported and every refused row, then offers only Close', async () => {
		const view = await renderWithStandIns(MeterReadingImportModal, {
			data: { result: { created: [{}, {}, {}], refused: [{ row: 4, reason: 'Unknown customer' }, { row: 7, reason: 'No quantity' }] } },
		})
		expect(vnodeText(view.byTestId('meter-reading-created'))).toBe('3 readings imported. Rate them to see their amount.')
		expect(vnodeText(view.byTestId('meter-reading-refused'))).toBe('Row 4: Unknown customer Row 7: No quantity')
		expect(view.byTestId('meter-reading-file')).toBeUndefined()
		expect(buttons(view)).toEqual(['Close'])

		const clean = await renderWithStandIns(MeterReadingImportModal, { data: { result: { created: [{}], refused: [] } } })
		expect(clean.byTestId('meter-reading-refused')).toBeUndefined()
	})

	it('writes the same states when the slots render the server way', async () => {
		const html = async (data) => (await renderWithStandIns(MeterReadingImportModal, { data, slots: 'ssr' })).html.replace(/<!--[[\]]-->/g, '')

		const before = await html({ administrationId: 'adm-1', rows: [ROW], error: 'Let op' })
		expect(before).toContain('Readings file')
		expect(before).toContain('1 rows to import.')
		expect(before).toContain('Let op')
		expect(before).toContain('Import')

		const after = await html({ result: { created: [{}], refused: [{ row: 2, reason: 'Kapot' }] } })
		expect(after).toContain('1 readings imported.')
		expect(after).toContain('Refused rows')
		expect(after).toContain('Row 2: Kapot')
		expect(after).toContain('Close')
	})
})

describe('MeterReadingImportModal handlers', () => {
	it('loads the active administration on mount, or says why it could not', async () => {
		http.get.mockResolvedValueOnce({ data: { activeAdministrationId: 5 } })
		const self = { ...MeterReadingImportModal.data() }
		await MeterReadingImportModal.mounted.call(self)
		expect(self.administrationId).toBe('5')

		http.get.mockRejectedValueOnce(new Error('offline'))
		const failed = { ...MeterReadingImportModal.data() }
		await MeterReadingImportModal.mounted.call(failed)
		expect(failed.error).toBe('The administration could not be loaded.')
	})

	it('reads the chosen file into rows, refuses one without readings and clears on no file', async () => {
		const view = await renderWithStandIns(MeterReadingImportModal, { data: { administrationId: 'adm-1' } })
		const change = view.byTestId('meter-reading-file').props.onChange

		await change(fileEvent('customerId;resourceType;quantity;periodStart;periodEnd\nc-1;water;12;2026-10-01;2026-10-31\n'))
		expect(view.vm.rows).toEqual([ROW])
		expect(view.vm.error).toBe('')

		await change(fileEvent('customerId,quantity\n'))
		expect(view.vm.rows).toEqual([])
		expect(view.vm.error).toBe('The file has no readings below its header line.')

		view.vm.rows = [ROW]
		await change(fileEvent(null))
		expect(view.vm.rows).toEqual([])
		expect(view.vm.error).toBe('')
	})

	it('imports the rows for the administration and closes with the result', async () => {
		const result = { created: [{}], refused: [] }
		http.post.mockResolvedValue({ data: result })
		const onClose = vi.fn()
		const view = await renderWithStandIns(MeterReadingImportModal, { props: { onClose }, data: { administrationId: 'adm-1', rows: [ROW] } })

		await view.byTestId('meter-reading-submit').props.onClick()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/meter-readings/import', { administrationId: 'adm-1', rows: [ROW] })
		expect(view.vm.result).toEqual(result)
		expect(view.vm.submitting).toBe(false)

		view.standIns('NcDialog')[0].onClosing()
		expect(onClose).toHaveBeenCalledWith(result)
	})

	it('keeps the rows with the reason when the import fails', async () => {
		http.post.mockRejectedValue({ response: { data: { message: 'Periode gesloten' } } })
		const view = await renderWithStandIns(MeterReadingImportModal, { data: { administrationId: 'adm-1', rows: [ROW] } })

		await view.vm.submit()

		expect(view.vm.result).toBeNull()
		expect(view.vm.rows).toEqual([ROW])
		expect(view.vm.error).toBe('Periode gesloten')
	})
})
