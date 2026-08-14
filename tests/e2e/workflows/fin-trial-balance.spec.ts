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
 *
 * The `@spec` above is KNOWINGLY DANGLING and must stay that way until
 * shillinq#500 is answered. `#task-4-1` cannot resolve (the task list is
 * written `- [x] Task 4.1:`, so the checker reads `Task` as the item id), but
 * neither canonical target is honest either: REQ-TB-001 forbids the
 * `TrialBalanceService` this test exercises, and the archived change's
 * REQ-TB-009 is change-local, not canon. A tag that resolves to a requirement
 * the code violates is worse than one that resolves to nothing.
 *
 * @e2e openspec/specs/bookkeeping-trial-balance/spec.md#balanced-trial-balance-returns-no-invariant-error
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

	test('a balanced journal posting yields a balanced trial balance with exact totals', async () => {
		// The shillinq register and its GLTransaction/GLLine/Account/
		// AdministrationMembership schemas MUST be imported; the prior import
		// blocker is fixed, so a missing schema is now a real regression.
		const missing = await fx.missingSchema(NEEDED)
		expect(
			missing,
			`shillinq register/schema not imported (missing: ${missing})`,
		).toBeNull()

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
		await fx.create('Account', {
			administrationId: ADMIN_ID,
			accountNumber: '1000',
			name: 'Cash',
			accountType: 'assets',
			status: 'active',
			currency: 'EUR',
		})
		await fx.create('Account', {
			administrationId: ADMIN_ID,
			accountNumber: '8000',
			name: 'Revenue',
			accountType: 'revenue',
			status: 'active',
			currency: 'EUR',
		})

		// The balanced journal entry.
		const { id: txId } = await fx.create('GLTransaction', {
			administrationId: ADMIN_ID,
			periodId: PERIOD_ID,
			transactionNumber: `${UNIQUE_PREFIX}-TX1`,
			postingDate: '2026-01-15',
			currency: 'EUR',
			state: 'posted',
			description: `${UNIQUE_PREFIX} balanced posting`,
		})
		await fx.create('GLLine', {
			transactionId: txId,
			periodId: PERIOD_ID,
			lineNumber: 1,
			accountNumber: '1000',
			side: 'debit',
			amount: 500.0,
			currency: 'EUR',
		})
		await fx.create('GLLine', {
			transactionId: txId,
			periodId: PERIOD_ID,
			lineNumber: 2,
			accountNumber: '8000',
			side: 'credit',
			amount: 500.0,
			currency: 'EUR',
		})

		// Compute the trial balance.
		const res = await api.get(
			`/index.php${APP}/api/trial-balance?administration_id=${ADMIN_ID}&period_id=${PERIOD_ID}`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		expect(
			res.ok(),
			`trial-balance endpoint HTTP ${res.status()}: ${await res.text()}`,
		).toBeTruthy()
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
		expect(
			missing,
			`shillinq register/schema not imported (missing: ${missing})`,
		).toBeNull()

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
			transactionNumber: `${UNIQUE_PREFIX}-TX2`,
			postingDate: '2026-01-16',
			currency: 'EUR',
			state: 'posted',
			description: `${UNIQUE_PREFIX} unbalanced posting`,
		})
		// Debit 500, credit only 300 — deliberately NOT balanced.
		await fx.create('GLLine', {
			transactionId: txId,
			periodId: period,
			lineNumber: 1,
			accountNumber: '1000',
			side: 'debit',
			amount: 500.0,
			currency: 'EUR',
		})
		await fx.create('GLLine', {
			transactionId: txId,
			periodId: period,
			lineNumber: 2,
			accountNumber: '8000',
			side: 'credit',
			amount: 300.0,
			currency: 'EUR',
		})

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
