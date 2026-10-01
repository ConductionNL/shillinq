/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A bookkeeper imports a month of storage readings for Hosting Noord, rates
 * one, and bills September usage from the invoice generator
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

		await expect(page.getByTestId('meter-reading-created')).toContainText('2 readings imported')
		await expect(page.getByTestId('meter-reading-refused')).toContainText('Row 3: The quantity is negative.')
	})

	/**
	 * @e2e usage-metered-billing::rating-a-reading-shows-its-amount
	 */
	test('rating a 250 GB reading shows EUR 30.00', async ({ page }) => {
		await page.goto('/index.php/apps/shillinq/sales/meter-readings')
		await page.getByRole('row', { name: /250/ }).getByRole('checkbox').first().check()
		await page.getByRole('button', { name: 'Rate readings' }).click()

		await expect(page.getByText('1 readings rated.')).toBeVisible()
		await expect(page.getByRole('row', { name: /250/ })).toContainText('30.00')
	})

	/**
	 * @e2e usage-metered-billing::a-bookkeeper-bills-september-usage
	 * @e2e usage-metered-billing::an-invoiced-reading-is-not-offered-again
	 */
	test('billing September usage takes the rated readings once', async ({ page }) => {
		await page.goto('/index.php/apps/shillinq/invoice/generate')
		await page.getByLabel('Billing model').selectOption('usage')
		await page.getByLabel('Customer').fill('cust-hosting-noord')
		await page.getByLabel('From').fill('2026-09-01')
		await page.getByLabel('To').fill('2026-09-30')

		const readings = page.getByTestId('usage-readings')
		await expect(readings.getByRole('checkbox')).not.toHaveCount(0)
		await page.getByRole('button', { name: 'Save as Draft' }).click()
		await expect(page.getByText('Net amount')).toBeVisible()

		await page.getByLabel('Billing model').selectOption('t_and_m')
		await page.getByLabel('Billing model').selectOption('usage')
		await expect(readings).toContainText('no rated readings in this period')
	})
})
