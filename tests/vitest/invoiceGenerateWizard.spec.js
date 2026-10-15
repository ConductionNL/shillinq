/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Generate invoices (board ShFactuurGenereren): the header action on Sales
 * invoices opens the three-step dialog, the lines step offers the right rows,
 * and the request the dialog sends is the one InvoiceGenerationRequest reads,
 * to routes appinfo/routes.php declares.
 *
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'),
)
const actionsJs = fs.readFileSync(path.join(ROOT, 'src/manifestActions.js'), 'utf8')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')
const request = fs.readFileSync(
	path.join(ROOT, 'lib/Request/InvoiceGenerationRequest.php'),
	'utf8',
)

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue/functions/dialog', () => ({
	spawnDialog: vi.fn(() => Promise.resolve(null)),
}))
vi.mock('../../src/modals/InvoiceGenerateWizard.vue', () => ({
	default: { name: 'InvoiceGenerateWizard' },
}))
vi.mock('../../src/utils/usageBilling.js', () => ({
	loadBillableReadings: vi.fn(() => Promise.resolve([{ id: 'r-1' }])),
}))

const axiosMock = { get: vi.fn(), post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

describe('Generate invoices', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	it('is a header action on Sales invoices that opens the dialog', async () => {
		const ar = manifest.pages.find((p) => p.id === 'AccountsReceivable')
		const action = ar.config.headerActions.find(
			(a) => a.id === 'generate-invoices',
		)
		expect(action.handler).toBe('openInvoiceGenerateWizard')
		expect(action.route).toBeUndefined()
		expect(actionsJs).toContain('openInvoiceGenerateWizard,')

		const { openInvoiceGenerateWizard } =
			await import('../../src/utils/invoiceGenerateWizard.js')
		const { spawnDialog } = await import('@nextcloud/vue/functions/dialog')
		await openInvoiceGenerateWizard()
		expect(spawnDialog).toHaveBeenCalledWith(
			expect.objectContaining({ name: 'InvoiceGenerateWizard' }),
			{},
		)
	})

	it('keeps the Generate invoice page', () => {
		expect(
			manifest.pages.some((p) => p.id === 'BillableInvoiceGenerate')
				|| fs
					.readFileSync(
						path.join(
							ROOT,
							'src/manifest.d/invoice-from-time-and-expense.json',
						),
						'utf8',
					)
					.includes('"BillableInvoiceGenerate"'),
		).toBe(true)
	})

	it('offers the project hours of the period, oldest first', async () => {
		const { timeEntriesFor } =
			await import('../../src/utils/invoiceGenerateWizard.js')
		const entries = [
			{ id: 'b', projectId: 'p1', date: '2026-09-20' },
			{ id: 'a', projectId: 'p1', date: '2026-09-02' },
			{ id: 'x', projectId: 'p2', date: '2026-09-05' },
			{ id: 'y', projectId: 'p1', date: '2026-10-01' },
		]
		expect(
			timeEntriesFor(entries, 'p1', '2026-09-01', '2026-09-30').map(
				(e) => e.id,
			),
		).toEqual(['a', 'b'])
		expect(
			timeEntriesFor(entries, '', '2026-09-01', '2026-09-30').map((e) => e.id),
		).toEqual(['a', 'x', 'b'])
	})

	it('offers only the claims passed on to the customer', async () => {
		const { passThroughClaimsFor } =
			await import('../../src/utils/invoiceGenerateWizard.js')
		const claims = [
			{
				id: 'c1',
				passThroughCustomerIds: ['cust-1'],
				submittedDate: '2026-09-14',
			},
			{
				id: 'c2',
				passThroughCustomerIds: ['cust-2'],
				submittedDate: '2026-09-14',
			},
			{ id: 'c3', submittedDate: '2026-09-14' },
			{
				id: 'c4',
				passThroughCustomerIds: ['cust-1'],
				submittedDate: '2026-08-01',
			},
		]
		expect(
			passThroughClaimsFor(claims, 'cust-1', '2026-09-01', '2026-09-30').map(
				(c) => c.id,
			),
		).toEqual(['c1'])
	})

	it('loads meter readings for the usage model only', async () => {
		const { loadCandidates } =
			await import('../../src/utils/invoiceGenerateWizard.js')
		axiosMock.get.mockResolvedValue({ data: { results: [] } })
		const usage = await loadCandidates({
			customerId: 'c',
			fromDate: '2026-09-01',
			toDate: '2026-09-30',
			billingModel: 'usage',
		})
		expect(usage.readings).toEqual([{ id: 'r-1' }])
		const hours = await loadCandidates({
			customerId: 'c',
			fromDate: '2026-09-01',
			toDate: '2026-09-30',
			billingModel: 't_and_m',
			projectId: 'p1',
		})
		expect(hours.readings).toEqual([])
		expect(axiosMock.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/shillinq/UrenRegistratie',
			{ params: { _limit: 500, projectId: 'p1' } },
		)
	})

	it('sends the fields InvoiceGenerationRequest reads', async () => {
		const { buildGenerateRequest, chosenCount } =
			await import('../../src/utils/invoiceGenerateWizard.js')
		const body = buildGenerateRequest(
			{
				billingModel: 'mixed',
				customerId: 'cust-1',
				projectId: 'p1',
				fromDate: '2026-09-01',
				toDate: '2026-09-30',
				rateCardId: 'rc-1',
				retainerScheduleId: 'rs-1',
				fixedFee: '1250,50',
			},
			{
				timeEntryIds: ['t1', 't2'],
				expenseIds: ['e1'],
				meterReadingIds: ['m1'],
			},
		)
		expect(body).toEqual({
			billingModel: 'mixed',
			customerId: 'cust-1',
			projectId: 'p1',
			fromDate: '2026-09-01',
			toDate: '2026-09-30',
			rateCardId: 'rc-1',
			retainerScheduleId: 'rs-1',
			fixedFeeCents: 125050,
			timeEntryIds: ['t1', 't2'],
			expenseIds: ['e1'],
			meterReadingIds: [],
		})
		for (const key of Object.keys(body)) {
			expect(request).toContain(`'${key}'`)
		}
		expect(chosenCount({ timeEntryIds: ['t1', 't2'], expenseIds: ['e1'] })).toBe(
			3,
		)
	})

	it('drafts and posts through declared routes', async () => {
		const { draftInvoice, postInvoice } =
			await import('../../src/utils/invoiceGenerateWizard.js')
		axiosMock.post.mockResolvedValue({ data: { id: 'inv-1' } })
		await draftInvoice({ billingModel: 't_and_m' })
		await postInvoice('inv-1')
		expect(axiosMock.post).toHaveBeenNthCalledWith(
			1,
			'/index.php/apps/shillinq/api/v1/invoices/generate',
			{ billingModel: 't_and_m' },
		)
		expect(axiosMock.post).toHaveBeenNthCalledWith(
			2,
			'/index.php/apps/shillinq/api/v1/invoices/inv-1/post',
		)
		expect(routes).toContain("'url' => '/api/v1/invoices/generate'")
		expect(routes).toContain("'url' => '/api/v1/invoices/{invoiceId}/post'")
	})

	it('shows the server message when there is one', async () => {
		const { errorText } =
			await import('../../src/utils/invoiceGenerateWizard.js')
		expect(
			errorText({ response: { data: { message: 'Already invoiced.' } } }, 'x'),
		).toBe('Already invoiced.')
		expect(errorText(new Error('boom'), 'Failed')).toBe('Failed')
	})
})
