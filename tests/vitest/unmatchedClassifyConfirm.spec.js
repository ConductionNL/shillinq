/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for UnmatchedClassifyDialog.vue's confirm(): the
 * classification call, the success and partial-failure toasts, the refresh
 * and close, and the error text. Methods run bound to a fake `this`.
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */

import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import UnmatchedClassifyDialog from '../../src/modals/UnmatchedClassifyDialog.vue'
import { classifyUnmatchedItems } from '../../src/utils/bankMatchApi.js'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars = {}) => `${app}:` + text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key])) }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcDialog: {}, NcTextField: {} }))
vi.mock('../../src/utils/bankMatchApi.js', () => ({ classifyUnmatchedItems: vi.fn() }))

/**
 * A fake dialog for three selected items.
 *
 * @return {object}
 */
function dialog() {
	const self = { ...UnmatchedClassifyDialog.data.call({}), selectedIds: ['m1', 'm2', 'm3'], closed: [] }
	for (const [name, method] of Object.entries(UnmatchedClassifyDialog.methods)) {
		self[name] = method.bind(self)
	}
	self.$emit = (...args) => self.closed.push(args)
	self.resolutionStatus = 'timing'
	self.reason = '  next month  '
	return self
}

describe('UnmatchedClassifyDialog confirm', () => {
	beforeEach(() => {
		classifyUnmatchedItems.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
		emit.mockReset()
	})

	it('classifies, toasts the count, refreshes and closes', async () => {
		classifyUnmatchedItems.mockResolvedValue({ applied: 3, failed: 0 })
		const vm = dialog()

		await vm.confirm()

		expect(classifyUnmatchedItems).toHaveBeenCalledWith(['m1', 'm2', 'm3'], 'timing', 'next month')
		expect(showSuccess).toHaveBeenCalledWith('shillinq:3 items classified.')
		expect(emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect([vm.closed, vm.submitting, vm.error]).toEqual([[['close', { applied: 3, failed: 0 }]], false, ''])
	})

	it('toasts a partial failure, and still refreshes and closes', async () => {
		classifyUnmatchedItems.mockResolvedValue({ applied: 1, failed: 2 })
		const vm = dialog()

		await vm.confirm()

		expect(showError).toHaveBeenCalledWith('shillinq:2 of 3 items could not be classified.')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(vm.closed).toHaveLength(1)
	})

	it('shows the server error, or the generic one, and stays open', async () => {
		const errors = []
		for (const failure of [{ response: { data: { error: 'Locked period' } } }, new Error('x')]) {
			classifyUnmatchedItems.mockRejectedValueOnce(failure)
			const vm = dialog()
			await vm.confirm()
			errors.push([vm.error, vm.closed.length, vm.submitting])
		}

		expect(errors).toEqual([['Locked period', 0, false], ['shillinq:The items could not be classified.', 0, false]])
		expect(emit).not.toHaveBeenCalled()
	})
})
