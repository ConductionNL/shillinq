/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for PaymentRunReconcileModal.vue's submit(): the
 * request, the full and partial outcomes and the error text. Methods run
 * bound to a fake `this` seeded from the real `data()`.
 *
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PaymentRunReconcileModal from '../../src/modals/PaymentRunReconcileModal.vue'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `${app}:${text}` }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

/**
 * A fake modal for run `run-1` with a file loaded.
 *
 * @return {object}
 */
function modal() {
	const self = { ...PaymentRunReconcileModal.data.call({}), runId: 'run-1', fileContents: 'CAMT', emitted: [] }
	for (const [name, method] of Object.entries(PaymentRunReconcileModal.methods)) {
		self[name] = method.bind(self)
	}
	self.$emit = (...args) => self.emitted.push(args)
	return self
}

describe('PaymentRunReconcileModal submit', () => {
	beforeEach(() => {
		axios.post.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
		emit.mockReset()
	})

	it('reconciles a full match: toast, widget refresh and event', async () => {
		axios.post.mockResolvedValue({ data: { result: 'full', matched: 3 } })
		const vm = modal()

		await vm.submit()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/payment-runs/run-1/reconcile', { contents: 'CAMT' })
		expect(showSuccess).toHaveBeenCalledWith('shillinq:Payment run reconciled.')
		expect(emit).toHaveBeenCalledWith('cn:widget:refresh', { widget: 'PaymentRunDetail' })
		expect([vm.emitted, vm.result, vm.submitting]).toEqual([[['reconciled', { result: 'full', matched: 3 }]], { result: 'full', matched: 3 }, false])
	})

	it('keeps a partial match exported', async () => {
		axios.post.mockResolvedValue({ data: { result: 'partial' } })
		const vm = modal()

		await vm.submit()

		expect(showError).toHaveBeenCalledWith('shillinq:Partial match — the run stays exported.')
		expect([vm.emitted, vm.result, emit.mock.calls.length]).toEqual([[], { result: 'partial' }, 0])
	})

	it('shows the server error, or the generic one', async () => {
		for (const failure of [{ response: { data: { error: 'Unknown file' } } }, { response: { data: {} } }, new Error('x')]) {
			axios.post.mockRejectedValueOnce(failure)
			await modal().submit()
		}

		expect(showError.mock.calls).toEqual([['Unknown file'], ['shillinq:Could not reconcile the payment run.'], ['shillinq:Could not reconcile the payment run.']])
	})

	it('does nothing without a file or while submitting', async () => {
		const empty = modal()
		empty.fileContents = ''
		await empty.submit()
		const busy = modal()
		busy.submitting = true
		await busy.submit()

		expect(axios.post).not.toHaveBeenCalled()
	})
})
