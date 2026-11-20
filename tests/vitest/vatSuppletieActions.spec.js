/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * tax-vat-position-and-suppletie: the VAT position panel and its lines, and
 * Check for corrections, Prepare, Download and File reach their routes, say
 * what happened, and the pages, the handler map and appinfo/routes.php agree.
 *
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-1.3
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.1
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const actionsJs = fs.readFileSync(path.join(ROOT, 'src/manifestActions.js'), 'utf8')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) =>
		'/index.php' + url.replace(/\{(\w+)\}/g, (m, k) => params[k]),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (m, k) => vars[k]),
}))
const dialogs = { showError: vi.fn(), showSuccess: vi.fn(), showWarning: vi.fn() }
vi.mock('@nextcloud/dialogs', () => dialogs)
const bus = { emit: vi.fn() }
vi.mock('@nextcloud/event-bus', () => bus)
const axiosMock = { post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
const dialog = { spawnDialog: vi.fn(() => Promise.resolve()) }
vi.mock('@nextcloud/vue/functions/dialog', () => dialog)
vi.mock('../../src/modals/VatPositionLinesModal.vue', () => ({ default: { name: 'VatPositionLinesModal' } }))

/**
 * The last page with the given id across src/manifest.json and the fragments,
 * the one that wins the merge.
 *
 * @param {string} id The page id.
 * @return {object} The page.
 */
function page(id) {
	const files = [path.join(ROOT, 'src/manifest.json')].concat(
		fs
			.readdirSync(path.join(ROOT, 'src/manifest.d'))
			.filter((f) => f.endsWith('.json'))
			.sort()
			.map((f) => path.join(ROOT, 'src/manifest.d', f)),
	)
	const found = files.flatMap((f) =>
		(JSON.parse(fs.readFileSync(f, 'utf8')).pages || []).filter((p) => p.id === id),
	)
	return found[found.length - 1]
}

/**
 * A failed axios request.
 *
 * @param {number} status The HTTP status.
 * @param {object} data The body.
 * @return {Error}
 */
function failure(status, data) {
	const error = new Error('Request failed')
	error.response = { status, data }
	return error
}

describe('tax-vat-position-and-suppletie', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('checks a filed return and says a correction was written', async () => {
		const { checkVatReturnForCorrections } = await import('../../src/utils/vatSuppletieActions.js')
		axiosMock.post.mockResolvedValue({ data: { drift: true, data: { id: 'corr-q2' } } })

		const answer = await checkVatReturnForCorrections({ item: { id: 'q2-2026' } })

		expect(axiosMock.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/vat-returns/q2-2026/detect-drift')
		expect(answer.drift).toBe(true)
		expect(dialogs.showWarning).toHaveBeenCalledWith('The books differ from this return. A correction is on the corrections page.')
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
	})

	it('says the books still match when there is no drift, and shows a refusal', async () => {
		const { checkVatReturnForCorrections } = await import('../../src/utils/vatSuppletieActions.js')
		axiosMock.post.mockResolvedValueOnce({ data: { drift: false, data: null } })
		await checkVatReturnForCorrections({ item: { id: 'q2-2026' } })
		expect(dialogs.showSuccess).toHaveBeenCalledWith('The books still match this return.')

		axiosMock.post.mockRejectedValueOnce(failure(409, { error: 'VATReturn q3 is draft; only a filed (submitted or later) return can be checked for drift.' }))
		expect(await checkVatReturnForCorrections({ item: { id: 'q3' } })).toBeNull()
		expect(dialogs.showError).toHaveBeenCalledWith('VATReturn q3 is draft; only a filed (submitted or later) return can be checked for drift.')
	})

	it('checks a broken fiscal year and says what its corrections total', async () => {
		const { checkVatFiscalYearCorrections } = await import('../../src/utils/vatSuppletieActions.js')
		axiosMock.post.mockResolvedValueOnce({
			data: { data: { fiscalYear: '2025/2026', total: 1250, thresholdExceeded: true, returns: [{ drift: true }, { drift: false }] } },
		})

		const answer = await checkVatFiscalYearCorrections({ item: { id: 'fy-1' } })

		expect(axiosMock.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/fiscal-years/fy-1/vat-corrections/check')
		expect(answer.total).toBe(1250)
		expect(dialogs.showWarning).toHaveBeenCalledWith(
			'Fiscal year 2025/2026: the corrections total EUR 1250.00, above EUR 1,000. File a supplementary return; the corrections are on the corrections page.',
		)
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})

		axiosMock.post.mockResolvedValueOnce({ data: { data: { fiscalYear: '2025/2026', total: 105, thresholdExceeded: false, returns: [{ drift: true }] } } })
		await checkVatFiscalYearCorrections({ item: { id: 'fy-1' } })
		expect(dialogs.showWarning).toHaveBeenCalledWith(
			'Fiscal year 2025/2026: the corrections total EUR 105.00, EUR 1,000 or less, so they can go into the next return. The corrections are on the corrections page.',
		)

		axiosMock.post.mockResolvedValueOnce({ data: { data: { fiscalYear: '2025/2026', total: 0, thresholdExceeded: false, returns: [{ drift: false }] } } })
		await checkVatFiscalYearCorrections({ item: { id: 'fy-1' } })
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Fiscal year 2025/2026: the books still match its returns.')

		axiosMock.post.mockRejectedValueOnce(failure(409, { error: 'The fiscal year of this administration is the calendar year; check its returns one by one.' }))
		expect(await checkVatFiscalYearCorrections({ item: { id: 'fy-26' } })).toBeNull()
		expect(dialogs.showError).toHaveBeenCalledWith('The fiscal year of this administration is the calendar year; check its returns one by one.')
	})

	it('puts Check fiscal year on the fiscal year page and groups corrections by fiscal year', () => {
		const actions = page('FiscalYearDetail').config.actions
		expect(actions.find((a) => a.id === 'check-fiscal-year-vat').handler).toBe('checkVatFiscalYearCorrections')
		expect(actionsJs).toContain('checkVatFiscalYearCorrections')
		expect(routes).toContain("'url' => '/api/fiscal-years/{fiscalYearId}/vat-corrections/check', 'verb' => 'POST'")
		const corrections = page('BtwCorrecties').config
		expect(corrections.folderSidebar.field).toBe('fiscalYearLabel')
		expect(corrections.columns.map((c) => c.key)).toContain('fiscalYearCorrectionTotal')
	})

	it('prepares a correction and names the deadline above the threshold', async () => {
		const { prepareSupplementaryReturn } = await import('../../src/utils/vatSuppletieActions.js')
		axiosMock.post.mockResolvedValue({ data: { data: { id: 'corr-q2', thresholdExceeded: true, filingDeadline: '2026-12-28' } } })

		const prepared = await prepareSupplementaryReturn({ item: { id: 'corr-q2' } })

		expect(axiosMock.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/vat-corrections/corr-q2/prepare')
		expect(prepared.id).toBe('corr-q2')
		expect(dialogs.showSuccess).toHaveBeenCalledWith(
			'The correction is above EUR 1,000: file the supplementary return before 2026-12-28. The correction posting is staged.',
		)
	})

	it('downloads the return when Digipoort is not connected, and files it when it is', async () => {
		const { fileSupplementaryReturn } = await import('../../src/utils/vatSuppletieActions.js')
		const assign = vi.fn()
		const original = globalThis.window
		globalThis.window = { location: { assign, pathname: '/x' } }
		try {
			axiosMock.post.mockRejectedValueOnce(failure(409, { error: 'Digipoort is not connected.', download: '/apps/shillinq/api/vat-corrections/corr-q2/supplementary-return' }))
			expect(await fileSupplementaryReturn({ item: { id: 'corr-q2' } })).toBe('downloaded')
			expect(assign).toHaveBeenCalledWith('/index.php/apps/shillinq/api/vat-corrections/corr-q2/supplementary-return')
			expect(dialogs.showWarning).toHaveBeenCalledTimes(1)

			axiosMock.post.mockResolvedValueOnce({ data: { data: { state: 'submitted' } } })
			expect(await fileSupplementaryReturn({ item: { id: 'corr-q2' } })).toBe('filed')
			expect(axiosMock.post).toHaveBeenLastCalledWith('/index.php/apps/shillinq/api/vat-corrections/corr-q2/file')

			axiosMock.post.mockRejectedValueOnce(failure(409, { error: 'Only a supplementary return still in draft can be filed.' }))
			expect(await fileSupplementaryReturn({ item: { id: 'corr-q2' } })).toBe('failed')
			expect(dialogs.showError).toHaveBeenCalledWith('Only a supplementary return still in draft can be filed.')
		} finally {
			globalThis.window = original
		}
	})

	it('opens the lines of the row the position table hands over, last', async () => {
		const { openVatPositionLines } = await import('../../src/utils/vatSuppletieActions.js')
		const row = { returnBox: '1a', lines: [{ glAccountNumber: '8000', taxableAmount: 20000, vatAmount: 4200 }] }

		openVatPositionLines({ item: null }, row)

		expect(dialog.spawnDialog).toHaveBeenCalledWith({ name: 'VatPositionLinesModal' }, { box: row })
		expect(openVatPositionLines({ item: null })).toBeNull()
	})

	it('adds up the lines behind a box in whole cents', async () => {
		const { positionLineTotals } = await import('../../src/utils/vatPositionLines.js')

		expect(positionLineTotals([
			{ taxableAmount: 0.1, vatAmount: 0.021 },
			{ taxableAmount: 0.2, vatAmount: 0.042 },
			{ taxableAmount: 19999.7, vatAmount: 4199.94 },
		])).toEqual({ base: 20000, vat: 4200 })
		expect(positionLineTotals(null)).toEqual({ base: 0, vat: 0 })
	})

	it('wires the pages, the handler map and the routes together', () => {
		const reports = page('VATReports').config
		const boxes = reports.widgets.find((w) => w.id === 'position-boxes')
		expect(boxes.content.endpointSource.url).toBe('/apps/shillinq/api/vat-position')
		expect(boxes.content.endpointSource.responsePath).toBe('data.boxes')
		expect(boxes.content.actions[0].handler).toBe('openVatPositionLines')
		expect(reports.pageFilters[0]).toMatchObject({ key: 'vatPeriod', type: 'quarter' })

		const check = page('VATReturnDetail').config.actions.find((a) => a.id === 'check-corrections')
		expect(check.handler).toBe('checkVatReturnForCorrections')

		const correction = page('BtwCorrectieDetail').config
		expect(correction.actions.map((a) => a.handler)).toEqual([
			'prepareSupplementaryReturn',
			'downloadSupplementaryReturn',
			'fileSupplementaryReturn',
		])

		for (const handler of ['checkVatReturnForCorrections', 'prepareSupplementaryReturn', 'downloadSupplementaryReturn', 'fileSupplementaryReturn', 'openVatPositionLines']) {
			expect(actionsJs).toContain(handler)
		}
		for (const url of ['/api/vat-position', '/api/vat-returns/{returnId}/detect-drift', '/api/vat-corrections/{correctionId}/prepare', '/api/vat-corrections/{correctionId}/supplementary-return', '/api/vat-corrections/{correctionId}/file']) {
			expect(routes).toContain(`'url' => '${url}'`)
		}
	})
})
