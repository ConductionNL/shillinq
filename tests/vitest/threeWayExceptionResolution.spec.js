/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The three-way match exception panel: the side-by-side comparison rows and
 * the resolution call (its guard, request, the two response shapes and the
 * error message).
 *
 * @spec openspec/changes/three-way-match-delivery-date-exception/specs/bookkeeping-three-way-matching-engine/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ThreeWayMatchExceptionPanel from '../../src/components/three-way-match/ThreeWayMatchExceptionPanel.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

/**
 * A fake component instance with every method bound to it.
 *
 * @param {object} state The data the panel holds.
 * @return {object} The instance.
 */
function panel(state) {
	const vm = { t: (app, text) => text, ...state }
	for (const [name, method] of Object.entries(ThreeWayMatchExceptionPanel.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

const day = (iso) => new Date(iso).toLocaleDateString()

describe('three-way match exception comparison', () => {
	it('lays out reference, date, amount and VAT for the three documents', () => {
		const vm = panel({
			po: { poNumber: 'PO-1', expectedDeliveryDate: '2025-03-01', totalInclVat: 12100, totalVat: 2100, currency: 'USD' },
			grn: { grnNumber: 'GRN-1', receivedAt: null },
			invoice: { invoiceNumber: 'INV-1', invoiceDate: '2025-03-04', totalInclVat: 12221, totalVat: null },
		})

		expect(ThreeWayMatchExceptionPanel.computed.comparisonRows.call(vm)).toEqual([
			{ field: 'reference', label: 'Reference', po: 'PO-1', grn: 'GRN-1', invoice: 'INV-1' },
			{ field: 'date', label: 'Date', po: day('2025-03-01'), grn: vm.formatTimestamp(null), invoice: day('2025-03-04') },
			{ field: 'amount', label: 'Amount (incl. VAT)', po: 'USD 121.00', grn: '—', invoice: 'USD 122.21' },
			{ field: 'vat', label: 'VAT', po: 'USD 21.00', grn: '—', invoice: '—' },
		])
	})

	it('falls back to dashes when the documents are missing', () => {
		const vm = panel({ po: null, grn: undefined, invoice: null })

		expect(ThreeWayMatchExceptionPanel.computed.comparisonRows.call(vm)).toEqual([
			{ field: 'reference', label: 'Reference', po: '—', grn: '—', invoice: '—' },
			{ field: 'date', label: 'Date', po: '—', grn: vm.formatTimestamp(undefined), invoice: '—' },
			{ field: 'amount', label: 'Amount (incl. VAT)', po: '—', grn: '—', invoice: '—' },
			{ field: 'vat', label: 'VAT', po: '—', grn: '—', invoice: '—' },
		])
	})
})

describe('three-way match exception resolution', () => {
	beforeEach(() => {
		axios.post.mockReset()
	})

	it('refuses empty notes without calling the server', async () => {
		const vm = panel({ notes: '   ', submitting: false, actionError: 'old', match: { id: 1 } })

		await vm.submitResolution('/api/x', { a: 1 })

		expect(axios.post).not.toHaveBeenCalled()
		expect(vm.actionError).toBe('A motivation / reason is required.')
		expect(vm.submitting).toBe(false)
		expect(vm.match).toEqual({ id: 1 })
	})

	it('posts the resolution and takes a match returned directly', async () => {
		const vm = panel({ notes: ' because ', administrationId: 'adm-1', id: 'm-1', submitting: false, actionError: 'old', dispatch: 'kept' })
		axios.post.mockImplementation(async () => {
			expect(vm.submitting).toBe(true)
			expect(vm.actionError).toBe('')
			return { data: { id: 'm-1', resolutionAction: 'accept' } }
		})

		await vm.submitResolution('/api/three-way-match/exceptions/accept', { motivation: 'because' })

		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/three-way-match/exceptions/accept',
			{ administrationId: 'adm-1', matchId: 'm-1', motivation: 'because' },
		)
		expect(vm.match).toEqual({ id: 'm-1', resolutionAction: 'accept' })
		expect(vm.dispatch).toBe('kept')
		expect(vm.notes).toBe('')
		expect(vm.submitting).toBe(false)
	})

	it('takes the match and dispatch of a dispute, and a missing dispatch as null', async () => {
		const vm = panel({ notes: 'x', submitting: false, actionError: '' })
		axios.post.mockResolvedValueOnce({ data: { match: { id: 2 }, dispatch: { id: 'd' } } })
		await vm.submitResolution('/api/d', {})
		expect(vm.match).toEqual({ id: 2 })
		expect(vm.dispatch).toEqual({ id: 'd' })

		vm.notes = 'x'
		axios.post.mockResolvedValueOnce({ data: { match: { id: 3 } } })
		await vm.submitResolution('/api/d', {})
		expect(vm.match).toEqual({ id: 3 })
		expect(vm.dispatch).toBeNull()

		vm.notes = 'x'
		axios.post.mockResolvedValueOnce({ data: null })
		await vm.submitResolution('/api/d', {})
		expect(vm.match).toBeNull()
		expect(vm.notes).toBe('')
	})

	it('shows the server error, or a generic one, and keeps the notes', async () => {
		const vm = panel({ notes: 'keep', submitting: false, actionError: '', match: { id: 1 } })
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Locked period' } } })
		await vm.submitResolution('/api/r', {})
		expect(vm.actionError).toBe('Locked period')
		expect(vm.notes).toBe('keep')
		expect(vm.submitting).toBe(false)
		expect(vm.match).toEqual({ id: 1 })

		axios.post.mockRejectedValueOnce(new Error('network'))
		await vm.submitResolution('/api/r', {})
		expect(vm.actionError).toBe('Resolution failed')
	})
})
