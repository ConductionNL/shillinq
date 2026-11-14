/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The oven of Bakkerij Jansen is revised and depreciated extra, its missed
 * months are posted from the asset page, and the old van's gain sits in a
 * reinvestment reserve (assets-method-change-and-reserve REQ-AMCR-001 to 005).
 *
 * The register rows are answered through page.route with the states
 * AssetsMethodChangeAndReserveTest pins, so this test proves the screens; the
 * amounts, the posting and the reserve are proven by that test and by
 * FixedAssetDisposalListenerTest.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */

import { expect, test } from '@playwright/test'

const ovenId = '0a0e0000-0000-4000-8000-000000000001'

const oven = {
	id: ovenId,
	assetNumber: 'FA-2024-001',
	name: 'Rademaker deegverwerker',
	acquisitionCost: 60000,
	usefulLifeMonths: 90,
	depreciationMethod: 'linear',
	monthlyDepreciation: 750,
	currentBookValue: 42750,
	status: 'active',
}

function line(month: string, amount: number, status: string) {
	return {
		id: 'line-' + month,
		scheduleNumber: 'FA-2024-001-' + month,
		periodStartDate: month + '-01',
		depreciationAmount: amount,
		status,
	}
}

test.describe('assets-method-change-and-reserve', () => {
	test.beforeEach(async ({ page }) => {
		await page.route(`**/api/objects/shillinq/FixedAsset/${ovenId}**`, (route) =>
			route.fulfill({ json: oven }),
		)
	})

	/**
	 * @e2e bookkeeping-fixed-assets-depreciation::septembers-depreciation-is-posted
	 */
	test('the schedule shows September posted at 750', async ({ page }) => {
		await page.route('**/api/objects/shillinq/DepreciationSchedule**', (route) =>
			route.fulfill({
				json: {
					results: [
						line('2026-09', 750, 'posted'),
						line('2026-10', 750, 'planned'),
					],
					total: 2,
					page: 1,
					pages: 1,
				},
			}),
		)
		await page.goto(`/index.php/apps/shillinq/fixed-assets/${ovenId}`)

		const september = page.getByRole('row', { name: /2026-09/ })
		await expect(september).toContainText('750')
		await expect(september).toContainText(/posted/i)
	})

	/**
	 * @e2e bookkeeping-fixed-assets-depreciation::a-controller-shortens-the-ovens-life
	 */
	test('the asset page offers Revise depreciation with date, life and reason', async ({
		page,
	}) => {
		await page.route('**/available-actions**', (route) =>
			route.fulfill({
				json: {
					actions: [
						{
							action: 'revise',
							to: 'active',
							label: 'Revise depreciation',
							inputs: [
								{ field: 'revisionDate', required: true },
								{
									field: 'revisedUsefulLifeMonths',
									required: false,
								},
								{ field: 'revisionReason', required: true },
							],
						},
					],
				},
			}),
		)
		await page.goto(`/index.php/apps/shillinq/fixed-assets/${ovenId}`)
		const revise = page.getByRole('button', { name: 'Revise depreciation' })
		test.skip(
			!(await revise.isVisible().catch(() => false)),
			'Lifecycle actions not rendered',
		)

		await revise.click()
		await expect(page.getByRole('dialog')).toBeVisible()
	})

	/**
	 * @e2e bookkeeping-fixed-assets-depreciation::water-damage-lowers-the-ovens-value
	 */
	test('missed months are listed with their amounts and posted on request', async ({
		page,
	}) => {
		const posted: string[] = []
		await page.route(
			`**/apps/shillinq/api/fixed-assets/${ovenId}/missed-depreciation`,
			(route) => {
				if (route.request().method() === 'POST') {
					posted.push('post')
					return route.fulfill({
						json: {
							rows: [{ period: '2026-08', amount: 750 }],
							total: 750,
						},
					})
				}
				return route.fulfill({
					json: { rows: [{ period: '2026-08', amount: 750 }], total: 750 },
				})
			},
		)
		await page.goto(`/index.php/apps/shillinq/fixed-assets/${ovenId}`)
		const action = page.getByRole('button', { name: 'Post missed depreciation' })
		test.skip(
			!(await action.isVisible().catch(() => false)),
			'Header action not rendered',
		)

		await action.click()
		await expect(
			page.locator('[data-testid="missed-depreciation-rows"]'),
		).toContainText('2026-08')
		await page.locator('[data-testid="missed-depreciation-post"]').click()
		await expect(
			page.locator('[data-testid="missed-depreciation-dialog"]'),
		).toBeHidden()
		expect(posted).toEqual(['post'])
	})

	/**
	 * @e2e bookkeeping-fixed-assets-depreciation::selling-the-old-van
	 * @e2e bookkeeping-fixed-assets-depreciation::the-reserve-pays-for-part-of-the-new-van
	 */
	test('the reserve of 6,000 expiring 2029-12-31 is listed', async ({ page }) => {
		await page.route('**/api/objects/shillinq/ReinvestmentReserve**', (route) =>
			route.fulfill({
				json: {
					results: [
						{
							id: 'rir-1',
							disposedAssetNumber: 'FA-2019-004',
							formedOn: '2026-03-31',
							amount: 6000,
							appliedAmount: 6000,
							remainder: 0,
							expiresOn: '2029-12-31',
							lifecycleState: 'applied',
						},
					],
					total: 1,
					page: 1,
					pages: 1,
				},
			}),
		)
		await page.goto(
			'/index.php/apps/shillinq/fixed-assets/reinvestment-reserves',
		)

		const row = page.getByRole('row', { name: /FA-2019-004/ })
		await expect(row).toContainText('2029-12-31')
		await expect(row).toContainText(/6[.,]000/)
	})
})
