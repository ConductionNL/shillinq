/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for RelationLinkDialog.vue's load(): the three reads
 * and what they leave — the confirmed link, this customer's suggestions and
 * the payee options — and the error text. Methods run bound to a fake `this`
 * seeded from the real `data()`.
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RelationLinkDialog from '../../src/modals/RelationLinkDialog.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcDialog: {}, NcNoteCard: {}, NcSelect: {} }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url, params = {}) => '/index.php' + url.replace('{customerId}', params.customerId) }))

/**
 * A fake dialog for customer `c-1` with its methods bound.
 *
 * @return {object}
 */
function dialog() {
	const self = { ...RelationLinkDialog.data.call({}), customerId: 'c-1' }
	for (const [name, method] of Object.entries(RelationLinkDialog.methods)) {
		self[name] = method.bind(self)
	}
	return self
}

/**
 * Answer the three reads by URL.
 *
 * @param {object} answers Response bodies for `both-sides`, `suggestions` and `Payee`.
 */
function answer(answers) {
	axios.get.mockImplementation(async (url) => {
		const key = Object.keys(answers).find((part) => url.includes(part))
		return { data: answers[key] }
	})
}

describe('RelationLinkDialog load', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('shows the link, this customer\'s suggestions and the payee options', async () => {
		answer({
			'both-sides': { link: { matchedOn: 'kvk', confirmedBy: 'anna' }, payee: { id: 'p-1', name: 'Zuid B.V.' } },
			suggestions: { suggestions: [{ customerId: 'c-1', payeeId: 'p-2' }, { customerId: 'c-9', payeeId: 'p-3' }] },
			Payee: { results: [{ id: 'p-1', name: 'Zuid B.V.' }, { '@self': { id: 'p-4' }, tradingName: 'Oost' }] },
		})
		const vm = dialog()

		await vm.load()

		expect(axios.get.mock.calls.map((call) => call[0])).toEqual([
			'/index.php/apps/shillinq/api/relations/c-1/both-sides',
			'/index.php/apps/shillinq/api/relations/suggestions',
			'/index.php/apps/openregister/api/objects/shillinq/Payee',
		])
		expect(axios.get.mock.calls[2][1]).toEqual({ params: { _limit: 500 } })
		expect([vm.linked, vm.suggestions, vm.payees, vm.error]).toEqual([
			{ payeeName: 'Zuid B.V.', matchedOn: 'kvk', confirmedBy: 'anna' },
			[{ customerId: 'c-1', payeeId: 'p-2' }],
			[{ id: 'p-1', name: 'Zuid B.V.' }, { id: 'p-4', name: 'Oost' }],
			'',
		])
	})

	it('has no link without a linked payee, and tolerates empty answers', async () => {
		answer({ 'both-sides': { link: { matchedOn: 'kvk' }, payee: { name: 'X' } }, suggestions: null, Payee: {} })
		const vm = dialog()

		await vm.load()

		expect([vm.linked, vm.suggestions, vm.payees]).toEqual([null, [], []])
	})

	it('words a failed load', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const vm = dialog()

		await vm.load()

		expect(vm.error).toBe('The supplier records could not be loaded.')
	})
})
