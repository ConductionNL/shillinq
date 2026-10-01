/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * sales-usage-billing: reading the import file, the Rate bulk action, the
 * reading list of the invoice generator, and the request the generator
 * sends when usage is chosen.
 *
 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue/functions/dialog', () => ({
	spawnDialog: vi.fn(() => Promise.resolve(null)),
}))
vi.mock('../../src/modals/MeterReadingImportModal.vue', () => ({
	default: { name: 'MeterReadingImportModal' },
}))

const ROOT = path.resolve(__dirname, '../..')

const { billableReadings, parseReadingsCsv, rateReadings } =
	await import('../../src/utils/usageBilling.js')
const { rateMeterReadings } = await import('../../src/utils/usageBillingActions.js')
const InvoiceGenerator = (
	await import('../../src/components/invoice/InvoiceGenerator.vue')
).default

describe('the import file', () => {
	it('reads one row per line under the header, with ; or , and quotes', () => {
		const rows = parseReadingsCsv(
			'\uFEFFcustomerId;resourceType;quantity;periodStart;periodEnd;colour\n'
				+ 'cust-hosting-noord;storage_gb;250;2026-09-01;2026-09-30;red\n'
				+ '\n'
				+ '"Hosting; Noord";storage_gb;-5;2026-09-01;2026-09-30;blue\r\n',
		)

		expect(rows).toEqual([
			{
				customerId: 'cust-hosting-noord',
				resourceType: 'storage_gb',
				quantity: '250',
				periodStart: '2026-09-01',
				periodEnd: '2026-09-30',
			},
			{
				customerId: 'Hosting; Noord',
				resourceType: 'storage_gb',
				quantity: '-5',
				periodStart: '2026-09-01',
				periodEnd: '2026-09-30',
			},
		])
		expect(parseReadingsCsv('customerId,quantity\n')).toEqual([])
	})
})

describe('the Rate bulk action', () => {
	beforeEach(() => {
		axiosMock.post.mockReset()
	})

	it('runs the rate transition per reading and counts the refusals', async () => {
		axiosMock.post
			.mockResolvedValueOnce({ data: {} })
			.mockRejectedValueOnce(new Error('no plan'))

		const outcome = await rateReadings(['r-1', 'r-2'])

		expect(outcome).toEqual({ rated: 1, failed: 1 })
		expect(axiosMock.post).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/r-1/transition',
			{ action: 'rate', data: {} },
		)
	})

	it('does nothing without a selection', async () => {
		expect(await rateMeterReadings({ selectedIds: [] })).toBeUndefined()
		expect(axiosMock.post).not.toHaveBeenCalled()
	})

	it('is a declared handler on the readings page and wired in main.js', () => {
		const fragment = JSON.parse(
			fs.readFileSync(
				path.join(ROOT, 'src/manifest.d/sales-usage-billing.json'),
				'utf8',
			),
		)
		const page = fragment.pages.find((p) => p.id === 'MeterReadings')
		const mainJs = fs.readFileSync(path.join(ROOT, 'src/main.js'), 'utf8')
		for (const action of [
			...page.config.bulkActions,
			...page.config.headerActions,
		]) {
			expect(mainJs).toMatch(new RegExp('\\b' + action.handler + ','))
		}
	})
})

describe('the invoice generator, usage model', () => {
	const readings = [
		{ id: 'r-oct', status: 'rated', customerId: 'c-1', periodEnd: '2026-10-31' },
		{
			id: 'r-b',
			status: 'rated',
			customerId: 'c-1',
			periodEnd: '2026-09-30',
			ratedAmount: 12,
		},
		{
			id: 'r-a',
			status: 'rated',
			customerId: 'c-1',
			periodEnd: '2026-09-15',
			ratedAmount: 30,
		},
		{
			id: 'r-billed',
			status: 'invoiced',
			customerId: 'c-1',
			periodEnd: '2026-09-30',
			invoiceId: 'i-1',
		},
		{
			id: 'r-unrated',
			status: 'unrated',
			customerId: 'c-1',
			periodEnd: '2026-09-30',
		},
		{
			id: 'r-other',
			status: 'rated',
			customerId: 'c-2',
			periodEnd: '2026-09-30',
		},
	]

	it("lists the customer's rated readings in the period that are not invoiced", () => {
		expect(
			billableReadings(readings, 'c-1', '2026-09-01', '2026-09-30').map(
				(r) => r.id,
			),
		).toEqual(['r-a', 'r-b'])
	})

	it('offers usage and sends the selected reading ids', async () => {
		const template = fs.readFileSync(
			path.join(ROOT, 'src/components/invoice/InvoiceGenerator.vue'),
			'utf8',
		)
		expect(template).toContain('<option value="usage">')

		axiosMock.get.mockResolvedValueOnce({ data: { results: readings } })
		const vm = {
			...InvoiceGenerator.data(),
			loadReadings: InvoiceGenerator.methods.loadReadings,
		}
		vm.form = {
			...vm.form,
			billingModel: 'usage',
			customerId: 'c-1',
			fromDate: '2026-09-01',
			toDate: '2026-09-30',
		}
		Object.defineProperty(vm, 'needsUsage', {
			get: () => InvoiceGenerator.computed.needsUsage.call(vm),
		})

		await vm.loadReadings()

		expect(axiosMock.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/shillinq/MeterReading',
			{ params: { customerId: 'c-1', status: 'rated', _limit: 500 } },
		)
		expect(vm.meterReadingIds).toEqual(['r-a', 'r-b'])

		vm.meterReadingIds = ['r-b']
		const payload = InvoiceGenerator.computed.payload.call({
			...vm,
			needsUsage: true,
			parseIds: InvoiceGenerator.methods.parseIds,
		})
		expect(payload.billingModel).toBe('usage')
		expect(payload.meterReadingIds).toEqual(['r-b'])
	})

	it('sends no reading ids for another model', () => {
		const vm = {
			...InvoiceGenerator.data(),
			parseIds: InvoiceGenerator.methods.parseIds,
			needsUsage: false,
		}
		vm.meterReadingIds = ['r-a']
		expect(InvoiceGenerator.computed.payload.call(vm).meterReadingIds).toEqual(
			[],
		)
	})
})
