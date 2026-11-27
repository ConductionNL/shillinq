/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the two quarterly returns of a municipality, the BCF claim
 * and the Wet Fido quarter, computed from the books (public-sector-quarterly-returns
 * task 3.2, REQ-BCF-010, REQ-FDO-010, REQ-FDO-011).
 *
 * The live pass of the change, driven through the `compute` transitions the
 * claim page and the quarterly report page offer, and the pages that show them:
 *
 *   1. BCF: EUR 21,000 of VAT posted in 2026-Q3 on an account mapped as
 *      compensable at 100 percent and EUR 4,200 on one at 50 percent; compute
 *      on the 2026-Q3 claim keeps EUR 23,100 compensable with both accounts in
 *      the breakdown, and the claim page shows them (REQ-BCF-010);
 *   2. Fido: a budget total of EUR 100,000,000 at 8.5 percent and a short-term
 *      loan of EUR 6,000,000 outstanding over the whole of 2026-Q3 (no bank
 *      balances); compute on the 2026-Q3 report gives a cash limit of
 *      EUR 8,500,000 and a headroom of EUR 2,500,000, and keeps both on the
 *      KasgeldLimiet (REQ-FDO-010);
 *   3. Fido: a long-term loan of EUR 12,000,000 refinancing in 2026 against a
 *      norm of 20 percent of the budget (EUR 20,000,000) leaves EUR 8,000,000
 *      of room, kept on the RenteRisicoNorm and on the report; the treasury
 *      dashboard shows the cash limit and the norm (REQ-FDO-011).
 *
 * Both computations read by administration (BCF) or organisation (Fido) only,
 * and go through OpenRegister lifecycle transitions with no membership check,
 * so every object here is seeded under a run-unique administration and
 * organisation id. The figures are then this run's alone: other GL lines,
 * mappings or loans on the instance cannot change them. Nothing is written to
 * an administration in use.
 *
 * The dashboard tiles show the KasgeldLimiet and RenteRisicoNorm with the
 * highest audit year of the whole register. The dashboard check runs only when
 * this run's 2026 records are that latest row; otherwise it skips and says so.
 *
 * Every object written here is removed afterwards.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no live instance).
 *
 * @spec openspec/changes/public-sector-quarterly-returns/specs/bookkeeping-bcf-vat-compensation/spec.md
 * @spec openspec/changes/public-sector-quarterly-returns/specs/bookkeeping-wet-fido-treasury/spec.md
 * @spec openspec/changes/public-sector-quarterly-returns/tasks.md
 *
 * @e2e bookkeeping-bcf-vat-compensation::a-controller-computes-the-third-quarter-claim
 * @e2e bookkeeping-wet-fido-treasury::a-treasurer-computes-the-third-quarter
 * @e2e bookkeeping-wet-fido-treasury::a-treasurer-reads-the-dashboard
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const APP = '/index.php/apps/shillinq'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const HEADERS = { 'OCS-APIRequest': 'true' }
const BCF_ADMINISTRATION = `${UNIQUE_PREFIX}-BCF`
const FIDO_ORGANISATION = `${UNIQUE_PREFIX}-FIDO`
const CLAIM_QUARTER = '2026-Q3'
const AUDIT_YEAR = 2026

/** One breakdown line as ComputeBcfClaimAction keeps it. */
interface BreakdownLine {
	accountNumber: string
	amount: number
	compensablePercentage: number
	compensableAmount: number
}

/** A cash or interest risk snapshot as FidoQuarter returns it. */
interface FidoSnapshot {
	ceiling: number
	exposure: number
	headroom: number
	status: string
}

test.describe('public-sector-quarterly-returns: the BCF claim and the Fido quarter', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let kasgeldId = ''
	let normId = ''

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
	 * Fire a lifecycle transition and expect it to pass.
	 *
	 * @param id     The object id.
	 * @param action The transition.
	 */
	async function transition(id: string, action: string): Promise<void> {
		const res = await fx.transition(id, action)
		expect(res.ok(), `${action} on ${id}: HTTP ${res.status()} ${await res.text()}`).toBeTruthy()
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
	})

	test.afterAll(async () => {
		await fx?.cleanup()
		await api?.dispose()
	})

	test('compute on the 2026-Q3 claim keeps EUR 23,100 compensable with both accounts in the breakdown @spec REQ-BCF-010', async ({ page }) => {
		const missing = await fx.missingSchema(['BcfClaim', 'BbvAccountMapping', 'GLTransaction', 'GLLine'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		for (const [accountNumber, compensablePercentage] of [['4300', 100], ['4310', 50]] as const) {
			await fx.create('BbvAccountMapping', {
				administrationId: BCF_ADMINISTRATION,
				accountNumber,
				taskField: '0.4',
				bcfCompensable: true,
				compensablePercentage,
			})
		}

		const { id: transactionId } = await fx.create('GLTransaction', {
			administrationId: BCF_ADMINISTRATION,
			periodId: CLAIM_QUARTER,
			transactionNumber: `${UNIQUE_PREFIX}-BCF-1`,
			postingDate: '2026-08-14',
			currency: 'EUR',
			state: 'posted',
			description: `${UNIQUE_PREFIX} purchases with compensable VAT`,
		})
		const lines: Array<[string, 'debit' | 'credit', number]> = [
			['4300', 'debit', 21000],
			['4310', 'debit', 4200],
			['1600', 'credit', 25200],
		]
		for (const [index, [accountNumber, side, amount]] of lines.entries()) {
			await fx.create('GLLine', {
				transactionId,
				administrationId: BCF_ADMINISTRATION,
				periodId: CLAIM_QUARTER,
				lineNumber: index + 1,
				accountNumber,
				side,
				amount,
				currency: 'EUR',
			})
		}

		const { id: claimId } = await fx.create('BcfClaim', {
			administrationId: BCF_ADMINISTRATION,
			claimQuarter: CLAIM_QUARTER,
			claimNumber: `${UNIQUE_PREFIX}-BCF-2026-Q3`,
			periodYear: AUDIT_YEAR,
			periodQuarter: 3,
			state: 'draft',
			currency: 'EUR',
		})

		await transition(claimId, 'compute')

		const claim = await fx.get('BcfClaim', claimId)
		expect(claim.state, 'compute keeps the claim a draft').toBe('draft')
		expect(Number(claim.totalCompensableAmount)).toBe(23100)
		expect(Number(claim.totalClaimAmount), 'the threshold guard reads the same total').toBe(23100)
		const breakdown = (claim.breakdown ?? []) as BreakdownLine[]
		expect(breakdown.map((line) => line.accountNumber)).toEqual(['4300', '4310'])
		expect(breakdown.map((line) => Number(line.compensableAmount))).toEqual([21000, 2100])
		expect(breakdown.map((line) => Number(line.compensablePercentage))).toEqual([100, 50])

		// The claim page shows the quarter, the total and the breakdown per account.
		await page.goto(`${APP}/overheid/bcf-claims/${claimId}`)
		const body = page.locator('body')
		await expect(body).toContainText('Compensable VAT', { timeout: 15_000 })
		await expect(body).toContainText(CLAIM_QUARTER)
		await expect(body).toContainText(/23[.,\s]?100/)
		await expect(body).toContainText('4300')
		await expect(body).toContainText('4310')
	})

	test('compute on the 2026-Q3 report gives a cash limit of EUR 8,500,000 and EUR 2,500,000 headroom, and EUR 8,000,000 of interest risk room @spec REQ-FDO-010 @spec REQ-FDO-011', async () => {
		const missing = await fx.missingSchema(['KasgeldLimiet', 'RenteRisicoNorm', 'Lening', 'QuartaalrapportageFido'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		kasgeldId = (await fx.create('KasgeldLimiet', {
			organisationId: FIDO_ORGANISATION,
			auditYear: AUDIT_YEAR,
			baseBudget: 100000000,
			percentage: 8.5,
		})).id
		normId = (await fx.create('RenteRisicoNorm', {
			organisationId: FIDO_ORGANISATION,
			auditYear: AUDIT_YEAR,
			baseFixedDebt: 100000000,
			percentage: 20,
		})).id

		// Short-term: runs under a year and is outstanding at every month end of Q3.
		const { id: shortId } = await fx.create('Lening', {
			organisationId: FIDO_ORGANISATION,
			counterparty: `${UNIQUE_PREFIX} BNG Bank`,
			type: 'kasgeld',
			principal: 6000000,
			currency: 'EUR',
			rateType: 'fixed',
			rate: 3.1,
			issueDate: '2026-06-15',
			maturityDate: '2027-03-15',
			repaymentSchedule: 'bullet',
			status: 'recorded',
		})
		// Long-term: matures, so refinances, in 2026.
		const { id: longId } = await fx.create('Lening', {
			organisationId: FIDO_ORGANISATION,
			counterparty: `${UNIQUE_PREFIX} NWB Bank`,
			type: 'onderhandse-lening',
			principal: 12000000,
			currency: 'EUR',
			rateType: 'fixed',
			rate: 2.4,
			issueDate: '2016-11-30',
			maturityDate: '2026-11-30',
			repaymentSchedule: 'bullet',
			status: 'recorded',
		})
		const states = [String((await fx.get('Lening', shortId)).status ?? ''), String((await fx.get('Lening', longId)).status ?? '')]
		test.skip(
			states.some((state) => state !== 'recorded'),
			'the instance keeps a seeded loan in its initial draft state; recording one needs an adopted Treasurystatuut signing mandate (FidoTreasuryGuard), which belongs to the Fido treasury suites',
		)

		const { id: reportId } = await fx.create('QuartaalrapportageFido', {
			organisationId: FIDO_ORGANISATION,
			auditYear: AUDIT_YEAR,
			quarter: 'Q3',
			status: 'draft',
		})

		await transition(reportId, 'compute')

		const report = await fx.get('QuartaalrapportageFido', reportId)
		expect(report.status, 'compute keeps the report a draft').toBe('draft')
		const cash = report.cashStatus as FidoSnapshot
		expect(Number(cash.ceiling)).toBe(8500000)
		expect(Number(cash.exposure), 'the average month-end net floating debt').toBe(6000000)
		expect(Number(cash.headroom)).toBe(2500000)
		expect(cash.status).toBe('binnen-norm')
		const risk = report.renteRiskStatus as FidoSnapshot
		expect(Number(risk.ceiling)).toBe(20000000)
		expect(Number(risk.exposure)).toBe(12000000)
		expect(Number(risk.headroom)).toBe(8000000)
		expect(risk.status).toBe('binnen-norm')

		// The dashboard reads these two records.
		const limit = await fx.get('KasgeldLimiet', kasgeldId)
		expect(Number(limit.calculatedCeiling)).toBe(8500000)
		expect(Number(limit.currentExposure)).toBe(6000000)
		expect(Number(limit.headroom)).toBe(2500000)
		expect(limit.status).toBe('binnen-norm')
		const norm = await fx.get('RenteRisicoNorm', normId)
		expect(Number(norm.calculatedCeiling)).toBe(20000000)
		expect(((norm.headroomPerYear ?? []) as number[]).map(Number)).toEqual([8000000, 20000000, 20000000, 20000000])
		expect(norm.status).toBe('binnen-norm')
	})

	test('the treasury dashboard shows the cash limit headroom and the interest risk norm room @spec REQ-FDO-011', async ({ page }) => {
		test.skip(kasgeldId === '' || normId === '', 'the Fido quarter was not computed in this run (see the test above)')

		/**
		 * Whether this run's record is the only one with the highest audit year.
		 *
		 * @param schema The schema the dashboard tile reads.
		 * @param id     This run's record.
		 * @return True when the tile shows this run's record.
		 */
		async function isLatest(schema: string, id: string): Promise<boolean> {
			const rows = await list(schema, {})
			const top = Math.max(...rows.map((row) => Number(row.auditYear ?? 0)))
			const latest = rows.filter((row) => Number(row.auditYear ?? 0) === top)
			const rowId = (row: Record<string, unknown>) => String((row['@self'] as { id?: string } | undefined)?.id ?? row.id ?? '')
			return latest.length === 1 && rowId(latest[0]) === id
		}
		test.skip(
			!(await isLatest('KasgeldLimiet', kasgeldId)) || !(await isLatest('RenteRisicoNorm', normId)),
			'the instance holds another KasgeldLimiet or RenteRisicoNorm for 2026 or a later year, so the dashboard tiles (latest audit year) do not show this run\'s records',
		)

		await page.goto(`${APP}/treasury/dashboard`)
		const body = page.locator('body')
		await expect(body).toContainText('Cash limit headroom', { timeout: 15_000 })
		await expect(body).toContainText('Interest rate risk norm headroom')
		await expect(body).toContainText(/8[.,\s]?500[.,\s]?000/)
		await expect(body).toContainText(/2[.,\s]?500[.,\s]?000/)
		await expect(body).toContainText(/20[.,\s]?000[.,\s]?000/)
		await expect(body).toContainText(/8[.,\s]?000[.,\s]?000/)
	})
})
