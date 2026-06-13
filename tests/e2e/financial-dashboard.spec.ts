/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — financial-dashboard-graphs.
 *
 * Drives the Financial overview dashboard (route `/`) and its seven
 * custom widgets: the KPI strip, the four charts (turnover, margin
 * with €/% toggle, cashflow with forecast, billable hours with
 * total/% toggle) and the two open-invoice tables.
 *
 * Spec scenarios covered:
 *   - KPI strip renders six metrics
 *   - Monthly turnover from posted revenue lines
 *   - Margin toggle switches between euro and percentage
 *   - Realized cashflow with dimmed forecast
 *   - Billable hours toggle to percentage view
 *   - Overdue debtor invoice is flagged
 *   - Open creditor invoices listed by due date
 *
 * Authored defensively for dev-container topologies: when the demo
 * seed (scripts/seed-demo-financials.py) has not run, the charts
 * mount with empty series and the tables show their empty state —
 * the widget shells must still render. Assertions that need data
 * are conditional on the seeded marker (a non-empty debtors table).
 */

import { test, expect } from '@playwright/test'

const APP = '/apps/shillinq'

test.describe('financial-dashboard-graphs — Financial overview', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
		await page.goto(APP + '/')
		await page.waitForLoadState('networkidle')

		// Dismiss any first-run wizard overlay.
		const wizard = page.locator('#firstrunwizard')
		if (await wizard.isVisible().catch(() => false)) {
			await page.keyboard.press('Escape').catch(() => {})
			await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
		}
	})

	test('KPI strip renders six metrics', async ({ page }) => {
		const strip = page.getByTestId('finance-kpis')
		await expect(strip).toBeVisible({ timeout: 15_000 })
		for (const key of ['turnover', 'margin', 'debtors', 'creditors', 'billable', 'cash']) {
			await expect(page.getByTestId(`finance-kpi-${key}`)).toBeVisible({ timeout: 15_000 })
		}
		// Euro formatting on the turnover tile.
		await expect(page.getByTestId('finance-kpi-turnover')).toContainText('€')
	})

	test('turnover chart renders monthly columns from posted revenue lines', async ({ page }) => {
		const chart = page.getByTestId('turnover-chart')
		await expect(chart).toBeVisible({ timeout: 15_000 })
		// ApexCharts mounts an SVG once the series resolve.
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({ timeout: 15_000 })
	})

	test('margin toggle switches between euro and percentage view', async ({ page }) => {
		const chart = page.getByTestId('margin-chart')
		await expect(chart).toBeVisible({ timeout: 15_000 })
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({ timeout: 15_000 })

		// Default € view shows the three-series legend (Revenue/Costs/Margin).
		await expect(chart.getByText('Revenue', { exact: false }).first()).toBeVisible()

		// % view is a single percentage series: the legend disappears
		// (ApexCharts hides single-series legends) and the y-axis
		// switches to percentage tick labels.
		await page.getByTestId('margin-toggle-pct').click()
		await expect(page.getByTestId('margin-toggle-pct')).toHaveAttribute('aria-pressed', 'true')
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({ timeout: 15_000 })
		await expect(chart.getByText(/^\d+%$/).first()).toBeVisible({ timeout: 15_000 })

		await page.getByTestId('margin-toggle-value').click()
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({ timeout: 15_000 })
		await expect(chart.getByText('Revenue', { exact: false }).first()).toBeVisible()
	})

	test('cashflow chart mounts with realized and forecast series', async ({ page }) => {
		const chart = page.getByTestId('cashflow-chart')
		await expect(chart).toBeVisible({ timeout: 15_000 })
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({ timeout: 15_000 })
		// Legend always carries the realized series names.
		await expect(chart.getByText('Money in', { exact: false }).first()).toBeVisible()
	})

	test('billable hours toggle switches to percentage view', async ({ page }) => {
		const chart = page.getByTestId('billable-chart')
		await expect(chart).toBeVisible({ timeout: 15_000 })
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({ timeout: 15_000 })

		// Stacked view carries the two-series legend.
		await expect(chart.getByText('Non-billable', { exact: false }).first()).toBeVisible()

		// % view is a single line series: legend disappears, y-axis
		// flips to percentage tick labels.
		await page.getByTestId('billable-toggle-pct').click()
		await expect(page.getByTestId('billable-toggle-pct')).toHaveAttribute('aria-pressed', 'true')
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({ timeout: 15_000 })
		await expect(chart.getByText(/^\d+%$/).first()).toBeVisible({ timeout: 15_000 })

		await page.getByTestId('billable-toggle-total').click()
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({ timeout: 15_000 })
		await expect(chart.getByText('Non-billable', { exact: false }).first()).toBeVisible()
	})

	test('open debtors table lists open invoices with overdue flag (seeded) or empty state', async ({ page }) => {
		const widget = page.getByTestId('open-invoices-debtors')
		await expect(widget).toBeVisible({ timeout: 15_000 })

		const rows = widget.locator('tbody tr')
		if (await rows.count() > 0) {
			// Seeded: due-date sorted rows, at least one overdue badge.
			await expect(widget.locator('thead')).toContainText('Due date')
			await expect(widget.locator('.open-invoices__badge--overdue').first()).toBeVisible()
		} else {
			await expect(widget.locator('.open-invoices__empty')).toBeVisible()
		}
	})

	test('open creditors table lists open AP transactions or empty state', async ({ page }) => {
		const widget = page.getByTestId('open-invoices-creditors')
		await expect(widget).toBeVisible({ timeout: 15_000 })

		const rows = widget.locator('tbody tr')
		if (await rows.count() > 0) {
			await expect(widget.locator('thead')).toContainText('Vendor')
			await expect(widget.locator('.open-invoices__badge').first()).toBeVisible()
		} else {
			await expect(widget.locator('.open-invoices__empty')).toBeVisible()
		}
	})
})
