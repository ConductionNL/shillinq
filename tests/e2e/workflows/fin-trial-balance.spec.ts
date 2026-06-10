/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FINANCIAL-CORRECTNESS workflow — journal posting -> trial balance balanced
 * (debits == credits).
 *
 * DATA-DEPENDENT test: posts a KNOWN balanced journal entry (debit Cash 500,
 * credit Revenue 500) as GLTransaction + two GLLine rows in one fiscal
 * period, then computes the live trial balance (GET /api/trial-balance) and
 * asserts the fundamental double-entry invariant: total debit movement ==
 * total credit movement, isBalanced === true, and each account shows the
 * posted side.
 *
 * Known input (one period, one transaction):
 *   GLLine A: account 1000 (Cash),    side debit,  amount 500.00
 *   GLLine B: account 8000 (Revenue), side credit, amount 500.00
 *
 * Expected:
 *   totals.debit  == 500.00
 *   totals.credit == 500.00
 *   isBalanced    == true
 *   account 1000 debitMovement 500.00 ; account 8000 creditMovement 500.00
 *
 * This is the single most safety-critical bookkeeping property: an unbalanced
 * trial balance means the books do not foot. The membership/period/account
 * scaffolding is seeded so the IDOR-guarded endpoint resolves real data.
 *
 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-4-1
 */

import { test, expect, request as pwRequest } from '@playwright/test'
import { UNIQUE_PREFIX, OrFixtures, money } from './_fixtures'

const APP = '/apps/shillinq'
const ADMIN_ID = `${UNIQUE_PREFIX}-adm`
const PERIOD_ID = `${UNIQUE_PREFIX}-2026-01`
const NEEDED = ['AdministrationMembership', 'GLTransaction', 'GLLine', 'Account']

interface TbRow {
	accountNumber: string
	debitMovement: number
	creditMovement: number
}

test.describe('shillinq finance — trial balance balances (debits == credits)', () => {
	let fx: OrFixtures
	let api: import('@playwright/test').APIRequestContext

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
	})

	test.afterAll(async () => {
		await fx?.cleanup()
		await api?.dispose()
	})

	test('a balanced journal posting yields a balanced trial balance with exact totals', async () => {
		const missing = await fx.missingSchema(NEEDED)
		test.fixme(
			missing !== null,
			`BLOCKED (env): shillinq OpenRegister register/schema not imported (missing: ${missing}). ` +
				`Root cause: OpenRegister ImportHandler.php:1277 TypeError on a null schema slug while importing ` +
				`shillinq register.d fragments, so the GLTransaction/GLLine/Account/AdministrationMembership ` +
				`schemas are never created. Once the register imports, this test posts a balanced journal and ` +
				`asserts totals.debit == totals.credit == 500.00 and isBalanced === true.`,
		)

		// Membership so the admin user passes the per-administration IDOR guard.
		await fx.create('AdministrationMembership', {
			userId: 'admin',
			administrationId: ADMIN_ID,
			role: 'boekhouder',
			mayPostJournalEntries: true,
			validFrom: '2024-01-01',
			validUntil: null,
		})

		// Chart of accounts (names for the trial-balance rows).
		await fx.create('Account', { administrationId: ADMIN_ID, accountNumber: '1000', accountName: 'Cash', accountType: 'asset' })
		await fx.create('Account', { administrationId: ADMIN_ID, accountNumber: '8000', accountName: 'Revenue', accountType: 'revenue' })

		// The balanced journal entry.
		const { id: txId } = await fx.create('GLTransaction', {
			administrationId: ADMIN_ID,
			periodId: PERIOD_ID,
			description: `${UNIQUE_PREFIX} balanced posting`,
		})
		await fx.create('GLLine', {
			transactionId: txId,
			periodId: PERIOD_ID,
			accountNumber: '1000',
			side: 'debit',
			amount: 500.0,
		})
		await fx.create('GLLine', {
			transactionId: txId,
			periodId: PERIOD_ID,
			accountNumber: '8000',
			side: 'credit',
			amount: 500.0,
		})

		// Compute the trial balance.
		const res = await api.get(
			`/index.php${APP}/api/trial-balance?administration_id=${ADMIN_ID}&period_id=${PERIOD_ID}`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		expect(res.ok(), `trial-balance endpoint HTTP ${res.status()}: ${await res.text()}`).toBeTruthy()
		const body = await res.json()

		// The invariant: debits foot to credits.
		expect(money(body.totals.debit)).toBe(500.0)
		expect(money(body.totals.credit)).toBe(500.0)
		expect(money(body.totals.debit)).toBe(money(body.totals.credit))
		expect(body.isBalanced).toBe(true)

		// Each posted account shows the correct movement side.
		const rows = (body.data ?? []) as TbRow[]
		const cash = rows.find((r) => r.accountNumber === '1000')
		const revenue = rows.find((r) => r.accountNumber === '8000')
		expect(cash, 'cash account 1000 must appear').toBeTruthy()
		expect(revenue, 'revenue account 8000 must appear').toBeTruthy()
		expect(money(cash!.debitMovement)).toBe(500.0)
		expect(money(revenue!.creditMovement)).toBe(500.0)
	})

	test('an UNBALANCED journal posting is reported as not balanced', async () => {
		const missing = await fx.missingSchema(NEEDED)
		test.fixme(missing !== null, `BLOCKED (env): shillinq register not imported (missing: ${missing}).`)

		const period = `${PERIOD_ID}-unb`

		await fx.create('AdministrationMembership', {
			userId: 'admin',
			administrationId: ADMIN_ID,
			role: 'boekhouder',
			mayPostJournalEntries: true,
			validFrom: '2024-01-01',
			validUntil: null,
		})
		const { id: txId } = await fx.create('GLTransaction', {
			administrationId: ADMIN_ID,
			periodId: period,
			description: `${UNIQUE_PREFIX} unbalanced posting`,
		})
		// Debit 500, credit only 300 — deliberately NOT balanced.
		await fx.create('GLLine', { transactionId: txId, periodId: period, accountNumber: '1000', side: 'debit', amount: 500.0 })
		await fx.create('GLLine', { transactionId: txId, periodId: period, accountNumber: '8000', side: 'credit', amount: 300.0 })

		const res = await api.get(
			`/index.php${APP}/api/trial-balance?administration_id=${ADMIN_ID}&period_id=${period}`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		expect(res.ok()).toBeTruthy()
		const body = await res.json()

		expect(money(body.totals.debit)).toBe(500.0)
		expect(money(body.totals.credit)).toBe(300.0)
		expect(body.isBalanced).toBe(false)
	})
})
