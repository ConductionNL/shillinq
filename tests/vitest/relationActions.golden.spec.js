/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the confirm and dismiss row actions of the relation
 * suggestions: for each row scope and server answer, the request made, the
 * toasts, the refresh event and the value returned.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */

import { describe, it, vi } from 'vitest'
import { asyncOutcome, golden } from './goldenHarness.js'

const calls = []
const answer = { next: null }
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (m, k) => String(vars[k])),
}))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => calls.push(['showError', text]),
	showInfo: (text) => calls.push(['showInfo', text]),
	showSuccess: (text) => calls.push(['showSuccess', text]),
}))
vi.mock('@nextcloud/event-bus', () => ({
	emit: (name, data) => calls.push(['emit', name, data]),
}))
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: () => null }))
vi.mock('../../src/modals/RelationsExportDialog.vue', () => ({ default: {} }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		post: (url, body) => {
			calls.push(['post', url, body])
			return answer.next()
		},
	},
}))

const check = golden('relationActions')

const ITEMS = [
	{ customerId: 'c1', payeeId: 'p1', matchedOn: 'iban', customerName: 'Acme' },
	{ customerId: 'c1', payeeId: '', matchedOn: 'iban' },
	{ customerId: '', payeeId: 'p1' },
	{ customerId: 'c2', payeeId: 'p2' },
	{},
	null,
]
const SCOPES = [...ITEMS.map((item) => ({ item })), {}, null, undefined]
const ANSWERS = {
	ok: () => Promise.resolve({ data: {} }),
	refused: () =>
		Promise.reject({ response: { data: { error: 'Already linked' } } }),
	emptyReason: () => Promise.reject({ response: { data: { error: '' } } }),
	network: () => Promise.reject(new Error('offline')),
	nothing: () => Promise.reject(null),
}

describe('relation actions golden', () => {
	it('matches the record', async () => {
		const actions = await import('../../src/utils/relationActions.js')
		for (const name of [
			'confirmRelationSuggestion',
			'dismissRelationSuggestion',
		]) {
			for (const [s, scope] of SCOPES.entries()) {
				for (const [a, next] of Object.entries(ANSWERS)) {
					calls.length = 0
					answer.next = next
					const result = await asyncOutcome(() => actions[name](scope))
					check(`${name} ${s} ${a}`, { result, calls: [...calls] })
				}
			}
		}
	})
})
