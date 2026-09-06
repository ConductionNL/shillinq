/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FINANCIAL-CORRECTNESS workflow — IFRS 16 lease amortization schedule.
 *
 * This is a DATA-DEPENDENT test: it seeds a LeaseContract with KNOWN inputs,
 * asks the live compute endpoint (GET /api/leases/schedule) to build the
 * amortization schedule, and asserts the EXACT computed numbers — not merely
 * that a page rendered. Wrong amortization math (opening liability,
 * interest accrual, principal split, closing-to-zero) is a high-harm
 * bookkeeping failure, so the assertions pin every column of every row.
 *
 * Known input (chosen so the arithmetic is hand-checkable):
 *   basePaymentAmount        = 1000.00 / period
 *   paymentFrequency         = monthly  (=> 12 periods/year)
 *   ibrPercent               = 6.0      (=> 0.5% per period)
 *   nonCancellableTermMonths = 3        (=> 3 periods)
 *   paymentTiming            = in-arrears
 *   classification           = IFRS16-capitalised
 *
 * Expected (PV of a 3-period ordinary annuity at 0.5%, cents-exact, mirrored
 * from lib/Service/LeaseAmortizationCalculator):
 *   opening lease liability (period 1) = 2970.25
 *   row 1: interest 14.85, principal 985.15, closing 1985.10
 *   row 2: interest  9.93, principal 990.07, closing  995.03
 *   row 3: interest  4.98, principal 995.03, closing     0.00  (final → exactly 0)
 *   sum of principal portions = 2970.25 (== opening liability)
 *
 * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-accounting/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { money, OrFixtures, UNIQUE_PREFIX } from './_fixtures.ts'

const APP = '/apps/shillinq'
const ADMIN_ID = `${UNIQUE_PREFIX}-adm`

// Required schemas for this calculation.
const NEEDED = ['LeaseContract']

interface ScheduleRow {
	periodSequence: number
	openingLeaseLiability: number
	interestAccrued: number
	paymentAppliedTotal: number
	paymentInterestPortion: number
	paymentPrincipalPortion: number
	closingLeaseLiability: number
}

test.describe('shillinq finance — IFRS 16 lease amortization (computed numbers)', () => {
	let fx: OrFixtures
	let api: APIRequestContext

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

	test('amortization schedule computes exact opening liability, interest, principal and closes to zero', async () => {
		// The shillinq register and its LeaseContract schema MUST be imported; the
		// prior import blocker is fixed, so a missing schema is now a real
		// regression.
		const missing = await fx.missingSchema(NEEDED)
		expect(
			missing,
			`shillinq register/schema not imported (missing: ${missing})`,
		).toBeNull()

		// Seed a capitalised lease with the known inputs above.
		const { id: leaseId } = await fx.create('LeaseContract', {
			administrationId: ADMIN_ID,
			leaseNumber: `${UNIQUE_PREFIX}-L1`,
			counterparty: 'Acme Leasing BV',
			description: `${UNIQUE_PREFIX} lease`,
			assetClass: 'machinery',
			commencementDate: '2024-01-01',
			endDate: '2024-03-31',
			classification: 'IFRS16-capitalised',
			basePaymentAmount: 1000.0,
			paymentFrequency: 'monthly',
			paymentTiming: 'in-arrears',
			paymentCurrency: 'EUR',
			ibrPercent: 6.0,
			ibrDerivationMethod: 'group-policy',
			nonCancellableTermMonths: 3,
			status: 'active',
			title: `${UNIQUE_PREFIX} lease`,
		})

		// Compute the schedule through the live endpoint.
		const res = await api.get(
			`/index.php${APP}/api/leases/schedule?lease_id=${leaseId}&administration_id=${ADMIN_ID}`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		expect(
			res.ok(),
			`schedule endpoint HTTP ${res.status()}: ${await res.text()}`,
		).toBeTruthy()
		const body = await res.json()
		const rows = (body.data ?? []) as ScheduleRow[]

		// Three periods.
		expect(rows.length, 'schedule must have 3 periods').toBe(3)

		// Opening liability == PV of the annuity.
		expect(money(rows[0].openingLeaseLiability)).toBe(2970.25)

		// Period 1.
		expect(money(rows[0].interestAccrued)).toBe(14.85)
		expect(money(rows[0].paymentPrincipalPortion)).toBe(985.15)
		expect(money(rows[0].closingLeaseLiability)).toBe(1985.1)

		// Period 2.
		expect(money(rows[1].interestAccrued)).toBe(9.93)
		expect(money(rows[1].paymentPrincipalPortion)).toBe(990.07)
		expect(money(rows[1].closingLeaseLiability)).toBe(995.03)

		// Period 3 — final period extinguishes the liability to EXACTLY zero.
		expect(money(rows[2].interestAccrued)).toBe(4.98)
		expect(money(rows[2].paymentPrincipalPortion)).toBe(995.03)
		expect(money(rows[2].closingLeaseLiability)).toBe(0)

		// Conservation: principal portions sum back to the opening liability.
		const principalSum = money(
			rows.reduce((s, r) => s + r.paymentPrincipalPortion, 0),
		)
		expect(principalSum).toBe(2970.25)

		// Each period: payment = interest + principal (the cash identity).
		for (const r of rows) {
			expect(money(r.paymentInterestPortion + r.paymentPrincipalPortion)).toBe(
				money(r.paymentAppliedTotal),
			)
		}
	})
})
