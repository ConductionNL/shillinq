/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A director pivots the ledger by account group and quarter, and a large
 * pivot says it was capped (reporting-custom-analysis REQ-RCA-002).
 *
 * The pivot endpoint is answered through page.route with the answer
 * LedgerPivotServiceTest pins for Adviesbureau Van Dijk, so this test proves
 * the screen; the sums themselves are proven by LedgerPivotServiceTest.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */

import { expect, test } from '@playwright/test'

const vanDijk = {
	rowAxis: 'accountGroup',
	columnAxis: 'quarter',
	rows: [
		{ key: '40', label: 'Personeelskosten' },
		{ key: '80', label: 'Omzet' },
	],
	columns: [
		{ key: '2026-Q1', label: '2026-Q1' },
		{ key: '2026-Q2', label: '2026-Q2' },
		{ key: '2026-Q3', label: '2026-Q3' },
	],
	cells: {
		40: { '2026-Q1': -30000, '2026-Q2': -30000, '2026-Q3': -30000 },
		80: { '2026-Q1': 60000, '2026-Q2': 58000, '2026-Q3': 62000 },
	},
	rowTotals: { 40: -90000, 80: 180000 },
	columnTotals: { '2026-Q1': 30000, '2026-Q2': 28000, '2026-Q3': 32000 },
	total: 90000,
	capped: { rows: false, columns: false },
	truncated: false,
}

test.describe('reporting-custom-analysis', () => {
	/**
	 * @e2e financial-dashboard-graphs::revenue-by-quarter
	 */
	test('a director sees revenue by quarter', async ({ page }) => {
		const asked: string[] = []
		await page.route('**/apps/shillinq/api/analysis/pivot**', (route) => {
			asked.push(route.request().url())
			return route.fulfill({ json: vanDijk })
		})
		await page.goto('/index.php/apps/shillinq/reports/pivot')

		const omzet = page.getByTestId('financial-pivot-table').getByRole('row', { name: /Omzet/ })
		await expect(omzet).toContainText('60,000')
		await expect(omzet).toContainText('180,000')
		expect(asked[0]).toContain('rows=accountGroup')
		expect(asked[0]).toContain('columns=quarter')
	})

	/**
	 * @e2e financial-dashboard-graphs::a-large-pivot-is-capped-visibly
	 */
	test('a capped pivot says so', async ({ page }) => {
		await page.route('**/apps/shillinq/api/analysis/pivot**', (route) =>
			route.fulfill({ json: { ...vanDijk, capped: { rows: true, columns: false } } }),
		)
		await page.goto('/index.php/apps/shillinq/reports/pivot')

		await expect(page.getByTestId('financial-pivot-capped')).toBeVisible()
	})
})
