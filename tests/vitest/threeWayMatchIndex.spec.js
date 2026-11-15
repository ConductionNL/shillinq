/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for ThreeWayMatchIndex.vue's list load (with its
 * invoice-header prefetch) and its re-evaluation. Methods run bound to a fake
 * `this` seeded from the real `data()`.
 *
 * @spec openspec/specs/bookkeeping-three-way-matching-engine/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ThreeWayMatchIndex from '../../src/components/three-way-match/ThreeWayMatchIndex.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

/**
 * A fake index with its methods bound.
 *
 * @return {object}
 */
function index() {
	const self = { ...ThreeWayMatchIndex.data.call({}), t: (app, text) => `${app}:${text}` }
	for (const [name, method] of Object.entries(ThreeWayMatchIndex.methods)) {
		self[name] = method.bind(self)
	}
	return self
}

describe('ThreeWayMatchIndex', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('loads the matches and prefetches each invoice once, null when unreadable', async () => {
		axios.get.mockImplementation(async (url) => {
			if (url.endsWith('/ThreeWayMatch')) {
				return { data: { results: [{ id: 'm1', invoiceId: 'i1' }, { id: 'm2', invoiceId: 'i1' }, { id: 'm3', invoiceId: 'i2' }, { id: 'm4' }] } }
			}
			if (url.endsWith('/i2')) {
				throw new Error('gone')
			}
			return { data: { id: 'i1', supplier: 'S' } }
		})
		const vm = index()

		await vm.loadMatches()

		expect(axios.get.mock.calls.map((call) => call[0].split('/').pop())).toEqual(['ThreeWayMatch', 'i1', 'i2'])
		expect([vm.matches.length, vm.invoices, vm.loading, vm.error]).toEqual([4, { i1: { id: 'i1', supplier: 'S' }, i2: null }, false, ''])
	})

	it('reads a bare list, ignores a non-list and words a failed load', async () => {
		axios.get.mockResolvedValueOnce({ data: [{ id: 'm1' }] })
		const vm = index()
		await vm.loadMatches()
		expect(vm.matches).toEqual([{ id: 'm1' }])

		axios.get.mockResolvedValueOnce({ data: { results: 'x' } })
		await vm.loadMatches()
		expect(vm.matches).toEqual([])

		axios.get.mockRejectedValueOnce({ response: { data: { error: 'Denied' } } })
		await vm.loadMatches()
		axios.get.mockRejectedValueOnce(new Error('x'))
		const other = index()
		await other.loadMatches()
		expect([vm.error, other.error, other.loading]).toEqual(['Denied', 'shillinq:Failed to load three-way matches', false])
	})

	it('replaces, prepends or reloads after a re-evaluation', async () => {
		const vm = index()
		vm.administrationId = ''
		vm.matches = [{ id: 'm1', invoiceId: 'i1' }, { id: 'm2', invoiceId: 'i2' }]

		axios.post.mockResolvedValueOnce({ data: { id: 'm2', status: 'auto_approved' } })
		await vm.reevaluate({ id: 'm2', invoiceId: 'i2', administrationId: 'adm-9' })
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/three-way-matches/evaluate', { administrationId: 'adm-9', invoiceId: 'i2' })
		expect(vm.matches).toEqual([{ id: 'm1', invoiceId: 'i1' }, { id: 'm2', status: 'auto_approved' }])

		axios.post.mockResolvedValueOnce({ data: { id: 'm9' } })
		await vm.reevaluate({ id: 'm-new', invoiceId: 'i3' })
		expect(vm.matches[0]).toEqual({ id: 'm9' })

		axios.post.mockResolvedValueOnce({ data: {} })
		axios.get.mockResolvedValueOnce({ data: [] })
		await vm.reevaluate({ id: 'm1', invoiceId: 'i1' })
		expect([vm.matches, vm.reevaluating]).toEqual([[], ''])
	})

	it('skips a match without invoice and words a failed re-evaluation', async () => {
		const vm = index()
		await vm.reevaluate({ id: 'm1' })
		expect(axios.post).not.toHaveBeenCalled()

		axios.post.mockRejectedValueOnce(new Error('x'))
		await vm.reevaluate({ id: 'm1', invoiceId: 'i1' })
		expect([vm.error, vm.reevaluating]).toEqual(['shillinq:Re-evaluation failed', ''])
	})
})
