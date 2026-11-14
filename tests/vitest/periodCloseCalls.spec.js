/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * PeriodCloseDetail's server calls: loading the period, the close and lock
 * transitions, and reopening with a reason, with their guards and errors.
 *
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-9
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import PeriodCloseDetail from '../../src/components/period-close/PeriodCloseDetail.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {} }))
vi.mock('../../src/modals/ReopenPeriodDialog.vue', () => ({ default: {} }))

const URL = '/index.php/apps/shillinq/api/period-close/p%2F1'

/**
 * A fake instance with the detail's data and bound methods.
 *
 * @param {object} state Overrides, including recordId.
 * @return {object} The instance.
 */
function detail(state = {}) {
	const vm = { ...PeriodCloseDetail.data.call({}), recordId: 'p/1', ...state }
	for (const [name, method] of Object.entries(PeriodCloseDetail.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

describe('PeriodCloseDetail calls', () => {
	beforeEach(() => {
		globalThis.t = (app, text) => text
		axios.get.mockReset()
		axios.post.mockReset()
	})

	afterEach(() => {
		delete globalThis.t
	})

	it('refuses to load without an id', async () => {
		const vm = detail({ recordId: '' })
		await vm.load()
		expect(axios.get).not.toHaveBeenCalled()
		expect(vm.loading).toBe(false)
		expect(vm.loadError).toBe('No period id supplied.')
	})

	it('loads the period, or reports it missing', async () => {
		const vm = detail({ loadError: 'old', loading: false })
		axios.get.mockImplementationOnce(async () => {
			expect(vm.loading).toBe(true)
			expect(vm.loadError).toBe('')
			return { data: { data: { id: 'p/1', state: 'open' } } }
		})
		await vm.load()
		expect(axios.get).toHaveBeenCalledWith(URL)
		expect(vm.period).toEqual({ id: 'p/1', state: 'open' })
		expect(vm.loading).toBe(false)

		axios.get.mockResolvedValueOnce({ data: { data: 'nope' } })
		await vm.load()
		expect(vm.period).toBeNull()
		expect(vm.loadError).toBe('Period not found.')

		axios.get.mockResolvedValueOnce(undefined)
		vm.period = { id: 'x' }
		await vm.load()
		expect(vm.period).toBeNull()
	})

	it('reports a load failure with the server message or the fallback', async () => {
		const vm = detail({ period: { id: 'kept' } })
		axios.get.mockRejectedValueOnce({ response: { status: 500, data: { message: 'Boom' } } })
		await vm.load()
		expect(vm.loadError).toBe('Boom')
		expect(vm.period).toEqual({ id: 'kept' })

		axios.get.mockRejectedValueOnce({ response: { status: 403 } })
		await vm.load()
		expect(vm.loadError).toBe('You do not have permission to perform this action.')

		axios.get.mockRejectedValueOnce(new Error('x'))
		await vm.load()
		expect(vm.loadError).toBe('Failed to load period.')
		expect(vm.loading).toBe(false)
	})

	it('posts a transition and merges the returned period', async () => {
		const vm = detail({ period: { id: 'p/1', state: 'open', name: 'Q1' }, transitionError: 'e', transitionNotice: 'n' })
		axios.post.mockImplementationOnce(async () => {
			expect(vm.transitioning).toBe(true)
			expect(vm.transitionError).toBe('')
			expect(vm.transitionNotice).toBe('')
			return { data: { data: { state: 'closing' } } }
		})
		await vm.onStartClose()
		expect(axios.post).toHaveBeenCalledWith(`${URL}/start-close`, {})
		expect(vm.period).toEqual({ id: 'p/1', state: 'closing', name: 'Q1' })
		expect(vm.transitionNotice).toBe('Period close initiated.')
		expect(vm.transitioning).toBe(false)

		axios.post.mockResolvedValueOnce({ data: {} })
		axios.get.mockResolvedValueOnce({ data: { data: { id: 'p/1', state: 'closed' } } })
		await vm.transition('close', 'Done.', null)
		expect(axios.post).toHaveBeenLastCalledWith(`${URL}/close`, {})
		expect(axios.get).toHaveBeenCalledWith(URL)
		expect(vm.period).toEqual({ id: 'p/1', state: 'closed' })
		expect(vm.transitionNotice).toBe('Done.')

		await vm.transition('lock-audit', 'Locked.', { reason: 'r' })
		expect(axios.post).toHaveBeenLastCalledWith(`${URL}/lock-audit`, { reason: 'r' })
	})

	it('reports a transition failure and skips without an id', async () => {
		const vm = detail()
		axios.post.mockRejectedValueOnce({ response: { status: 403 } })
		await vm.onLockAudit()
		expect(vm.transitionError).toBe('You do not have permission to perform this action.')
		expect(vm.transitionNotice).toBe('')
		expect(vm.transitioning).toBe(false)

		axios.post.mockRejectedValueOnce(new Error('x'))
		await vm.onClose()
		expect(vm.transitionError).toBe('Transition failed.')

		const none = detail({ recordId: '', transitionError: 'kept' })
		await none.onClose()
		expect(axios.post).toHaveBeenCalledTimes(2)
		expect(none.transitionError).toBe('kept')
		expect(none.transitioning).toBe(false)
	})

	it('reopens with a trimmed reason, closes the dialog and announces it', async () => {
		const vm = detail({ period: { id: 'p/1', state: 'closed' }, reopenDialogOpen: true, reopenError: 'old' })
		axios.post.mockImplementationOnce(async () => {
			expect(vm.reopenSubmitting).toBe(true)
			expect(vm.reopenError).toBe('')
			return { data: { data: { state: 'open' } } }
		})
		await vm.onReopenConfirmed('  late invoice  ')
		expect(axios.post).toHaveBeenCalledWith(`${URL}/reopen`, { closeReason: 'late invoice' })
		expect(vm.period).toEqual({ id: 'p/1', state: 'open' })
		expect(vm.reopenDialogOpen).toBe(false)
		expect(vm.transitionNotice).toBe('Period reopened.')
		expect(vm.reopenSubmitting).toBe(false)

		axios.post.mockResolvedValueOnce({ data: { data: null } })
		axios.get.mockResolvedValueOnce({ data: { data: { id: 'p/1', state: 'open', reloaded: true } } })
		vm.reopenDialogOpen = true
		await vm.onReopenConfirmed('again')
		expect(vm.period).toEqual({ id: 'p/1', state: 'open', reloaded: true })
		expect(vm.reopenDialogOpen).toBe(false)
	})

	it('refuses an empty reason, keeps the dialog open on failure, and skips without an id', async () => {
		const vm = detail({ reopenDialogOpen: true })
		await vm.onReopenConfirmed('   ')
		await vm.onReopenConfirmed(null)
		expect(axios.post).not.toHaveBeenCalled()
		expect(vm.reopenError).toBe('Close reason is required.')
		expect(vm.reopenSubmitting).toBe(false)

		axios.post.mockRejectedValueOnce({ response: { status: 409, data: { message: 'Locked for audit' } } })
		await vm.onReopenConfirmed('why')
		expect(vm.reopenError).toBe('Locked for audit')
		expect(vm.reopenDialogOpen).toBe(true)
		expect(vm.transitionNotice).toBe('')

		axios.post.mockRejectedValueOnce(new Error('x'))
		await vm.onReopenConfirmed('why')
		expect(vm.reopenError).toBe('Reopen failed.')

		const none = detail({ recordId: '' })
		await none.onReopenConfirmed('why')
		expect(none.reopenError).toBe('')
		expect(axios.post).toHaveBeenCalledTimes(2)
	})
})
