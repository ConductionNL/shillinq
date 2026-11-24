/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for BudgetLineCommitments.vue's loadRows(): the
 * request the server scopes to the administration, the refusal it answers
 * without one and the message of each failure. Methods run bound to a fake `this`.
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
 * @return {object}
 */
function view() {
	const self = { ...BudgetLineCommitments.data.call({}), t: (app, text) => `${app}:${text}` }
	for (const [name, method] of Object.entries(BudgetLineCommitments.methods)) {
		self[name] = method.bind(self)
	}
	return self
}

describe('BudgetLineCommitments loadRows', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('reads the aggregation the server scopes to the administration', async () => {
		axios.get.mockResolvedValue({ data: [] })
		const vm = view()

		await vm.loadRows()

		expect(axios.get.mock.calls).toEqual([['/index.php/apps/shillinq/api/v1/budget-line-commitments']])
		expect([vm.loading, vm.errorMessage]).toEqual([false, ''])
	})

	it('shows nothing when the server finds no administration', async () => {
		axios.get.mockRejectedValueOnce({ response: { status: 400 } })
		const vm = view()

		await vm.loadRows()

		expect(axios.get).toHaveBeenCalledOnce()
		expect([vm.loading, vm.errorMessage, vm.rows]).toEqual([false, 'shillinq:No active administration, so the budget lines cannot be shown.', []])
	})

	it('words each failure', async () => {
		const messages = []
		for (const status of [400, 404, 401, 403, 500]) {
			axios.get.mockRejectedValueOnce({ response: { status } })
			const vm = view()
			await vm.loadRows()
			messages.push(vm.errorMessage)
		}

		expect(messages).toEqual([
			'shillinq:No active administration, so the budget lines cannot be shown.',
			'shillinq:Permission required to read budget-line data.',
			'shillinq:Permission required to read budget-line data.',
			'shillinq:Permission required to read budget-line data.',
			'shillinq:Failed to load budget lines.',
		])
	})
})
