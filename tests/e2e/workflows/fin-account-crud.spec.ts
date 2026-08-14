/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FULL CRUD-WITH-PERSISTENCE journey on a primary bookkeeping entity
 * (a chart-of-accounts ledger Account).
 *
 * Beyond shell/render: this drives the entity through its whole lifecycle and
 * asserts each step PERSISTED by reading it back from the server (not just
 * that a control was clicked):
 *   CREATE  -> read back, assert fields match
 *   READ    -> the created row appears in the schema listing
 *   UPDATE  -> change a field, read back, assert the NEW value persisted
 *   DELETE  -> remove, then assert a fresh read 404s (gone)
 *
 * The persistence layer here is the same OpenRegister object API the shillinq
 * manifest SPA writes through, so a regression in CRUD persistence (a write
 * that silently does not stick) is caught. The spec also opens the shillinq
 * SPA so the journey is anchored to a real authenticated app session.
 *
 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md
 */

import { test, expect, request as pwRequest } from '@playwright/test'
import { UNIQUE_PREFIX, OrFixtures, REGISTER_SLUG } from './_fixtures'

const APP = '/apps/shillinq'
const ADMIN_ID = `${UNIQUE_PREFIX}-adm`
const NEEDED = ['Account']

test.describe('shillinq finance — ledger Account full CRUD with persistence', () => {
	let fx: OrFixtures
	let api: import('@playwright/test').APIRequestContext

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({
			baseURL,
			storageState: 'tests/e2e/.auth/admin.json',
		})
		fx = new OrFixtures(api)
	})

	test.afterAll(async () => {
		await fx?.cleanup()
		await api?.dispose()
	})

	test('the shillinq SPA mounts in an authenticated session', async ({ page }) => {
		await page.goto(APP + '/')
		await page.waitForLoadState('domcontentloaded')
		expect(page.url()).toContain('/apps/shillinq')
	})

	test('create -> read -> update -> delete an Account, asserting persistence at each step', async () => {
		// The shillinq register and its Account schema MUST be imported; the prior
		// import blocker is fixed, so a missing schema is now a real regression.
		const missing = await fx.missingSchema(NEEDED)
		expect(
			missing,
			`shillinq register/schema not imported (missing: ${missing})`,
		).toBeNull()

		const accountNumber = `${UNIQUE_PREFIX}-4100`

		// CREATE.
		const { id } = await fx.create('Account', {
			administrationId: ADMIN_ID,
			accountNumber,
			name: 'Office supplies',
			accountType: 'expenses',
			status: 'active',
			currency: 'EUR',
		})

		// READ BACK — the created fields persisted.
		const created = await fx.get('Account', id)
		expect(created.accountNumber).toBe(accountNumber)
		expect(created.name).toBe('Office supplies')
		expect(created.accountType).toBe('expenses')

		// READ (listing) — the row appears in the schema's object list.
		const listRes = await api.get(
			`/index.php/apps/openregister/api/objects/${REGISTER_SLUG}/Account?_limit=1000`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		expect(listRes.ok()).toBeTruthy()
		const listBody = await listRes.json()
		const list: Array<Record<string, unknown>> =
			listBody.results ?? listBody ?? []
		const found = list.find(
			(o) =>
				o.accountNumber === accountNumber
				|| (o['@self'] as Record<string, unknown>)?.id === id,
		)
		expect(found, 'created Account must appear in the listing').toBeTruthy()

		// UPDATE — rename, then assert the NEW value persisted on a fresh read.
		await fx.update('Account', id, {
			administrationId: ADMIN_ID,
			accountNumber,
			name: 'Office supplies (renamed)',
			accountType: 'expenses',
			status: 'active',
			currency: 'EUR',
		})
		const updated = await fx.get('Account', id)
		expect(updated.name).toBe('Office supplies (renamed)')

		// DELETE — then a fresh read must 404 (truly gone, not soft-shadowed).
		await fx.remove('Account', id)
		const gone = await api.get(
			`/index.php/apps/openregister/api/objects/${REGISTER_SLUG}/Account/${id}`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		expect(gone.status(), 'deleted Account read must 404').toBe(404)
	})
})
