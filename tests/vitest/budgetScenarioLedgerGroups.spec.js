/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pins how the scenario comparison reads the ledger-group names: the request,
 * the response shapes it accepts, the id and name fallbacks and the failure.
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-009
 */

import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import BudgetScenarioComparison from '../../src/views/BudgetScenarioComparison.vue'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, s) => s }))

const originalGet = axios.get

afterEach(() => {
	axios.get = originalGet
})

/**
 * Load the names with axios.get answering the given way.
 *
 * @param {Function} answer The fake axios.get.
 * @return {Promise<object>} The instance after the load.
 */
async function load(answer) {
	const ctx = { administrationId: 'adm-1', ledgerGroupNames: 'old' }
	axios.get = vi.fn(answer)
	await BudgetScenarioComparison.methods.loadLedgerGroupNames.call(ctx)
	return ctx
}

const ROWS = [
	{ id: 'g1', name: 'Personnel', code: 'P' },
	{ '@self': { id: 'g2' }, code: 'H' },
	{ id: 'g3' },
	{ name: 'no id' },
	{ '@self': {}, name: 'still no id' },
]
const NAMES = { g1: 'Personnel', g2: 'H', g3: 'g3' }

describe('BudgetScenarioComparison — loadLedgerGroupNames', () => {
	it('asks for the administration ledger groups and maps them by id', async () => {
		const ctx = await load(async () => ({ data: { results: ROWS } }))
		expect(axios.get).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/shillinq/LedgerGroup',
			{ params: { administrationId: 'adm-1', limit: 500 } },
		)
		expect(ctx.ledgerGroupNames).toEqual(NAMES)
	})

	it('accepts objects, a bare array, and nothing usable', async () => {
		expect((await load(async () => ({ data: { objects: ROWS } }))).ledgerGroupNames).toEqual(NAMES)
		expect((await load(async () => ({ data: ROWS }))).ledgerGroupNames).toEqual(NAMES)
		expect((await load(async () => ({ data: { results: null, objects: ROWS } }))).ledgerGroupNames).toEqual(NAMES)
		expect((await load(async () => ({ data: { results: 'x' } }))).ledgerGroupNames).toEqual({})
		expect((await load(async () => ({ data: null }))).ledgerGroupNames).toEqual({})
		expect((await load(async () => ({}))).ledgerGroupNames).toEqual({})
	})

	it('clears the names when the request fails', async () => {
		expect((await load(async () => { throw new Error('down') })).ledgerGroupNames).toEqual({})
		expect((await load(async () => undefined)).ledgerGroupNames).toEqual({})
	})
})
