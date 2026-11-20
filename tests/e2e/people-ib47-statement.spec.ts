/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the annual IB47 batch and its statement (people-ib47-statement
 * task 2.2, REQ-DPA-021).
 *
 * The live pass of the change, driven through the routes the page's
 * "Prepare last year's batch" action and the report catalogue call:
 *
 *   1. a payee marked as an IB47 recipient, with EUR 1,200 and EUR 800 paid in
 *      2026 and EUR 500 paid in 2025, is prepared into the 2026 batch with
 *      EUR 2,000, and the downloaded `ib47` statement names that amount;
 *   2. after a further EUR 300 in December, a second prepare of 2026 keeps one
 *      record for the payee, now with EUR 2,300.
 *
 * "Paid" is a payment run in `reconciled` (Ib47BatchService). The runs are
 * seeded in that state through the object API: walking a run through approve,
 * export and reconcile needs a second user for the four-eyes guard and a bank
 * statement, which belong to the payment-run suites. When the instance keeps a
 * seeded run in its initial state the test skips and says so, rather than
 * asserting against a batch with no payments in it.
 *
 * The administration defaults to the seed's `ADM-001`; `E2E_ADMINISTRATION_ID`
 * names another. The caller is the suite's administrator, whom the prepare
 * route lets through like a payroll officer. Every object written here, the
 * prepared records and the generated report included, is removed afterwards.
 *
 * @spec openspec/changes/people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md
 * @spec openspec/changes/people-ib47-statement/tasks.md
 *
 * @e2e bookkeeping-detachering-payroll-administratie::a-freelancers-payments-land-in-the-2026-batch
 * @e2e bookkeeping-detachering-payroll-administratie::a-second-prepare-writes-no-duplicate
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const APP_API = '/index.php/apps/shillinq/api'
const ADMINISTRATION_ID = process.env.E2E_ADMINISTRATION_ID ?? 'ADM-001'
const TAX_YEAR = 2026

/** A prepared entry as the prepare route answers it. */
interface PreparedEntry {
	payeeId: string
	recipientName: string
	paymentsTotal: number
	recordId: string
	created: boolean
}

test.describe('people-ib47-statement: the annual IB47 batch and statement', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let token = ''
	const recordIds = new Set<string>()
	const reportIds = new Set<string>()

	/**
	 * The CSRF token shillinq's own write routes need beside the session cookie.
	 *
	 * @return The request token of an authenticated app page.
	 */
	async function requestToken(): Promise<string> {
		if (token === '') {
			const html = await (await api.get('/index.php/apps/shillinq/')).text()
			token = html.match(/data-requesttoken="([^"]+)"/)?.[1] ?? ''
		}
		return token
	}

	/**
	 * POST a JSON body to a shillinq route as the administrator.
	 *
	 * @param path The route below the app's API.
	 * @param data The body.
	 * @return The response.
	 */
	async function post(path: string, data: Record<string, unknown>) {
		return api.post(APP_API + path, {
			headers: { requesttoken: await requestToken(), 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data,
		})
	}

	/**
	 * A reconciled payment run with one line for the payee.
	 *
	 * @param suffix   Makes the run number unique within this run.
	 * @param payeeId  The payee paid.
	 * @param amount   The line amount in EUR.
	 * @param day      The execution date (YYYY-MM-DD).
	 * @return The run's id and the state the instance stored.
	 */
	async function reconciledRun(suffix: string, payeeId: string, amount: number, day: string): Promise<{ id: string, state: string }> {
		const { id } = await fx.create('PaymentRun', {
			runNumber: `${UNIQUE_PREFIX}-IB47-${suffix}`,
			administrationId: ADMINISTRATION_ID,
			executionDate: day,
			status: 'reconciled',
			lifecycleState: 'reconciled',
			totalAmount: amount,
			currency: 'EUR',
			paymentLines: [{
				payeeId,
				creditorIban: 'NL91ABNA0417164300',
				amount,
				apTransactionRef: `${UNIQUE_PREFIX}-AP-${suffix}`,
				requestedExecutionDate: day,
			}],
		})
		const stored = await fx.get('PaymentRun', id)
		return { id, state: String(stored.lifecycleState ?? '') }
	}

	/**
	 * Prepare the batch of the tax year and return this test's payee entry.
	 *
	 * @param payeeId The payee to pick out.
	 * @return Every prepared entry for the payee (one, when the batch is idempotent).
	 */
	async function prepare(payeeId: string): Promise<PreparedEntry[]> {
		const res = await post('/ib47/batches/prepare', { taxYear: String(TAX_YEAR), administration_id: ADMINISTRATION_ID })
		test.skip(res.status() === 404, `the administrator holds no membership of ${ADMINISTRATION_ID}; set E2E_ADMINISTRATION_ID`)
		expect(res.status(), await res.text()).toBe(200)
		const batch = await res.json()
		expect(batch.taxYear).toBe(TAX_YEAR)
		const mine = (batch.prepared ?? []).filter((entry: PreparedEntry) => entry.payeeId === payeeId)
		for (const entry of mine) {
			recordIds.add(String(entry.recordId))
		}
		return mine
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
	})

	test.afterAll(async () => {
		for (const id of reportIds) {
			await fx?.remove('GeneratedReport', id)
		}
		for (const id of recordIds) {
			await fx?.remove('IB47Record', id)
		}
		await fx?.cleanup()
		await api?.dispose()
	})

	test('a freelancer\'s payments land in the 2026 batch, and a second prepare writes no duplicate @spec REQ-DPA-021', async () => {
		const missing = await fx.missingSchema(['Payee', 'PaymentRun', 'IB47Record'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		const name = `J. de Boer ${UNIQUE_PREFIX}`
		const { id: payeeId } = await fx.create('Payee', {
			vendorNumber: `${UNIQUE_PREFIX}-IB47`,
			name,
			payeeType: 'freelancer',
			paymentTermDays: 30,
			administrationId: ADMINISTRATION_ID,
			lifecycleState: 'active',
			ib47Recipient: true,
			bsn: '123456782',
			ib47PaymentTypeCode: '1',
			address: { street: 'Keizersgracht', houseNumber: '1', postcode: '1015CJ', city: 'Amsterdam', country: 'NL' },
		})

		const runs = [
			await reconciledRun('A', payeeId, 1200, `${TAX_YEAR}-03-15`),
			await reconciledRun('B', payeeId, 800, `${TAX_YEAR}-06-30`),
			await reconciledRun('C', payeeId, 500, `${TAX_YEAR - 1}-11-01`),
		]
		test.skip(
			runs.some((run) => run.state !== 'reconciled'),
			'the instance keeps a seeded payment run in its initial state; a reconciled run needs the four-eyes approve, export and bank reconcile of the payment-run suites',
		)

		// Scenario 1: one record with EUR 2,000; the 2025 payment stays out.
		const first = await prepare(payeeId)
		expect(first, 'one record for the payee').toHaveLength(1)
		expect(first[0].paymentsTotal).toBe(2000)
		expect(first[0].recipientName).toBe(name)

		// The statement file names the amount.
		const generated = await post('/reporting/generate', {
			reportType: 'ib47',
			period: String(TAX_YEAR),
			administrationId: ADMINISTRATION_ID,
			format: 'csv',
		})
		expect(generated.status(), await generated.text()).toBe(201)
		const report = await generated.json()
		reportIds.add(String(report.id ?? ''))
		const download = await api.get(String(report.downloadPath), { headers: { 'OCS-APIRequest': 'true' } })
		expect(download.status()).toBe(200)
		const csv = await download.text()
		const line = csv.split(/\r?\n/).find((row) => row.includes(name)) ?? ''
		expect(line, 'the statement has a line for the payee').not.toBe('')
		expect(line).toContain('2000.00')
		expect(line.startsWith(String(TAX_YEAR))).toBe(true)

		// Scenario 2: a further EUR 300 in December, then prepare again.
		const december = await reconciledRun('D', payeeId, 300, `${TAX_YEAR}-12-01`)
		expect(december.state).toBe('reconciled')
		const second = await prepare(payeeId)
		expect(second, 'still one record for the payee').toHaveLength(1)
		expect(second[0].paymentsTotal).toBe(2300)
		expect(second[0].created, 'the record is updated in place').toBe(false)
		expect(second[0].recordId).toBe(first[0].recordId)
	})
})
