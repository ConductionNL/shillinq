/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the list of records due for destruction
 * (ledger-retention-due-records task 3.2, REQ-RET-015).
 *
 * The live pass of the change, driven through GET /api/retention/due (the
 * route the "Due for destruction" page under Reporting & Compliance reads):
 *
 *   1. a GLTransaction booked on 15 March 2018 under rule 5.1.2 (7 years,
 *      destroy) is on the list with end date 15 March 2025 and disposition
 *      destroy;
 *   2. a GLTransaction booked on 1 February 2022 under the same rule is not;
 *   3. once a legal hold (a DocumentRetention with `legalHold: true` naming the
 *      2018 transaction) is set, the 2018 transaction leaves the list.
 *
 * The statutory 5.1.2 rule of the Selectielijst seed has disposition
 * `archive`, so the test seeds the administration's own override of 5.1.2 (7
 * years, destroy), which RetentionDueService prefers over the statutory rule.
 * The transactions are seeded as drafts: the list does not look at the state,
 * and a draft can be deleted again in afterAll where a posted, sealed entry
 * cannot. When the active administration of the suite's administrator is the
 * one the test seeds into, the page itself is opened as well and must show
 * the 2018 transaction.
 *
 * The administration defaults to the seed's `ADM-001`; `E2E_ADMINISTRATION_ID`
 * names another. Every object written here is removed afterwards.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no live instance).
 *
 * @spec openspec/changes/ledger-retention-due-records/specs/bookkeeping-archiefwet-retention/spec.md
 * @spec openspec/changes/ledger-retention-due-records/tasks.md
 *
 * @e2e bookkeeping-archiefwet-retention::a-posted-transaction-from-2018-is-due-for-destruction
 * @e2e bookkeeping-archiefwet-retention::a-recent-transaction-stays-off-the-list
 * @e2e bookkeeping-archiefwet-retention::a-record-under-a-legal-hold-stays-off-the-list
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const APP = '/index.php/apps/shillinq'
const ADMINISTRATION_ID = process.env.E2E_ADMINISTRATION_ID ?? 'ADM-001'
const RULE_CODE = '5.1.2'

/** One row of the list, as GET /api/retention/due answers it. */
interface DueRow {
	register: string
	recordId: string
	ruleCode: string
	endDate: string
	disposition: string
}

test.describe('ledger-retention-due-records: the records due for destruction', () => {
	let api: APIRequestContext
	let fx: OrFixtures

	/**
	 * The list of records due for destruction of the administration.
	 *
	 * @param administrationId The administration; '' for the caller's active one.
	 * @return The HTTP status, the administration answered and the rows.
	 */
	async function dueList(administrationId: string): Promise<{ status: number, administrationId: string, rows: DueRow[] }> {
		const query = administrationId === '' ? '' : `?administrationId=${encodeURIComponent(administrationId)}`
		const res = await api.get(`${APP}/api/retention/due${query}`, { headers: { 'OCS-APIRequest': 'true' } })
		if (res.status() !== 200) {
			return { status: res.status(), administrationId: '', rows: [] }
		}
		const body = await res.json()
		return { status: 200, administrationId: String(body.administrationId ?? ''), rows: (body.results ?? []) as DueRow[] }
	}

	/**
	 * A draft GL transaction of the administration on a posting date.
	 *
	 * @param suffix      Makes the transaction number unique within this run.
	 * @param postingDate The posting date (YYYY-MM-DD), the record's reference date.
	 * @return The transaction's id.
	 */
	async function transaction(suffix: string, postingDate: string): Promise<string> {
		const { id } = await fx.create('GLTransaction', {
			transactionNumber: `${UNIQUE_PREFIX}-RET-${suffix}`,
			postingDate,
			periodId: `${UNIQUE_PREFIX}-${postingDate.slice(0, 7)}`,
			currency: 'EUR',
			description: `${UNIQUE_PREFIX} retention ${suffix}`,
			state: 'draft',
			administrationId: ADMINISTRATION_ID,
		})
		return id
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
	})

	test.afterAll(async () => {
		await fx?.cleanup()
		await api?.dispose()
	})

	test('a 2018 transaction is due for destruction, a 2022 one is not, and a legal hold takes it off the list @spec REQ-RET-015', async ({ page }) => {
		const missing = await fx.missingSchema(['GLTransaction', 'RetentionRule', 'DocumentRetention'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		const before = await dueList(ADMINISTRATION_ID)
		test.skip(before.status === 404, `the administrator holds no membership of ${ADMINISTRATION_ID}; set E2E_ADMINISTRATION_ID`)
		expect(before.status).toBe(200)

		// The administration's own 5.1.2 rule: 7 years, destroy.
		await fx.create('RetentionRule', {
			selectionListCode: RULE_CODE,
			description: `${UNIQUE_PREFIX} daily financial records`,
			recordCategory: 'financial',
			retentionYears: 7,
			disposition: 'destroy',
			legalBasis: 'Archiefwet 1995 art. 5 + BW 2:394',
			effectiveFrom: '2018-01-01',
			administrationId: ADMINISTRATION_ID,
		})

		const old = await transaction('2018', '2018-03-15')
		const recent = await transaction('2022', '2022-02-01')

		// Scenario 1 and 2: the 2018 transaction is listed, the 2022 one is not.
		const listed = await dueList(ADMINISTRATION_ID)
		expect(listed.status).toBe(200)
		const mine = listed.rows.filter((row) => row.recordId === old)
		expect(mine, 'the 2018 transaction is listed once').toHaveLength(1)
		expect(mine[0].register).toBe('GLTransaction')
		expect(mine[0].ruleCode).toBe(RULE_CODE)
		expect(mine[0].endDate).toBe('2025-03-15')
		expect(mine[0].disposition).toBe('destroy')
		expect(listed.rows.some((row) => row.recordId === recent), 'the 2022 transaction ends in 2029').toBe(false)

		// The page shows it, when it reads the administration seeded into.
		const active = await dueList('')
		if (active.administrationId === ADMINISTRATION_ID) {
			await page.goto(`${APP}/reporting/retention-due`)
			await expect(page.getByRole('row').filter({ hasText: old }).first()).toBeVisible({ timeout: 20_000 })
		}

		// Scenario 3: a legal hold on the 2018 transaction takes it off the list.
		await fx.create('DocumentRetention', {
			documentId: old,
			policyId: `${UNIQUE_PREFIX}-policy`,
			status: 'active',
			legalHold: true,
			administrationId: ADMINISTRATION_ID,
		})
		const held = await dueList(ADMINISTRATION_ID)
		expect(held.status).toBe(200)
		expect(held.rows.some((row) => row.recordId === old), 'a held record is not due').toBe(false)
	})

	test('an ARInvoice under a legal hold stays off the list @spec REQ-RET-015', async () => {
		const missing = await fx.missingSchema(['ARInvoice', 'RetentionRule', 'DocumentRetention'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)
		const before = await dueList(ADMINISTRATION_ID)
		test.skip(before.status === 404, `the administrator holds no membership of ${ADMINISTRATION_ID}; set E2E_ADMINISTRATION_ID`)

		await fx.create('RetentionRule', {
			selectionListCode: RULE_CODE,
			description: `${UNIQUE_PREFIX} sales invoices`,
			recordCategory: 'financial',
			retentionYears: 7,
			disposition: 'destroy',
			legalBasis: 'Archiefwet 1995 art. 5 + BW 2:394',
			effectiveFrom: '2016-01-01',
			administrationId: ADMINISTRATION_ID,
		})
		// Retention ended on 1 May 2023, so the invoice is due until it is held.
		const { id: invoice } = await fx.create('ARInvoice', {
			invoiceNumber: `${UNIQUE_PREFIX}-RET-AR`,
			invoiceDate: '2016-05-01',
			dueDate: '2016-05-31',
			customerId: `${UNIQUE_PREFIX}-customer`,
			currency: 'EUR',
			lifecycleState: 'draft',
			administrationId: ADMINISTRATION_ID,
		})
		const listed = await dueList(ADMINISTRATION_ID)
		const mine = listed.rows.filter((row) => row.recordId === invoice)
		expect(mine, 'the 2016 sales invoice is listed once').toHaveLength(1)
		expect(mine[0].register).toBe('ARInvoice')
		expect(mine[0].endDate).toBe('2023-05-01')

		await fx.create('DocumentRetention', {
			documentId: invoice,
			policyId: `${UNIQUE_PREFIX}-policy-ar`,
			status: 'active',
			legalHold: true,
			administrationId: ADMINISTRATION_ID,
		})
		const held = await dueList(ADMINISTRATION_ID)
		expect(held.rows.some((row) => row.recordId === invoice), 'a held invoice is not due').toBe(false)
	})
})
