/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the three IFRS calculations of a year, read after it closes
 * (reporting-ifrs-calculations task 1.4, REQ-DGAAP-011, REQ-PEN-011,
 * REQ-LD-008).
 *
 * The live pass of the change, "close a seed year and read the three
 * outputs", driven through the transitions and the period-close route that
 * trigger the three calculations:
 *
 *   1. dual GAAP: a receivables provision of EUR 4,000 under Dutch GAAP, marked
 *      divergent with an IFRS 9 rule giving EUR 5,200, is posted (the `post`
 *      transition); the IFRS entry of EUR 5,200 is derived on a DualTransaction
 *      and the period's net result bridge from NL-GAAP-RJ to IFRS-EU shows a
 *      difference of EUR 1,200 (REQ-DGAAP-011);
 *   2. IAS 19: a plan's 2026 movement with service cost EUR 80,000, net
 *      interest EUR 12,000 and an actuarial loss of EUR 30,000; approving the
 *      valuation writes one balanced journal entry with EUR 92,000 to the
 *      profit and loss and EUR 30,000 to other comprehensive income, and the
 *      movement keeps both totals and the entry (REQ-PEN-011);
 *   3. lease disclosures: with three active leases, closing the year
 *      (POST /api/period-close/{periodId}/close) stores the year's
 *      LeaseDisclosureTable with the maturity analysis of the three leases,
 *      and the IFRS 16 disclosures page lists it (REQ-LD-008).
 *
 * The year is a FiscalPeriod this spec seeds, open, under a run-unique period
 * id in the administration; the divergent entry is posted into it and it is
 * the period that is closed, so no period in use is closed. The
 * administration defaults to the seed's `ADM-001`; `E2E_ADMINISTRATION_ID`
 * names another. The close route checks the caller's membership (a 404 skips
 * with that reason) and refuses while the administration has unmatched bank
 * suspense items (a 422 naming them skips with that reason).
 *
 * The disclosure table sums every active lease of the administration. The
 * maturity buckets are asserted to the cent band when the administration had
 * no active lease before this run, and as a lower bound otherwise.
 *
 * PensionValuationPostingService writes the IAS 19 entry as a draft
 * GLTransaction; the spec asserts the entry and its balance, and records the
 * state it finds rather than asserting `posted`.
 *
 * Every object written here is removed afterwards, the derived DualTransaction,
 * the bridge, the pension entry and the disclosure row included. A posted
 * entry the ledger seals may refuse deletion; cleanup is best-effort.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no live instance).
 *
 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-pension-ias19/spec.md
 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-lease-disclosures/spec.md
 * @spec openspec/changes/reporting-ifrs-calculations/tasks.md
 *
 * @e2e bookkeeping-ifrs-rj-dual-gaap::an-expected-credit-loss-differs-between-the-frameworks
 * @e2e bookkeeping-pension-ias19::a-valuation-is-booked
 * @e2e bookkeeping-lease-disclosures::the-disclosures-page-shows-the-closed-year
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const APP = '/index.php/apps/shillinq'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const HEADERS = { 'OCS-APIRequest': 'true' }
const ADMINISTRATION_ID = process.env.E2E_ADMINISTRATION_ID ?? 'ADM-001'
const PERIOD_ID = `FY2026-${UNIQUE_PREFIX}`

/** A journal line as the derivation and the pension posting write them. */
interface JournalLine {
	accountNumber: string
	side: 'debit' | 'credit'
	amount: number
}

/** One adjustment row on a ReconciliationBridge. */
interface BridgeAdjustment {
	amount: number
	sourceTransactionId: string
	dualTransactionId: string
}

/**
 * The OpenRegister id of an object as a list answers it.
 *
 * @param row The object.
 * @return Its id.
 */
function idOf(row: Record<string, unknown>): string {
	return String((row['@self'] as { id?: string } | undefined)?.id ?? row.id ?? '')
}

/**
 * The total of one side of a set of journal lines, in cents.
 *
 * @param lines The lines.
 * @param side  The side.
 * @return The total in cents.
 */
function sideCents(lines: JournalLine[], side: 'debit' | 'credit'): number {
	return lines.filter((line) => line.side === side).reduce((sum, line) => sum + Math.round(Number(line.amount) * 100), 0)
}

test.describe('reporting-ifrs-calculations: close a year and read the three outputs', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let token = ''
	let periodSeeded = false
	const written: Array<{ schema: string, id: string }> = []

	/**
	 * The CSRF token shillinq's own write routes need beside the session cookie.
	 *
	 * @return The request token of an authenticated app page.
	 */
	async function requestToken(): Promise<string> {
		if (token === '') {
			const html = await (await api.get(`${APP}/`)).text()
			token = html.match(/data-requesttoken="([^"]+)"/)?.[1] ?? ''
		}
		return token
	}

	/**
	 * Every object of a schema matching the query.
	 *
	 * @param schema The schema slug.
	 * @param query  Field filters.
	 * @return The matching objects.
	 */
	async function list(schema: string, query: Record<string, string>): Promise<Record<string, unknown>[]> {
		const params = new URLSearchParams({ ...query, _limit: '1000' })
		const res = await api.get(`${OBJECTS}/${schema}?${params.toString()}`, { headers: HEADERS })
		expect(res.ok(), `list ${schema}: HTTP ${res.status()}`).toBeTruthy()
		const body = await res.json()
		return (body.results ?? body ?? []) as Record<string, unknown>[]
	}

	/**
	 * The rows a listener writes after a transition, polled until they appear,
	 * and tracked for cleanup.
	 *
	 * @param schema The schema slug.
	 * @param query  Field filters naming this run's rows.
	 * @param match  Keeps only the rows of this run (OpenRegister may ignore an unknown filter).
	 * @return The rows.
	 */
	async function listenerRows(schema: string, query: Record<string, string>, match: (row: Record<string, unknown>) => boolean): Promise<Record<string, unknown>[]> {
		let rows: Record<string, unknown>[] = []
		await expect.poll(async () => {
			rows = (await list(schema, query)).filter(match)
			return rows.length
		}, { message: `the ${schema} the listener writes`, timeout: 15_000 }).toBeGreaterThan(0)
		for (const row of rows) {
			if (!written.some((entry) => entry.id === idOf(row))) {
				written.push({ schema, id: idOf(row) })
			}
		}
		return rows
	}

	/**
	 * Fire a lifecycle transition and expect it to pass.
	 *
	 * @param id     The object id.
	 * @param action The transition.
	 */
	async function transition(id: string, action: string): Promise<void> {
		const res = await fx.transition(id, action)
		expect(res.ok(), `${action} on ${id}: HTTP ${res.status()} ${await res.text()}`).toBeTruthy()
	}

	/** The open 2026 period of this run, seeded once. */
	async function seedPeriod(): Promise<void> {
		if (periodSeeded) {
			return
		}
		await fx.create('FiscalPeriod', {
			periodId: PERIOD_ID,
			name: `2026 (${UNIQUE_PREFIX})`,
			startDate: '2026-01-01',
			endDate: '2026-12-31',
			fiscalYear: '2026',
			administrationId: ADMINISTRATION_ID,
			state: 'open',
		})
		periodSeeded = true
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
	})

	test.afterAll(async () => {
		for (const { schema, id } of written.reverse()) {
			await fx?.remove(schema, id)
		}
		await fx?.cleanup()
		await api?.dispose()
	})

	test('posting a EUR 4,000 provision with an IFRS 9 rule of EUR 5,200 derives the IFRS entry and a EUR 1,200 bridge line @spec REQ-DGAAP-011', async () => {
		const missing = await fx.missingSchema(['FiscalPeriod', 'GLTransaction', 'GLLine', 'StandardSpecificCalculation', 'DualTransaction', 'ReconciliationBridge'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)
		await seedPeriod()

		const { id: calculationId } = await fx.create('StandardSpecificCalculation', {
			administrationId: ADMINISTRATION_ID,
			standardCode: 'IFRS-9',
			calculationMethod: 'expected_credit_loss_stages',
			contractOrPositionReference: `${UNIQUE_PREFIX} trade receivables`,
			inputs: { grossReceivables: 80000 },
			outputs: { rjAmount: 4000, ifrsAmount: 5200 },
			revaluationFrequency: 'annual',
		})
		const { id: transactionId } = await fx.create('GLTransaction', {
			administrationId: ADMINISTRATION_ID,
			periodId: PERIOD_ID,
			transactionNumber: `${UNIQUE_PREFIX}-ECL-1`,
			postingDate: '2026-12-15',
			currency: 'EUR',
			state: 'draft',
			description: `${UNIQUE_PREFIX} receivables provision`,
			divergenceReasonCode: 'ECL_IFRS9',
			adjustmentCalculationId: calculationId,
		})
		for (const [index, [accountNumber, side]] of ([['4910', 'debit'], ['1390', 'credit']] as const).entries()) {
			await fx.create('GLLine', {
				transactionId,
				administrationId: ADMINISTRATION_ID,
				periodId: PERIOD_ID,
				lineNumber: index + 1,
				accountNumber,
				side,
				amount: 4000,
				currency: 'EUR',
			})
		}

		await transition(transactionId, 'post')
		expect((await fx.get('GLTransaction', transactionId)).state).toBe('posted')

		// The IFRS entry is derived once, on one DualTransaction.
		const duals = await listenerRows('DualTransaction', { baseTransactionId: transactionId }, (row) => row.baseTransactionId === transactionId)
		expect(duals, 'derived once').toHaveLength(1)
		const dual = duals[0]
		expect(dual.state).toBe('open')
		expect(dual.divergenceReasonCode).toBe('ECL_IFRS9')
		expect(Number(dual.divergenceAmount)).toBe(1200)
		const ifrs = (dual.ifrsJournalEntries ?? []) as JournalLine[]
		expect(sideCents(ifrs, 'debit')).toBe(520000)
		expect(sideCents(ifrs, 'credit'), 'the derived entry balances').toBe(520000)
		const rj = (dual.rjJournalEntries ?? []) as JournalLine[]
		expect(sideCents(rj, 'debit')).toBe(400000)

		// The period's net result bridge from Dutch GAAP to IFRS carries the difference.
		const bridges = await listenerRows(
			'ReconciliationBridge',
			{ administrationId: ADMINISTRATION_ID, period: PERIOD_ID },
			(row) => row.period === PERIOD_ID && row.administrationId === ADMINISTRATION_ID,
		)
		expect(bridges).toHaveLength(1)
		const bridge = bridges[0]
		expect(bridge.metric).toBe('net_result')
		expect(bridge.fromFramework).toBe('NL-GAAP-RJ')
		expect(bridge.toFramework).toBe('IFRS-EU')
		const line = ((bridge.adjustments ?? []) as BridgeAdjustment[]).find((row) => row.sourceTransactionId === transactionId)
		expect(line, 'a bridge line for the provision').toBeTruthy()
		expect(Number(line?.amount)).toBe(1200)
		expect(line?.dualTransactionId).toBe(idOf(dual))
		expect(Number(bridge.closingBalanceIfrs)).toBe(1200)
	})

	test('approving the 2026 valuation books EUR 92,000 to the profit and loss and EUR 30,000 to OCI in one balanced entry @spec REQ-PEN-011', async () => {
		const missing = await fx.missingSchema(['ActuarialValuation', 'PensionMovement', 'GLTransaction', 'GLLine'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		const plan = `${UNIQUE_PREFIX}-PLAN`
		const { id: movementId } = await fx.create('PensionMovement', {
			administrationId: ADMINISTRATION_ID,
			plan,
			period: '2026',
			dboOpening: 1800000,
			serviceCostCurrent: 80000,
			pastServiceCost: 0,
			gainOnSettlement: 0,
			netInterestCost: 12000,
			actuarialLossGainDBO: 30000,
			benefitsPaid: 40000,
			dboClosing: 1882000,
			planAssetsOpening: 1500000,
			expectedReturnOnAssets: 52500,
			actualReturnOnAssets: 52500,
			actuarialGainLossAssets: 0,
			employerContributions: 60000,
			benefitsPaidFromAssets: 40000,
			planAssetsClosing: 1572500,
			netPensionMovementPL: 0,
			netPensionMovementOCI: 0,
		})
		const { id: valuationId } = await fx.create('ActuarialValuation', {
			administrationId: ADMINISTRATION_ID,
			plan,
			valuationDate: '2026-12-31',
			actuary: 'e2e actuary',
			actuaryCertificationNumber: `AG-${UNIQUE_PREFIX}`,
			methodology: 'PUC',
			dboGross: 1882000,
			dboPastService: 1700000,
			dboFutureService: 182000,
			discountRate: 3.5,
			discountRateSource: 'iBoxx EUR Corporates AA 10+ at 31 December 2026',
			salaryGrowthAssumption: 2.5,
			pensionGrowthAssumption: 2,
			inflationAssumption: 2,
			mortalityTable: 'AG2024',
			retirementAgeAssumption: 68,
			planAssetsFairValue: 1572500,
			netPensionLiability: 309500,
			approvalStatus: 'draft',
		})

		await transition(valuationId, 'approve')
		expect((await fx.get('ActuarialValuation', valuationId)).approvalStatus).toBe('approved')

		const entries = await listenerRows('GLTransaction', { sourceReference: valuationId }, (row) => row.sourceReference === valuationId)
		expect(entries, 'one journal entry for the valuation').toHaveLength(1)
		const entryId = idOf(entries[0])
		test.info().annotations.push({ type: 'pension entry state', description: String(entries[0].state ?? '') })
		const lines = (await listenerRows('GLLine', { transactionId: entryId }, (row) => row.transactionId === entryId)) as unknown as JournalLine[]
		expect(lines.filter((row) => row.side === 'debit').map((row) => Number(row.amount)).sort((a, b) => a - b), 'EUR 30,000 OCI and EUR 92,000 P&L').toEqual([30000, 92000])
		expect(lines.filter((row) => row.side === 'credit').map((row) => Number(row.amount)), 'against the net pension liability').toEqual([122000])
		expect(sideCents(lines, 'debit'), 'the entry balances').toBe(sideCents(lines, 'credit'))

		const movement = await fx.get('PensionMovement', movementId)
		expect(Number(movement.netPensionMovementPL)).toBe(92000)
		expect(Number(movement.netPensionMovementOCI)).toBe(30000)
		expect((movement.linkedJournalEntries ?? []) as string[]).toContain(entryId)
	})

	test('closing 2026 stores the lease disclosure table with the maturity analysis of three leases, and the disclosures page lists it @spec REQ-LD-008', async ({ page }) => {
		const missing = await fx.missingSchema(['FiscalPeriod', 'LeaseContract', 'LeaseDisclosureTable'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)
		await seedPeriod()

		const before = (await list('LeaseContract', { administrationId: ADMINISTRATION_ID }))
			.filter((lease) => lease.administrationId === ADMINISTRATION_ID && ['active', 'modified'].includes(String(lease.status ?? '')))
		const leaseIds: string[] = []
		for (const n of [1, 2, 3]) {
			const { id } = await fx.create('LeaseContract', {
				administrationId: ADMINISTRATION_ID,
				leaseNumber: `${UNIQUE_PREFIX}-LEASE-${n}`,
				counterparty: 'e2e Lease B.V.',
				description: `${UNIQUE_PREFIX} company car ${n}`,
				assetClass: 'vehicle',
				commencementDate: '2026-01-01',
				endDate: '2028-12-31',
				nonCancellableTermMonths: 36,
				paymentFrequency: 'monthly',
				paymentTiming: 'in-arrears',
				basePaymentAmount: 1000,
				paymentCurrency: 'EUR',
				indexationClause: 'none',
				ibrPercent: 4,
				ibrDerivationMethod: 'group-policy',
				classification: 'IFRS16-capitalised',
				status: 'active',
			})
			leaseIds.push(id)
		}
		const states = await Promise.all(leaseIds.map(async (id) => String((await fx.get('LeaseContract', id)).status ?? '')))
		test.skip(states.some((state) => state !== 'active'), 'the instance keeps a seeded lease in its initial draft state; activation goes through LeaseContractGuard in the lease suites')

		const closed = await api.post(`${APP}/api/period-close/${encodeURIComponent(PERIOD_ID)}/close`, {
			headers: { requesttoken: await requestToken(), 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: { administration_id: ADMINISTRATION_ID },
		})
		test.skip(closed.status() === 404, `the administrator holds no membership of ${ADMINISTRATION_ID}; set E2E_ADMINISTRATION_ID`)
		const closedText = await closed.text()
		test.skip(closed.status() === 422 && closedText.includes('suspense'), `${ADMINISTRATION_ID} has unmatched bank suspense items, so no period of it closes: ${closedText}`)
		expect(closed.status(), closedText).toBe(200)
		expect(JSON.parse(closedText).data.state).toBe('closed')

		const tables = await listenerRows(
			'LeaseDisclosureTable',
			{ administrationId: ADMINISTRATION_ID, fiscalPeriod: PERIOD_ID },
			(row) => row.fiscalPeriod === PERIOD_ID && row.administrationId === ADMINISTRATION_ID,
		)
		expect(tables, 'one table for the administration and the year').toHaveLength(1)
		const table = tables[0]
		expect(table.materializedAtPeriodClose).toBe(true)
		const maturity = table.maturityAnalysis as Record<string, number>
		expect(Object.keys(maturity).sort()).toEqual(['gt5y', 'lt1y', 'y1to2', 'y2to3', 'y3to4', 'y4to5'])
		// Three leases of 36 payments of EUR 1,000: EUR 36,000 in each of the first three years.
		for (const bucket of ['lt1y', 'y1to2', 'y2to3']) {
			if (before.length === 0) {
				expect(Number(maturity[bucket]), `maturity ${bucket}`).toBeCloseTo(36000, -1)
			} else {
				expect(Number(maturity[bucket]), `maturity ${bucket}, with ${before.length} other active lease(s)`).toBeGreaterThanOrEqual(35990)
			}
		}
		if (before.length === 0) {
			expect(Number(maturity.y3to4) + Number(maturity.y4to5) + Number(maturity.gt5y)).toBe(0)
			expect(Number(table.totalLeaseLiabilityCurrent) + Number(table.totalLeaseLiabilityNoncurrent)).toBeGreaterThan(0)
		}

		// The IFRS 16 disclosures page lists the closed year (on its first page when the register is small).
		const all = await list('LeaseDisclosureTable', {})
		await page.goto(`${APP}/ifrs-16/disclosures`)
		const body = page.locator('body')
		await expect(body).toContainText('IFRS 16 Disclosure', { timeout: 15_000 })
		if (all.length <= 20) {
			await expect(body).toContainText(PERIOD_ID)
		} else {
			test.info().annotations.push({ type: 'not checked', description: `${all.length} disclosure rows; this run's row may be on a later page` })
		}
	})
})
