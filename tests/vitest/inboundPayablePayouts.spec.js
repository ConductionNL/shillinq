/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * inbound-payable-from-another-app T2.1: the Payouts to pay page, its three
 * row actions and the calls they make. The fragment, the handler map, the
 * helpers' URLs and appinfo/routes.php are held to each other, and the body
 * each step sends is the one InboundPayableController reads.
 *
 * @spec openspec/changes/inbound-payable-from-another-app/tasks.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src/manifest.d/inbound-payable-from-another-app.json'), 'utf8'),
)
const menuLayout = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.json'), 'utf8'))
const actionsJs = fs.readFileSync(path.join(ROOT, 'src/manifestActions.js'), 'utf8')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => '/index.php' + url.replace(/\{(\w+)\}/g, (_, key) => params[key]),
}))
vi.mock('@nextcloud/vue/functions/dialog', () => ({
	spawnDialog: vi.fn(() => Promise.resolve(null)),
}))
vi.mock('../../src/modals/InboundPayableStepDialog.vue', () => ({
	default: { name: 'InboundPayableStepDialog' },
}))

const axiosMock = { post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

const page = fragment.pages.find((p) => p.id === 'InboundPayables')

describe('Payouts to pay', () => {
	beforeEach(() => {
		axiosMock.post.mockReset()
	})

	it('is an index over InboundPayable, opening on the payouts to approve', () => {
		expect(page.type).toBe('index')
		expect(page.config.schema).toBe('InboundPayable')
		expect(page.config.quickFilters.find((f) => f.default)).toEqual(
			expect.objectContaining({ label: 'To approve', filter: { state: 'requested' } }),
		)
	})

	it('is a menu entry under Banking & Cashflow', () => {
		expect(fragment.menu).toEqual([expect.objectContaining({ id: 'InboundPayables', label: 'Payouts to pay', route: 'InboundPayables' })])
		expect(menuLayout.relocations.InboundPayables).toBe('BankingCashflow')
	})

	it('offers each step only in the state it applies to, through a registered handler', () => {
		const byHandler = Object.fromEntries(page.config.actions.map((a) => [a.handler, a.visibleWhen]))
		expect(byHandler).toEqual({
			approvePayable: { field: 'state', in: ['requested'] },
			markPayablePaid: { field: 'state', in: ['approved'] },
			rejectPayable: { field: 'state', in: ['requested', 'approved'] },
		})
		for (const handler of Object.keys(byHandler)) {
			expect(actionsJs).toMatch(new RegExp(`\\b${handler},`))
		}
	})

	it('posts each step to the route InboundPayableController serves', async () => {
		const { takePayableStep, PAYABLE_STEPS } = await import('../../src/utils/inboundPayableApi.js')
		for (const segment of Object.values(PAYABLE_STEPS)) {
			expect(routes).toContain(`'url' => '/api/inbound-payables/{id}/${segment}', 'verb' => 'POST'`)
		}

		axiosMock.post.mockResolvedValue({ data: { id: 'p-1', state: 'paid' } })
		const answer = await takePayableStep('p-1', 'paid', { bankReference: 'B1', bankAccount: '1100', paidOn: '' })

		expect(answer).toEqual({ id: 'p-1', state: 'paid' })
		expect(axiosMock.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/inbound-payables/p-1/paid',
			{ bankReference: 'B1', bankAccount: '1100', paidOn: '' },
		)
		await expect(takePayableStep('p-1', 'pay', {})).rejects.toThrow('unknown-step')
		await expect(takePayableStep('', 'approve', {})).rejects.toThrow('unknown-step')
	})

	it('builds each body the controller reads, refusing what it would refuse', async () => {
		const { stepBody } = await import('../../src/utils/inboundPayableApi.js')

		expect(stepBody('approve', { reason: 'ignored' })).toEqual({})
		expect(stepBody('paid', { bankReference: ' B1 ', bankAccount: ' 1100 ', paidOn: '2026-11-13' }))
			.toEqual({ bankReference: 'B1', bankAccount: '1100', paidOn: '2026-11-13' })
		expect(stepBody('reject', { reason: '  double request  ' })).toEqual({ reason: 'double request' })

		expect(() => stepBody('paid', { bankReference: 'B1' })).toThrow('missing-bank')
		expect(() => stepBody('paid', { bankReference: 'B1', bankAccount: '1100', paidOn: '13-11-2026' })).toThrow('bad-date')
		expect(() => stepBody('reject', { reason: '   ' })).toThrow('missing-reason')
		expect(() => stepBody('cancel')).toThrow('unknown-step')
	})

	it('opens the step dialog for the row, and nothing without an id', async () => {
		const { spawnDialog } = await import('@nextcloud/vue/functions/dialog')
		const { approvePayable, markPayablePaid, rejectPayable } = await import('../../src/utils/inboundPayableActions.js')
		const item = { id: 'p-7', amount: 12.5, payee: { name: 'J. Jansen' } }

		approvePayable({ item })
		markPayablePaid({ item })
		rejectPayable({ item })

		expect(spawnDialog.mock.calls.map((call) => call[1])).toEqual([
			{ payableId: 'p-7', step: 'approve', payable: item },
			{ payableId: 'p-7', step: 'paid', payable: item },
			{ payableId: 'p-7', step: 'reject', payable: item },
		])
		expect(approvePayable({ item: {} })).toBeUndefined()
		expect(spawnDialog).toHaveBeenCalledTimes(3)
	})
})
