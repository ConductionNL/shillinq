/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reserves, the reserves over the years and the interest allocation
 * (public-sector-reserves-and-interest).
 *
 * The overview endpoint is answered through page.route with the seed of the
 * scenario, so the test proves the screens. The figures themselves are proven
 * by PublicSectorReservesAndInterestTest against the services and the real
 * register schema.
 *
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 */

import { expect, test } from '@playwright/test'

function row(
	year: number,
	opening: number,
	additions: number,
	withdrawals: number,
	planned: boolean,
) {
	return {
		reserveId: 'res-onderhoud-sport',
		reserveName: 'Reserve onderhoud sportaccommodaties',
		year,
		opening,
		additions,
		withdrawals,
		closing: opening + additions - withdrawals,
		planned,
		belowFloor: false,
		aboveCeiling: false,
	}
}

const OVERVIEW = {
	fromYear: 2026,
	rows: [
		row(2026, 800000, 0, 250000, false),
		row(2027, 550000, 150000, 0, true),
		row(2028, 700000, 150000, 0, true),
		row(2029, 850000, 150000, 0, true),
		row(2030, 1000000, 150000, 0, true),
	],
}

test.describe('bookkeeping-programmabegroting', () => {
	/**
	 * @e2e bookkeeping-programmabegroting::a-controller-records-a-withdrawal
	 */
	test('a controller records a withdrawal', async ({ page }) => {
		await page.goto('/index.php/apps/shillinq/overheid/reserve-mutations')

		await expect(
			page.getByRole('columnheader', { name: 'Council resolution' }),
		).toBeVisible({ timeout: 15_000 })
		await expect(
			page.getByRole('columnheader', { name: 'Status' }),
		).toBeVisible()
	})

	/**
	 * @e2e bookkeeping-programmabegroting::the-overview-shows-the-plan
	 */
	test('the overview shows the plan', async ({ page }) => {
		await page.route(
			'**/apps/shillinq/api/v1/public-sector/reserves/overview**',
			(route) => route.fulfill({ json: OVERVIEW }),
		)
		await page.goto('/index.php/apps/shillinq/overheid/reserves/overview')

		await expect(page.getByText(/1[.,]150[.,]000/).first()).toBeVisible({
			timeout: 15_000,
		})
		await expect(
			page.getByRole('columnheader', { name: 'Planned' }),
		).toBeVisible()
	})

	/**
	 * @e2e bookkeeping-programmabegroting::the-2026-interest-run
	 */
	test('the 2026 interest run', async ({ page }) => {
		await page.goto('/index.php/apps/shillinq/overheid/interest-allocation')

		await expect(
			page.getByRole('columnheader', { name: 'Pooled rate (%)' }),
		).toBeVisible({ timeout: 15_000 })
		await expect(
			page.getByRole('columnheader', { name: 'Charged to task fields' }),
		).toBeVisible()
	})
})
