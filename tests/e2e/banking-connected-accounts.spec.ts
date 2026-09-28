/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Connected bank accounts and the cash position (banking-connected-accounts).
 *
 * The cash position endpoint is answered through page.route with the seed of
 * the scenario, so the test proves the screens. The figures themselves are
 * proven by CashPositionByAccountTest against the calculator.
 *
 * @spec openspec/changes/banking-connected-accounts/tasks.md#task-4.2
 */

import { expect, test } from '@playwright/test'

const CASH_POSITION = {
	accounts: [
		{
			accountName: 'ING',
			iban: 'NL20INGB0001234567',
			ledgerAccountNumber: '1100',
			ledgerBalance: 84300,
			bankBalance: 84300,
			bankBalanceDate: '2026-09-28T06:00:00Z',
		},
		{
			accountName: 'Rabobank',
			iban: 'NL91RABO0123456789',
			ledgerAccountNumber: '1110',
			ledgerBalance: 250000,
			bankBalance: null,
			bankBalanceDate: null,
		},
	],
	other: 0,
	total: 334300,
}

test.describe('bookkeeping-treasury-ihb', () => {
	/**
	 * @e2e bookkeeping-treasury-ihb::a-controller-reads-the-combined-cash-position
	 */
	test('a controller reads the combined cash position', async ({ page }) => {
		await page.route('**/apps/shillinq/api/v1/cash-position**', (route) =>
			route.fulfill({ json: CASH_POSITION }),
		)
		await page.goto('/index.php/apps/shillinq/treasury/group-liquidity')

		await expect(page.getByText(/334[.,]300/).first()).toBeVisible({
			timeout: 15_000,
		})
		await expect(page.getByText('NL91RABO0123456789')).toBeVisible()
	})
})

test.describe('bookkeeping-bank-connectors', () => {
	/**
	 * @e2e bookkeeping-bank-connectors::a-controller-sees-which-accounts-are-connected
	 */
	test('a controller sees which accounts are connected', async ({ page }) => {
		await page.goto('/index.php/apps/shillinq/bookkeeping/bank-accounts')

		await expect(
			page.getByRole('columnheader', { name: 'Ledger account' }),
		).toBeVisible({ timeout: 15_000 })
		await expect(
			page.getByRole('columnheader', { name: 'Last sync' }),
		).toBeVisible()
		await expect(
			page.getByRole('button', { name: 'Connect a bank' }),
		).toBeVisible()
	})
})
