/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The segment P&L dashboard shows revenue, costs and result per segment for
 * one period (reporting-segment-results REQ-RSR-003).
 *
 * The administration context and the aggregation endpoint are answered
 * through page.route with the KP-300 seed of the design, so the test proves
 * the screen and the query it sends. The figures themselves (bank line and
 * draft left out, EUR 12,000 result) are proven by GlLineResultStampsTest
 * against the stamps and the real register fragment.
 *
 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
 */

import { expect, test } from '@playwright/test'

const KP300 = {
	groups: [
		{
			key: 'KP-300',
			joined: { 'AnalyticalDimension.name': 'Sociaal Domein' },
			values: { revenue: 40000, costs: 28000, result: 12000 },
		},
	],
}

test.describe('reporting-segment-results', () => {
	/**
	 * @e2e bookkeeping-cost-centers-dimensions::a-manager-reads-sociaal-domeins-september
	 */
	test('a manager reads the September result of KP-300', async ({ page }) => {
		const queries: string[] = []
		await page.route('**/apps/shillinq/api/administrations/context', (route) =>
			route.fulfill({ json: { activeAdministrationId: 'adm-voorbeeld' } }),
		)
		await page.route('**/api/objects/shillinq/FiscalPeriod**', (route) =>
			route.fulfill({
				json: {
					results: [
						{ periodId: '2026-M09', name: 'September 2026', startDate: '2026-09-01' },
					],
				},
			}),
		)
		await page.route('**/api/objects/aggregations/**/GLLine/byCostCenter**', (route) => {
			queries.push(route.request().url())
			return route.fulfill({ json: KP300 })
		})

		await page.goto('/index.php/apps/shillinq/bookkeeping/dimensions/segment-pnl')
		await page.getByLabel('Period').selectOption('2026-M09')

		const row = page.locator('tr', { hasText: 'KP-300' })
		await expect(row).toContainText('Sociaal Domein')
		await expect(row).toContainText('40,000')
		await expect(row).toContainText('28,000')
		await expect(row).toContainText('12,000')
		expect(queries.some((url) => url.includes('adm-voorbeeld'))).toBe(true)
		expect(queries.some((url) => url.includes('2026-M09'))).toBe(true)
	})
})
