/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Purchase order approval on OpenRegister's approval tasks
 * (purchasing-approval-delegation REQ-PAD-001 and REQ-PAD-002).
 *
 * The PurchaseOrder schema declares an OpenRegister approval chain on its
 * `approve` transition. Each step is an OpenRegister task anchored to the
 * order (`objectUuid`), one per sequence position. These helpers read those
 * tasks and call OpenRegister's task verbs; shillinq keeps no approval state
 * of its own. Pure functions with the HTTP client passed in, so the panel's
 * request sequence can be tested without a DOM.
 */

const TASKS = '/apps/openregister/api/flow-tasks'

/**
 * The steps of the newest approval round, in decision order.
 *
 * A rejected round stays readable in OpenRegister and a new submit opens a
 * new sequence beside it; the order page shows the newest one.
 *
 * @param {Array<object>} tasks OpenRegister task rows for the order.
 * @return {Array<object>} The newest sequence's tasks by position.
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.2
 */
export function currentSteps(tasks) {
	const rounds = new Map()
	for (const task of tasks || []) {
		if (!task || !task.sequenceUuid) {
			continue
		}
		const round = rounds.get(task.sequenceUuid) || { opened: '', tasks: [] }
		round.tasks.push(task)
		if ((task.created || '') > round.opened) {
			round.opened = task.created || ''
		}
		rounds.set(task.sequenceUuid, round)
	}

	let newest = null
	for (const round of rounds.values()) {
		if (newest === null || round.opened > newest.opened) {
			newest = round
		}
	}

	if (newest === null) {
		return []
	}

	return [...newest.tasks].sort(
		(a, b) => (a.sequencePosition || 0) - (b.sequencePosition || 0),
	)
}

/**
 * The state of one step as the panel names it.
 *
 * @param {object} task An OpenRegister task row.
 * @return {string} pending | waiting | approved | rejected | terminated.
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.2
 */
export function stepState(task) {
	if (task.isTerminal) {
		if (task.state === 'completed') {
			return ['rejected', 'returned', 'declined', 'denied'].includes(
				String(task.outcome || '').toLowerCase(),
			)
				? 'rejected'
				: 'approved'
		}
		return 'terminated'
	}

	return ['enabled', 'active'].includes(task.state) ? 'pending' : 'waiting'
}

/**
 * Whether this user may decide the step now.
 *
 * OpenRegister decides authority: a step is decidable when it shows up in the
 * user's pooled or assigned inbox. The requester is excluded here as
 * OpenRegister's separation of duties refuses them anyway, so no button is
 * offered that can only fail.
 *
 * @param {object} task An OpenRegister task row.
 * @param {string} uid The current user.
 * @param {Array<string>} decidable Uuids from the user's pooled and assigned inboxes.
 * @return {boolean}
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.2
 */
export function mayDecide(task, uid, decidable) {
	return stepState(task) === 'pending'
		&& !!uid
		&& task.requester !== uid
		&& (decidable || []).includes(task.uuid)
}

/**
 * Make the user the step's assignee when they are not yet.
 *
 * @param {object} http Client with `post(url, body)`.
 * @param {object} task The step.
 * @param {string} uid The current user.
 * @return {Promise<void>}
 */
async function claimIfNeeded(http, task, uid) {
	if (task.assignee !== uid) {
		await http.post(`${TASKS}/${task.uuid}/claim`, {})
	}
}

/**
 * Approve a step: claim it from the pool when needed, then complete it.
 *
 * @param {object} http Client with `post(url, body)`.
 * @param {object} task The step.
 * @param {string} uid The current user.
 * @return {Promise<object>} The response of the completion.
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.2
 */
export async function approveStep(http, task, uid) {
	await claimIfNeeded(http, task, uid)
	return http.post(`${TASKS}/${task.uuid}/complete`, { outcome: 'approved' })
}

/**
 * Reject a step with the comment OpenRegister requires.
 *
 * @param {object} http Client with `post(url, body)`.
 * @param {object} task The step.
 * @param {string} uid The current user.
 * @param {string} comment Why.
 * @return {Promise<object>} The response of the completion.
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.2
 */
export async function rejectStep(http, task, uid, comment) {
	await claimIfNeeded(http, task, uid)
	return http.post(`${TASKS}/${task.uuid}/complete`, {
		outcome: 'rejected',
		comment,
	})
}

/**
 * Which field of a hand-over is missing, or null when it may be sent.
 *
 * @param {string} delegate The colleague's user name.
 * @param {string} mandate The authority relied on.
 * @return {string|null} 'delegate' | 'mandate' | null.
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-2.1
 */
export function handOverError(delegate, mandate) {
	if (!String(delegate || '').trim()) {
		return 'delegate'
	}
	if (!String(mandate || '').trim()) {
		return 'mandate'
	}
	return null
}

/**
 * Hand a step to a colleague with a mandate through OpenRegister's
 * `delegate` verb, which records the original approver as on-behalf-of.
 *
 * @param {object} http Client with `post(url, body)`.
 * @param {object} task The step.
 * @param {string} uid The current user.
 * @param {string} delegate The colleague's user name.
 * @param {string} mandate The authority relied on.
 * @return {Promise<object>} The response of the delegation.
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-2.1
 */
export async function handOverStep(http, task, uid, delegate, mandate) {
	await claimIfNeeded(http, task, uid)
	return http.post(`${TASKS}/${task.uuid}/delegate`, {
		delegate: delegate.trim(),
		mandate: mandate.trim(),
	})
}
