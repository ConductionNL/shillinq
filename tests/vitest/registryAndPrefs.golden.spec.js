/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the shared integration registry stub and the quick-draft
 * preference loader: the globals after the call, what the loader returns and
 * what it removes from storage, over generated globals and stored values.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/changes/shillinq-invoice-quick-draft/proposal.md
 */

import { afterEach, describe, it, vi } from 'vitest'
import { sharedRegistry } from '../../src/integrations/leafMount.js'
import { loadQuickDraftPrefs } from '../../src/modals/invoiceQuickDraft.js'
import { golden, outcome, seeded } from './goldenHarness.js'

const check = golden('registryAndPrefs')
const NOW = 1790000000000
const DAY = 86400000

afterEach(() => {
	vi.restoreAllMocks()
	delete globalThis.localStorage
})

describe('registry and prefs golden', () => {
	it('sharedRegistry matches the record', () => {
		const existing = { _queue: ['x'], register() {} }
		const globals = [
			{},
			{ OCA: {} },
			{ OCA: { OpenRegister: {} } },
			{ OCA: { OpenRegister: { integrations: null } } },
			{ OCA: { OpenRegister: { integrations: existing } } },
			{ OCA: { OpenRegister: { integrations: 0 } } },
			{ OCA: { Other: 1 }, keep: true },
			{ OCA: null },
		]
		globals.forEach((g, i) => {
			const result = outcome(() => sharedRegistry(g))
			const registry = g.OCA?.OpenRegister?.integrations
			let queued = null
			if (registry && typeof registry.register === 'function') {
				registry.register({ id: 'probe' })
				queued = registry._queue
			}
			check(`registry ${i}`, {
				result,
				global: g,
				same: result.value !== undefined && registry === existing,
				queued,
			})
		})
		check(
			'registry no global',
			outcome(() => sharedRegistry(null)),
		)
	})

	it('loadQuickDraftPrefs matches the record', () => {
		const r = seeded(21)
		const stored = [
			null,
			'',
			'not json',
			'0',
			'true',
			'"text"',
			'[]',
			JSON.stringify({ glAccount: '8000' }),
			JSON.stringify({ glAccount: '8000', savedAt: NOW - DAY }),
			JSON.stringify({ glAccount: '8000', savedAt: NOW - 91 * DAY }),
			JSON.stringify({ glAccount: '8000', savedAt: NOW - 90 * DAY }),
			JSON.stringify({ savedAt: 0 }),
			JSON.stringify({ savedAt: 'x' }),
		]
		for (let i = 0; i < 500; i++) {
			const value = r.pick(stored)
			const customerId = r.pick(['c1', '', null, undefined, 7])
			const storage = r.next() < 0.1 ? undefined : r.pick(['ok', 'throws'])
			const log = []
			if (storage) {
				globalThis.localStorage = {
					getItem: (key) => {
						log.push(['get', key])
						if (storage === 'throws') throw new Error('denied')
						return value
					},
					removeItem: (key) => log.push(['remove', key]),
				}
			}
			vi.spyOn(Date, 'now').mockReturnValue(NOW)
			check(`prefs ${i}`, {
				result: outcome(() => loadQuickDraftPrefs(customerId)),
				log,
			})
			delete globalThis.localStorage
			vi.restoreAllMocks()
		}
	})
})
