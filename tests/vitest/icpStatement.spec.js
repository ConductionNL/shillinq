/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * tax-icp-and-reverse-charge 2.3: the ICP page. The manifest, the handler
 * map and appinfo/routes.php are held to each other; the period, the
 * reconciliation and the refusals are put in words; the actions call the
 * routes the controller serves and say what came back; the Prepare dialog
 * shows the lines and the comparison with box 3b. Only HTTP, the toasts, the
 * event bus, the dialog spawner and the @nextcloud/vue components are
 * replaced.
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns, vnodeText } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const toast = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn(), showWarning: vi.fn() }))
const bus = vi.hoisted(() => ({ emit: vi.fn() }))
const dialog = vi.hoisted(() => ({ spawnDialog: vi.fn(() => 'dialog') }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/vue/functions/dialog', () => dialog)
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (all, key) => (key in vars ? String(vars[key]) : all)),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return {
		NcButton: standIn('NcButton'),
		NcDialog: standIn('NcDialog'),
		NcSelect: standIn('NcSelect'),
		NcTextField: standIn('NcTextField'),
	}
})

const api = await import('../../src/utils/icpStatementApi.js')
const actions = await import('../../src/utils/icpStatementActions.js')
const { default: PrepareIcpStatementModal } = await import('../../src/modals/PrepareIcpStatementModal.vue')

const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.d/tax-icp-and-reverse-charge.json'), 'utf8'))
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')
const actionsJs = fs.readFileSync(path.join(ROOT, 'src/manifestActions.js'), 'utf8')
const page = (id) => fragment.pages.find((p) => p.id === id)
const statement = { id: 's1', period: '2026-Q3', administrationId: 'adm-1', status: 'finalized' }

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	for (const fn of [...Object.values(toast), bus.emit, dialog.spawnDialog]) {
		fn.mockClear()
	}
})

describe('the ICP page in the manifest', () => {
	it('lists the line-bearing statements and prepares one from the header', () => {
		const index = page('IcpOpgaaf')
		expect(index.route).toBe('/belastingen/icp-opgaaf')
		expect(index.config.schema).toBe('IcpOpgaaf')
		expect(index.config.columns.map((c) => c.key)).toEqual(['period', 'periodicity', 'type', 'total', 'status'])
		expect(index.config.headerActions.map((a) => a.handler)).toEqual(['openPrepareIcpStatement'])
	})

	it('offers Reconcile, Download XML and, on a finalised statement, File', () => {
		const detail = page('IcpOpgaafDetail')
		expect(detail.config.schema).toBe('IcpOpgaaf')
		const byId = Object.fromEntries(detail.config.headerActions.map((a) => [a.id, a]))
		expect(Object.values(byId).map((a) => a.handler)).toEqual(['reconcileIcpStatement', 'downloadIcpStatementXml', 'fileIcpStatement'])
		expect(byId['file-icp-statement'].visibleWhen).toEqual({ field: 'status', op: 'eq', value: 'finalized' })
		expect(byId['reconcile-icp-statement'].visibleWhen).toBeUndefined()
	})

	it('names handlers both maps provide and routes the controller serves', () => {
		for (const name of ['openPrepareIcpStatement', 'reconcileIcpStatement', 'downloadIcpStatementXml', 'fileIcpStatement']) {
			expect(typeof actions[name]).toBe('function')
			expect(actionsJs).toContain('\t' + name + ',\n')
		}
		expect(routes).toContain("['name' => 'icpStatement#prepare', 'url' => '/api/icp/statements/prepare', 'verb' => 'POST']")
		expect(routes).toContain("['name' => 'icpStatement#xml', 'url' => '/api/icp/statements/xml', 'verb' => 'GET']")
		expect(routes).toContain("['name' => 'icpStatement#filing', 'url' => '/api/icp/statements/filing', 'verb' => 'GET']")
		expect(routes).toContain("['name' => 'icpStatement#file', 'url' => '/api/icp/statements/file', 'verb' => 'POST']")
		expect(routes).toContain("['name' => 'icp#reconcile', 'url' => '/api/icp/reconcile', 'verb' => 'GET']")
	})
})

describe('the ICP helpers', () => {
	it('spells a period as the server reads it, and nothing for an incomplete one', () => {
		expect(api.icpPeriod('quarter', '2026', '3')).toBe('2026-Q3')
		expect(api.icpPeriod('month', 2026, 7)).toBe('2026-07')
		expect(api.icpPeriod('month', 2026, 13)).toBe('')
		expect(api.icpPeriod('quarter', 2026, 5)).toBe('')
		expect(api.icpPeriod('quarter', '', 1)).toBe('')
		expect(api.icpPeriod('quarter', 2026, 1.5)).toBe('')
	})

	it('picks the last finished quarter or month', () => {
		expect(api.lastFinishedIcpPeriod('quarter', new Date(2026, 10, 14))).toEqual({ year: 2026, number: 3 })
		expect(api.lastFinishedIcpPeriod('quarter', new Date(2026, 1, 1))).toEqual({ year: 2025, number: 4 })
		expect(api.lastFinishedIcpPeriod('month', new Date(2026, 10, 14))).toEqual({ year: 2026, number: 10 })
		expect(api.lastFinishedIcpPeriod('month', new Date(2026, 0, 9))).toEqual({ year: 2025, number: 12 })
	})

	it('puts the reconciliation with box 3b in words', () => {
		expect(api.reconciliationMessage({ icpTotal: 15000, rubriek3b: 15000.4, matches: true, missing: false, difference: 0.4 })).toEqual({
			kind: 'success',
			text: 'The statement total EUR 15000.00 matches box 3b (EUR 15000.40) within EUR 1; the difference is EUR 0.40.',
		})
		expect(api.reconciliationMessage({ icpTotal: 15000, rubriek3b: 14000, matches: false, missing: false, difference: 1000 })).toEqual({
			kind: 'error',
			text: 'The statement total EUR 15000.00 differs from box 3b (EUR 14000.00) by EUR 1000.00. It cannot be finalised until they match within EUR 1.',
		})
		for (const missing of [undefined, { missing: true }, { rubriek3b: null }]) {
			expect(api.reconciliationMessage(missing).kind).toBe('warning')
		}
	})

	it('says why a request was refused', () => {
		const refused = (status, data = {}) => api.icpErrorText({ response: { status, data } })
		expect(refused(409, { error: 'statement-filed', message: 'x' })).toBe('The statement of this period has been filed and is not replaced. File a correction instead.')
		expect(refused(409, { error: 'not-filed', message: 'Only a finalised ICP statement can be filed; this one is draft.' })).toBe('Only a finalised ICP statement can be filed; this one is draft.')
		expect(refused(422, { message: 'Dutch ICP return is not offered.' })).toBe('Dutch ICP return is not offered.')
		expect(refused(400)).toBe('Choose a quarter or a month and a year.')
		expect(refused(404)).toBe('You cannot work on the ICP statement of this administration.')
		expect(api.icpErrorText(new Error('down'))).toBe('The ICP statement could not be processed. Try again, or ask your administrator to check the log.')
	})

	it('puts what Digipoort answered in words', () => {
		expect(api.filingMessage({ status: 'submitted', reference: 'DGP-1' })).toEqual({ kind: 'success', text: 'The statement was submitted to Digipoort (reference DGP-1).' })
		expect(api.filingMessage({ status: 'rejected' }).kind).toBe('error')
		expect(api.filingMessage({ status: 'finalized' }).kind).toBe('warning')
	})

	it('sends the period and administration the controller reads', async () => {
		http.post.mockResolvedValueOnce({ data: { replaced: false } })
		expect(await api.prepareIcpStatement({ administrationId: 'adm-1', period: '2026-Q3' })).toEqual({ replaced: false })
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/icp/statements/prepare', { period: '2026-Q3', administration_id: 'adm-1' })

		http.get.mockResolvedValueOnce({ data: { matches: true } })
		expect(await api.reconcileIcpPeriod(statement)).toEqual({ matches: true })
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/icp/reconcile', { params: { period_id: '2026-Q3', administration_id: 'adm-1' } })

		expect(api.icpStatementXmlUrl({ period: '2026-Q3', administrationId: 'adm 1' })).toBe('/index.php/apps/shillinq/api/icp/statements/xml?period=2026-Q3&administration_id=adm%201')
	})

	it('reads filing as unavailable unless the server says it is available', async () => {
		http.get.mockResolvedValueOnce({ data: { available: true } })
		expect(await api.icpFilingAvailable()).toBe(true)
		http.get.mockResolvedValueOnce({ data: { available: 'yes' } })
		expect(await api.icpFilingAvailable()).toBe(false)
		http.get.mockRejectedValueOnce(new Error('down'))
		expect(await api.icpFilingAvailable()).toBe(false)
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/icp/statements/filing')
	})
})

describe('the ICP actions', () => {
	it('opens the Prepare dialog', () => {
		expect(actions.openPrepareIcpStatement()).toBe('dialog')
		expect(dialog.spawnDialog.mock.calls[0]).toEqual([PrepareIcpStatementModal, {}])
	})

	it('reconciles the statement and toasts the outcome', async () => {
		http.get.mockResolvedValueOnce({ data: { icpTotal: 10, rubriek3b: 20, matches: false, missing: false, difference: 10 } })
		await actions.reconcileIcpStatement({ item: statement })
		expect(toast.showError).toHaveBeenCalledWith('The statement total EUR 10.00 differs from box 3b (EUR 20.00) by EUR 10.00. It cannot be finalised until they match within EUR 1.')

		http.get.mockRejectedValueOnce({ response: { status: 404 } })
		expect(await actions.reconcileIcpStatement({ item: statement })).toBeNull()
		expect(toast.showError).toHaveBeenLastCalledWith('You cannot work on the ICP statement of this administration.')

		expect(await actions.reconcileIcpStatement({ item: { id: 'x' } })).toBeNull()
		expect(http.get).toHaveBeenCalledTimes(2)
	})

	it('downloads the XML of the statement', () => {
		const target = { location: { assign: vi.fn() } }
		expect(actions.downloadIcpStatementXml({ item: statement }, target)).toBe('/index.php/apps/shillinq/api/icp/statements/xml?period=2026-Q3&administration_id=adm-1')
		expect(target.location.assign).toHaveBeenCalledWith('/index.php/apps/shillinq/api/icp/statements/xml?period=2026-Q3&administration_id=adm-1')
		expect(actions.downloadIcpStatementXml(null, target)).toBe('')
		expect(target.location.assign).toHaveBeenCalledTimes(1)
	})

	it('files a finalised statement when Digipoort is bound, and refreshes the page', async () => {
		http.get.mockResolvedValueOnce({ data: { available: true } })
		http.post.mockResolvedValueOnce({ data: { status: 'submitted', deliveryStatus: 'ACCEPTED', reference: 'DGP-7' } })

		expect(await actions.fileIcpStatement({ item: statement })).toEqual({ status: 'submitted', deliveryStatus: 'ACCEPTED', reference: 'DGP-7' })
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/icp/statements/file', { period: '2026-Q3', administration_id: 'adm-1' })
		expect(toast.showSuccess).toHaveBeenCalledWith('The statement was submitted to Digipoort (reference DGP-7).')
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
	})

	it('files nothing for a draft or while Digipoort is not bound, and says why', async () => {
		expect(await actions.fileIcpStatement({ item: { ...statement, status: 'draft' } })).toBeNull()
		expect(toast.showError).toHaveBeenCalledWith('Finalise the statement before you file it.')

		http.get.mockResolvedValueOnce({ data: { available: false } })
		expect(await actions.fileIcpStatement({ item: statement })).toBeNull()
		expect(toast.showWarning).toHaveBeenCalledWith('Filing through Digipoort is not available yet. Download the XML and file it yourself.')
		expect(http.post).not.toHaveBeenCalled()

		http.get.mockResolvedValueOnce({ data: { available: true } })
		http.post.mockRejectedValueOnce({ response: { status: 409, data: { error: 'not-filed', message: 'The ICP statement does not reconcile with box 3b of the VAT return within EUR 1.' } } })
		expect(await actions.fileIcpStatement({ item: statement })).toBeNull()
		expect(toast.showError).toHaveBeenLastCalledWith('The ICP statement does not reconcile with box 3b of the VAT return within EUR 1.')
		expect(bus.emit).not.toHaveBeenCalled()
	})
})

describe('PrepareIcpStatementModal', () => {
	it('asks for a quarter by default and a month when chosen', async () => {
		const quarter = await renderWithStandIns(PrepareIcpStatementModal)
		const number = quarter.standIns('NcTextField').find((a) => a['data-testid'] === 'prepare-icp-number')
		expect(number.label).toBe('Quarter (1 to 4)')
		const month = await renderWithStandIns(PrepareIcpStatementModal, { data: { kind: 'month' } })
		expect(month.standIns('NcTextField').find((a) => a['data-testid'] === 'prepare-icp-number').label).toBe('Month (1 to 12)')
		expect(quarter.byTestId('prepare-icp-submit').props.disabled).toBe(true)
	})

	it('prepares the period and shows its lines and the comparison with box 3b', async () => {
		const view = await renderWithStandIns(PrepareIcpStatementModal, { data: { administrationId: 'adm-1', year: '2026', number: '3' } })
		http.post.mockResolvedValueOnce({
			data: {
				statement: {
					period: '2026-Q3',
					total: 15000,
					lines: [
						{ buyerVatId: 'BE0123456749', supplyType: 'L', amountExclVat: 12000 },
						{ buyerVatId: 'DE123456789', supplyType: 'S', amountExclVat: 3000 },
					],
				},
				reconciliation: { icpTotal: 15000, rubriek3b: 15000, matches: true, missing: false, difference: 0 },
				replaced: false,
			},
		})

		await view.byTestId('prepare-icp-submit').props.onClick()
		const done = await view.rerender()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/icp/statements/prepare', { period: '2026-Q3', administration_id: 'adm-1' })
		expect(vnodeText(done.byTestId('prepare-icp-created'))).toBe('Draft statement 2026-Q3: 2 lines, total EUR 15000.00.')
		expect(vnodeText(done.byTestId('prepare-icp-lines'))).toContain('BE0123456749 Goods 12000.00')
		expect(vnodeText(done.byTestId('prepare-icp-lines'))).toContain('DE123456789 Services 3000.00')
		expect(vnodeText(done.byTestId('prepare-icp-reconciliation'))).toBe('The statement total EUR 15000.00 matches box 3b (EUR 15000.00) within EUR 1; the difference is EUR 0.00.')
		expect(done.byTestId('prepare-icp-submit')).toBeUndefined()
	})

	it('says a filed statement is not replaced', async () => {
		const view = await renderWithStandIns(PrepareIcpStatementModal, { data: { administrationId: 'adm-1' } })
		http.post.mockRejectedValueOnce({ response: { status: 409, data: { error: 'statement-filed' } } })

		await view.vm.submit()
		const again = await view.rerender()

		expect(vnodeText(again.byTestId('prepare-icp-error'))).toBe('The statement of this period has been filed and is not replaced. File a correction instead.')
		expect(again.byTestId('prepare-icp-lines')).toBeUndefined()
	})

	it('loads the active administration, or says why it cannot', async () => {
		const view = await renderWithStandIns(PrepareIcpStatementModal)
		http.get.mockResolvedValueOnce({ data: { activeAdministrationId: 'adm-9' } })
		await PrepareIcpStatementModal.mounted.call(view.vm)
		expect(view.vm.administrationId).toBe('adm-9')

		http.get.mockResolvedValueOnce({ data: {} })
		await PrepareIcpStatementModal.mounted.call(view.vm)
		expect(view.vm.error).toBe('Choose an administration first.')

		http.get.mockRejectedValueOnce(new Error('down'))
		await PrepareIcpStatementModal.mounted.call(view.vm)
		expect(view.vm.error).toBe('The administration could not be loaded.')
		expect(view.vm.supplyTypeLabel('T')).toBe('Triangulation')
		expect(view.vm.supplyTypeLabel('X')).toBe('X')
	})
})
