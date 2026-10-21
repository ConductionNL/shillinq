/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A bookkeeper imports a month of storage readings for Hosting Noord, rates
 * one, and bills September usage from the Generate invoices dialog
 * (sales-usage-billing REQ-USB-001, REQ-USB-002).
 *
 * The server half (refusal by row number, the rated amount, the invoiced
 * state) is proven by SalesUsageBillingTest; these flows prove the screens
 * reach it.
 *
 * @spec openspec/changes/sales-usage-billing/tasks.md#task-3.2
 */

import { expect, test } from '@playwright/test'

const csv = [
	'customerId,resourceType,quantity,unit,periodStart,periodEnd',
	'cust-hosting-noord,storage_gb,250,GB,2026-09-01,2026-09-30',
	'cust-hosting-noord,storage_gb,100,GB,2026-09-01,2026-09-30',
	'cust-hosting-noord,storage_gb,-5,GB,2026-09-01,2026-09-30',
].join('\n')

test.describe('sales-usage-billing', () => {
	/**
	 * @e2e usage-metered-billing::a-bookkeeper-imports-a-month-of-readings
	 */
	test('importing readings names the refused row', async ({ page }) => {
		await page.goto('/index.php/apps/shillinq/sales/meter-readings')
		await page.getByRole('button', { name: 'Import readings' }).click()
		await page.getByTestId('meter-reading-file').setInputFiles({
			name: 'september.csv',
			mimeType: 'text/csv',
			buffer: Buffer.from(csv),
		})
		await page.getByTestId('meter-reading-submit').click()

		await expect(page.getByTestId('meter-reading-created')).toContainText(
			'2 readings imported',
		)
		await expect(page.getByTestId('meter-reading-refused')).toContainText(
			'Row 3: The quantity is negative.',
		)
	})

	/**
	 * @e2e usage-metered-billing::rating-a-reading-shows-its-amount
	 */
	test('rating a 250 GB reading shows EUR 30.00', async ({ page }) => {
		await page.goto('/index.php/apps/shillinq/sales/meter-readings')
		await page
			.getByRole('row', { name: /250/ })
			.getByRole('checkbox')
			.first()
			.check()
		await page.getByRole('button', { name: 'Rate readings' }).click()

		await expect(page.getByText('1 readings rated.')).toBeVisible()
		await expect(page.getByRole('row', { name: /250/ })).toContainText('30.00')
	})

	/**
	 * @e2e usage-metered-billing::a-bookkeeper-bills-september-usage
	 * @e2e usage-metered-billing::an-invoiced-reading-is-not-offered-again
	 */
	test('billing September usage takes the rated readings once', async ({
		page,
	}) => {
		const chooseUsage = async () => {
			await page.goto('/index.php/apps/shillinq/bookkeeping/accounts-receivable')
			await page.getByRole('button', { name: 'Generate invoices' }).click()
			const wizard = page.getByTestId('invoice-generate-wizard')
			await wizard.getByTestId('igw-customer').locator('input').fill('Hosting Noord')
			await page.keyboard.press('Enter')
			await wizard.getByLabel('From').fill('2026-09-01')
			await wizard.getByLabel('To').fill('2026-09-30')
			await wizard.getByTestId('igw-model').locator('input').fill('Usage')
			await page.keyboard.press('Enter')
			await wizard.getByRole('button', { name: 'Next' }).click()
			return wizard
		}

		const wizard = await chooseUsage()
		const readings = wizard.locator('section', { hasText: 'Meter readings' })
		await expect(readings.getByRole('checkbox')).not.toHaveCount(0)
		await wizard.getByRole('button', { name: 'Next' }).click()
		await expect(wizard.getByText('Net amount')).toBeVisible()
		await wizard.getByRole('button', { name: 'Close' }).click()

		const again = await chooseUsage()
		await expect(again).toContainText('no rated readings in this period')
	})
})
