/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * purchasing-approval-delegation tasks 1.2 and 2.1: the approval panel on a
 * purchase order reads OpenRegister's approval tasks for the order, lets a
 * user who may decide the pending step approve, reject or hand it to a
 * colleague with a mandate, and shows "on behalf of" on decided steps.
 *
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.2
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-2.1
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	approveStep,
	currentSteps,
	handOverError,
	handOverStep,
	mayDecide,
	rejectStep,
	stepState,
} from '../../src/utils/purchaseOrderApproval.js'

const panel = fs.readFileSync(
	path.resolve(__dirname, '../../src/components/purchase-order/PurchaseOrderApprovalPanel.vue'),
	'utf8',
)

/**
 * A task row with overrides.
 *
 * @param {object} over Overrides.
 * @return {object}
 */
function task(over) {
	return {
	uuid: 't-1',
	sequenceUuid: 'seq-2',
	sequencePosition: 1,
	created: '2026-09-29T09:00:00+00:00',
	state: 'enabled',
	isTerminal: false,
	outcome: null,
	candidateGroups: ['teamleider'],
	assignee: null,
		requester: 'jbakker',
		...over,
	}
}

/**
 * A fake HTTP client recording every call.
 *
 * @return {{calls: Array, post: Function}}
 */
function recorder() {
	const calls = []
	return {
		calls,
		post: async (url, body) => {
			calls.push([url, body])
			return { data: {} }
		},
	}
}

describe('Purchase order approval steps', () => {
	it('shows only the newest sequence, in position order', () => {
		const tasks = [
			task({ uuid: 'old', sequenceUuid: 'seq-1', created: '2026-09-20T09:00:00+00:00', state: 'terminated', isTerminal: true }),
			task({ uuid: 'b', sequencePosition: 2, candidateGroups: ['facility_manager'], state: 'available' }),
			task({ uuid: 'a', sequencePosition: 1, state: 'completed', isTerminal: true, outcome: 'approved' }),
		]
		expect(currentSteps(tasks).map((t) => t.uuid)).toEqual(['a', 'b'])
	})

	it('names each step state', () => {
		expect(stepState(task())).toBe('pending')
		expect(stepState(task({ state: 'active', assignee: 'kdewit' }))).toBe('pending')
		expect(stepState(task({ state: 'available' }))).toBe('waiting')
		expect(stepState(task({ state: 'completed', isTerminal: true, outcome: 'approved' }))).toBe('approved')
		expect(stepState(task({ state: 'completed', isTerminal: true, outcome: 'rejected' }))).toBe('rejected')
		expect(stepState(task({ state: 'terminated', isTerminal: true }))).toBe('terminated')
	})

	it('lets a pool member decide, never the requester', () => {
		const pending = task()
		expect(mayDecide(pending, 'psmit', ['t-1'])).toBe(true)
		// PO-2026-041: Jeroen Bakker is a teamleider, but he requested the order.
		expect(mayDecide(pending, 'jbakker', ['t-1'])).toBe(false)
		expect(mayDecide(pending, 'psmit', [])).toBe(false)
		expect(mayDecide(task({ state: 'available' }), 'psmit', ['t-1'])).toBe(false)
	})
})

describe('Deciding a step', () => {
	it('claims an unassigned step before approving it', async () => {
		const http = recorder()
		await approveStep(http, task(), 'kdewit')
		expect(http.calls).toEqual([
			['/apps/openregister/api/flow-tasks/t-1/claim', {}],
			['/apps/openregister/api/flow-tasks/t-1/complete', { outcome: 'approved' }],
		])
	})

	it('approves a step handed to the user without claiming it', async () => {
		const http = recorder()
		await approveStep(http, task({ state: 'active', assignee: 'kdewit' }), 'kdewit')
		expect(http.calls).toEqual([
			['/apps/openregister/api/flow-tasks/t-1/complete', { outcome: 'approved' }],
		])
	})

	it('rejects with the comment OpenRegister requires', async () => {
		const http = recorder()
		await rejectStep(http, task({ state: 'active', assignee: 'psmit' }), 'psmit', 'Niet begroot')
		expect(http.calls).toEqual([
			['/apps/openregister/api/flow-tasks/t-1/complete', { outcome: 'rejected', comment: 'Niet begroot' }],
		])
	})

	it('hands a step to a colleague with a mandate', async () => {
		const http = recorder()
		await handOverStep(http, task(), 'psmit', 'kdewit', 'Vervanging tijdens verlof 30 september tot 11 oktober')
		expect(http.calls).toEqual([
			['/apps/openregister/api/flow-tasks/t-1/claim', {}],
			['/apps/openregister/api/flow-tasks/t-1/delegate', { delegate: 'kdewit', mandate: 'Vervanging tijdens verlof 30 september tot 11 oktober' }],
		])
	})

	it('refuses a hand-over without a colleague or a mandate before calling anything', () => {
		expect(handOverError('kdewit', '  ')).toBe('mandate')
		expect(handOverError('', 'Verlof')).toBe('delegate')
		expect(handOverError('kdewit', 'Verlof')).toBe(null)
	})
})

describe('Approval panel', () => {
	it('labels its actions and the on-behalf-of line', () => {
		expect(panel).toContain("t('shillinq', 'Approve')")
		expect(panel).toContain("t('shillinq', 'Reject')")
		expect(panel).toContain("t('shillinq', 'Hand to a colleague')")
		expect(panel).toContain("'{decider} on behalf of {original}'")
		expect(panel).toContain('data-testid="po-approval-panel"')
		expect(panel).toContain('scope: \'pooled\'')
		expect(panel).toContain('scope: \'assigned\'')
	})
})
