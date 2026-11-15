/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for BudgetLineCommitments.vue's loadRows(): the
 * administration-scoped request, the refusal without an administration and
 * the message of each failure. Methods run bound to a fake `this`.
 *
 * @spec openspec/specs/budget-core-schema/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BudgetLineCommitments from '../../src/views/BudgetLineCommitments.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

/**
 * A fake BudgetLineCommitments with its methods bound.
 *
 * @param {string} administrationId What the administration lookup answers.
 * @return {object}
 */
function view(administrationId) {
	const self = { ...BudgetLineCommitments.data.call({}), t: (app, text) => `${app}:${text}` }
	for (const [name, method] of Object.entries(BudgetLineCommitments.methods)) {
		self[name] = method.bind(self)
	}
	self.resolveAdministrationId = () => Promise.resolve(administrationId)
	return self
}

describe('BudgetLineCommitments loadRows', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('scopes the aggregation to the administration', async () => {
		axios.get.mockResolvedValue({ data: [] })
		const vm = view('adm-1')

		await vm.loadRows()

		expect(axios.get.mock.calls[0][1]).toEqual({ params: { 'filter[administrationId]': 'adm-1' } })
		expect(axios.get.mock.calls[0][0]).toContain('/CommitmentLine/committedVsRealisedPerBudgetLine')
		expect([vm.loading, vm.errorMessage]).toEqual([false, ''])
	})

	it('sends nothing without an administration', async () => {
		const vm = view('')

		await vm.loadRows()

		expect(axios.get).not.toHaveBeenCalled()
		expect([vm.loading, vm.errorMessage, vm.rows]).toEqual([false, 'shillinq:No active administration — cannot scope budget lines.', []])
	})

	it('words each failure', async () => {
		const messages = []
		for (const status of [404, 501, 401, 403, 500]) {
			axios.get.mockRejectedValueOnce({ response: { status } })
			const vm = view('adm-1')
			await vm.loadRows()
			messages.push(vm.errorMessage)
		}

		expect(messages).toEqual([
			'shillinq:Aggregation endpoint unavailable on this OpenRegister build.',
			'shillinq:Aggregation endpoint unavailable on this OpenRegister build.',
			'shillinq:Permission required to read budget-line data.',
			'shillinq:Permission required to read budget-line data.',
			'shillinq:Failed to load budget lines.',
		])
	})
})
