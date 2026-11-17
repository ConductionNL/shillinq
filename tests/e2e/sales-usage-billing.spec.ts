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

const PLANS = '/index.php/apps/openregister/api/objects/shillinq/UsageRatePlan'

test.describe('sales-usage-billing', () => {
	// The shipped rate plans sit in the demo administration, so the import
	// finds no plan for the active one. Rating needs a storage_gb plan there:
	// a flat 12 cents per GB, which prices 250 GB at EUR 30.00.
	test.beforeAll(async ({ request }) => {
		const headers = { 'OCS-APIRequest': 'true' }
		const context = await request.get('/index.php/apps/shillinq/api/administrations/context', { headers })
		expect(context.status()).toBe(200)
		const body = await context.json()
		const administrationId = String(body?.activeAdministrationId ?? body?.administrations?.[0]?.administrationId ?? '')
		expect(administrationId).not.toBe('')

		const existing = await request.get(`${PLANS}?administrationId=${encodeURIComponent(administrationId)}&resourceType=storage_gb`, { headers })
		if (((await existing.json())?.results ?? []).length > 0) {
			return
		}
		const created = await request.post(PLANS, {
			headers,
			data: {
				administrationId,
				name: 'E2E storage per GB',
				resourceType: 'storage_gb',
				unit: 'GB',
				ratingMethod: 'flat',
				unitPriceCents: 12,
				currency: 'EUR',
				vatRate: 21,
				glRevenueAccount: '4500',
				effectiveFrom: '2026-01-01',
			},
		})
		expect(created.ok(), await created.text()).toBe(true)
	})

	/**
	 * @e2e usage-metered-billing::a-bookkeeper-imports-a-month-of-readings
	 */
	test('importing readings names the refused row', async ({ page }) => {
		await page.goto('/index.php/apps/shillinq/sales/meter-readings')
		await page.getByTestId('cn-actions-bar').getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Import readings' }).click()
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
		// The row checkbox's own label covers the input, so click the label as a user does.
		const reading = page.getByRole('row', { name: /250/ }).first()
		await reading.locator('.checkbox-radio-switch__content').first().click()
		await expect(reading.getByRole('checkbox').first()).toBeChecked()
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
		await page.goto('/index.php/apps/shillinq/invoice/generate')
		await page.getByLabel('Billing model').selectOption('usage')
		await page.getByLabel('Customer', { exact: true }).fill('cust-hosting-noord')
		await page.getByLabel('From', { exact: true }).fill('2026-09-01')
		await page.getByLabel('To', { exact: true }).fill('2026-09-30')

		const readings = page.getByTestId('usage-readings')
		await expect(readings.getByRole('checkbox')).not.toHaveCount(0)
		await page.getByRole('button', { name: 'Save as Draft' }).click()
		await expect(page.getByText('Net amount')).toBeVisible()

		await page.getByLabel('Billing model').selectOption('t_and_m')
		await page.getByLabel('Billing model').selectOption('usage')
		await expect(readings).toContainText('no rated readings in this period')
	})
})
