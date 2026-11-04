/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the settings store's load and save: every fetch call, the
 * loading flag while the request runs, the state, the logged errors and the
 * returned value, for answers that succeed, refuse, throw or fail to parse.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-1
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, describe, it, vi } from 'vitest'
import { useSettingsStore } from '../../src/store/modules/settings.js'
import { golden, plain } from './goldenHarness.js'

const check = golden('settingsStore')

const ANSWERS = {
	full: { ok: true, body: { openregisters: true, isAdmin: true, register: 'r' } },
	flagsOff: { ok: true, body: { openregisters: 0, isAdmin: '' } },
	nullBody: { ok: true, body: null },
	emptyBody: { ok: true, body: {} },
	refused: { ok: false, body: { error: 'no' } },
	throws: { throws: true },
	badJson: { ok: true, jsonThrows: true },
}

/**
 * Run one store action against one answer and describe what happened.
 *
 * @param {string} action fetchSettings or saveSettings.
 * @param {object} answer The answer.
 * @return {Promise<object>}
 */
async function run(action, answer) {
	setActivePinia(createPinia())
	globalThis.OC = { requestToken: 'tok-1' }
	const store = useSettingsStore()
	store.settings = { before: true }
	const calls = []
	const errors = []
	vi.spyOn(console, 'error').mockImplementation((...args) =>
		errors.push(args.map(String)),
	)
	globalThis.fetch = async (url, init) => {
		calls.push({ url, init, loading: store.loading })
		if (answer.throws) {
			throw new Error('network down')
		}
		return {
			ok: answer.ok,
			json: async () => {
				if (answer.jsonThrows) {
					throw new SyntaxError('bad json')
				}
				return answer.body
			},
		}
	}
	const result = await (action === 'saveSettings'
		? store.saveSettings({ register: 'x', n: 1 })
		: store.fetchSettings())
	return plain({
		calls,
		result,
		errors,
		state: {
			settings: store.settings,
			loading: store.loading,
			hasOpenRegisters: store.hasOpenRegisters,
			isAdmin: store.isAdmin,
		},
	})
}

describe('settings store golden', () => {
	afterEach(() => {
		vi.restoreAllMocks()
		delete globalThis.fetch
		delete globalThis.OC
	})

	it('matches the record', async () => {
		for (const action of ['fetchSettings', 'saveSettings']) {
			for (const [name, answer] of Object.entries(ANSWERS)) {
				check(`${action} ${name}`, await run(action, answer))
			}
		}
	})
})
