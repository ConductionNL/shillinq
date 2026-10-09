/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * planning-commitment-year-end: the carry-over action on the commitments
 * register and Mark as last invoice on a supplier invoice reach shillinq.
 * The helpers, the manifest, main.js and appinfo/routes.php are held to each
 * other.
 *
 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/specs/bookkeeping-verplichtingenadministratie/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8')

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url) => '/index.php' + url,
}))
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: vi.fn() }))
vi.mock('../../src/modals/CarryOverCommitmentsModal.vue', () => ({
	default: {},
}))
const axiosMock = { get: vi.fn(), post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

describe('Commitment year end endpoints', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	it('call routes the app declares', async () => {
		const api = await import('../../src/utils/commitmentYearEndApi.js')
		axiosMock.get.mockResolvedValue({ data: {} })
		axiosMock.post.mockResolvedValue({ data: {} })
		await api.previewCarryOver('adm', 2026)
		await api.previewLastInvoice('adm', 'inv-1')
		await api.carryOver('adm', 2026)
		await api.markLastInvoice('adm', 'inv-1')
		const urls = [...axiosMock.get.mock.calls, ...axiosMock.post.mock.calls].map(
			(c) => c[0],
		)
		expect(urls).toEqual([
			'/index.php/apps/shillinq/api/v1/commitments/carry-over',
			'/index.php/apps/shillinq/api/v1/supplier-invoices/inv-1/last-invoice',
			'/index.php/apps/shillinq/api/v1/commitments/carry-over',
			'/index.php/apps/shillinq/api/v1/supplier-invoices/inv-1/last-invoice',
		])
		const routes = read('appinfo/routes.php')
		expect(routes).toContain(
			"'commitmentYearEnd#previewCarryOver', 'url' => '/api/v1/commitments/carry-over', 'verb' => 'GET'",
		)
		expect(routes).toContain(
			"'commitmentYearEnd#carryOver', 'url' => '/api/v1/commitments/carry-over', 'verb' => 'POST'",
		)
		expect(routes).toContain(
			"'commitmentYearEnd#previewLastInvoice', 'url' => '/api/v1/supplier-invoices/{id}/last-invoice', 'verb' => 'GET'",
		)
		expect(routes).toContain(
			"'commitmentYearEnd#markLastInvoice', 'url' => '/api/v1/supplier-invoices/{id}/last-invoice', 'verb' => 'POST'",
		)
	})

	it('shows amounts in euros', async () => {
		const { euro } = await import('../../src/utils/commitmentYearEndApi.js')
		expect(euro(1800000)).toBe('18,000.00')
		expect(euro(50)).toBe('0.50')
	})
})

describe('Where the actions sit', () => {
	it('puts the carry-over on the commitments register through a registered handler', () => {
		const fragment = JSON.parse(
			read('src/manifest.d/bookkeeping-verplichtingenadministratie.json'),
		)
		const register = fragment.pages.find((p) => p.id === 'CommitmentsRegister')
		expect(register.config.headerActions[0]).toMatchObject({
			id: 'carry-over-commitments',
			handler: 'openCarryOverCommitments',
		})
		expect(read('src/manifestActions.js')).toContain('openCarryOverCommitments,')
	})

	it('offers Mark as last invoice on an approved supplier invoice', () => {
		const detail = read(
			'src/components/supplier-invoice/SupplierInvoiceDetail.vue',
		)
		expect(detail).toContain('spawnDialog(LastInvoiceModal')
		expect(detail).toContain("invoice.statusCode === 'approved'")
		expect(detail).toContain('data-testid="si-detail-last-invoice"')
	})
})
