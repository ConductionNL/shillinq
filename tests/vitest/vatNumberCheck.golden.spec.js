/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the Check VAT number action: for each scope, location
 * and server answer, the request made, the toasts shown, the refresh event
 * and the value returned.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/changes/tax-vat-number-check/tasks.md#task-2.1
 */

import { beforeEach, describe, it, vi } from 'vitest'
import { asyncOutcome, golden } from './goldenHarness.js'

const calls = []
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) =>
		url.replace(/\{(\w+)\}/g, (m, k) => params[k]),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (m, k) => vars[k]),
}))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => calls.push(['showError', text]),
	showSuccess: (text) => calls.push(['showSuccess', text]),
	showWarning: (text) => calls.push(['showWarning', text]),
}))
vi.mock('@nextcloud/event-bus', () => ({
	emit: (name, data) => calls.push(['emit', name, data]),
}))
const answer = { next: null }
vi.mock('@nextcloud/axios', () => ({
	default: {
		post: (url) => {
			calls.push(['post', url])
			return answer.next()
		},
	},
}))

const check = golden('vatNumberCheck')

const SCOPES = [
	{ item: { id: 'c-1' } },
	{ item: { id: '' } },
	{ item: null },
	{},
	null,
	undefined,
	{ item: { id: 0 } },
]
const PATHS = ['/apps/shillinq/customers/from-path', '/apps/shillinq/customers/']
const ANSWERS = {
	valid: () =>
		Promise.resolve({
			data: { status: 'valid', vatId: 'NL1', lastValidAt: null },
		}),
	invalid: () => Promise.resolve({ data: { status: 'invalid', vatId: 'NL2' } }),
	unreachableKnown: () =>
		Promise.resolve({
			data: { status: 'unreachable', vatId: 'NL3', lastValidAt: 'not a date' },
		}),
	unreachableNew: () =>
		Promise.resolve({
			data: { status: 'unreachable', vatId: 'NL4', lastValidAt: null },
		}),
	refusedWithReason: () =>
		Promise.reject({ response: { data: { error: 'Not your administration' } } }),
	refusedEmptyReason: () => Promise.reject({ response: { data: { error: '' } } }),
	networkError: () => Promise.reject(new Error('offline')),
	nullError: () => Promise.reject(null),
	noData: () => Promise.resolve({}),
}

describe('vat number check golden', () => {
	beforeEach(() => {
		calls.length = 0
	})

	it('matches the record', async () => {
		const { checkCustomerVatNumber, checkSupplierVatNumber } =
			await import('../../src/utils/vatNumberCheck.js')
		for (const [p, path] of PATHS.entries()) {
			globalThis.window = { location: { pathname: path } }
			for (const [s, scope] of SCOPES.entries()) {
				for (const [name, next] of Object.entries(ANSWERS)) {
					for (const [kind, action] of [
						['customer', checkCustomerVatNumber],
						['supplier', checkSupplierVatNumber],
					]) {
						calls.length = 0
						answer.next = next
						const result = await asyncOutcome(() => action(scope))
						check(`${p} ${s} ${name} ${kind}`, {
							result,
							calls: [...calls],
						})
					}
				}
			}
		}
	})
})
