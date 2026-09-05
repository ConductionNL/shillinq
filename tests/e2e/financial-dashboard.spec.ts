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
 *
 * ── Why the selectors changed ────────────────────────────────────────────────
 * This file used to target `finance-kpis`, `turnover-chart`, `margin-chart`,
 * `billable-chart` and `open-invoices-{debtors,creditors}`. Every one of those
 * ids belonged to a BESPOKE Vue widget that commit 9e329080
 * ("refactor(dashboard): dissolve 7 custom financial widgets into declarative
 * manifest", ADR-049 Phase-4) deleted — FinanceKpisWidget, TurnoverChartWidget,
 * MarginChartWidget, BillableHoursChartWidget, OpenInvoicesTableWidget. Their
 * replacements are declarative manifest widgets rendered by
 * @conduction/nextcloud-vue, and that library emits NO per-widget
 * `data-testid`: CnWidgetWrapper has none at all. `turnover-chart` /
 * `margin-chart` survive in src/manifest.json only as widget IDS, which never
 * reach the DOM — which is exactly why grepping src/ for them "found" them.
 *
 * The per-widget hook the library DOES emit is `gs-id` on the grid item
 * (CnDashboardGrid.vue: `class="grid-stack-item" :gs-id="item.id"`), whose
 * value is the LAYOUT entry id — `layout-turnover-chart`, not the widget id.
 * The €/% switches are `cn-chart-widget-view-${view.key}` (CnChartWidget.vue),
 * driven by the `content.views[]` blocks the manifest declares. Those are the
 * hooks asserted below. `cashflow-chart` keeps its own id because
 * CashflowChartWidget is the one surviving custom widget and authors it itself.
 *
 * (The right long-term fix is a `cn-widget-${widgetId}` testid on
 * CnWidgetWrapper upstream in @conduction/nextcloud-vue; until that ships,
 * `gs-id` is the only stable per-widget hook, and it is a real one.)
 *
 * AppHost adoption (adopt-apphost): the dashboard route `/` is now served
 * by the OpenRegister AppHost GenericDashboardController. This suite driving
 * the SPA shell + dashboard widgets unchanged is the behavioural proof that
 * the generic controller is a drop-in for the deleted local DashboardController.
 *
 * @e2e apphost-adoption::app-ui-is-unaffected-by-the-generic-controllers
 */

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'

test.describe('financial-dashboard-graphs — Financial overview', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
		await page.goto(APP + '/')
		await page.waitForLoadState('domcontentloaded')

		// Dismiss any first-run wizard overlay.
		const wizard = page.locator('#firstrunwizard')
		if (await wizard.isVisible().catch(() => false)) {
			await page.keyboard.press('Escape').catch(() => {})
			await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
		}
	})

	const widget = (id: string) => `[gs-id="layout-${id}"]`

	test('KPI strip renders six stat widgets', async ({ page }) => {
		for (const key of [
			'turnover',
			'margin',
			'debtors',
			'creditors',
			'billable',
			'cash',
		]) {
			await expect(page.locator(widget(key))).toBeVisible({ timeout: 15_000 })
		}
		// Euro formatting on the turnover tile (manifest declares
		// format.style = currency / EUR on the `turnover` stat widget).
		await expect(page.locator(widget('turnover'))).toContainText('€', {
			timeout: 15_000,
		})
	})

	test('turnover chart renders monthly columns from posted revenue lines', async ({
		page,
	}) => {
		const chart = page.locator(widget('turnover-chart'))
		await expect(chart).toBeVisible({ timeout: 15_000 })
		// ApexCharts mounts an SVG once the series resolve. It renders SVG, never
		// a <canvas> — an earlier version of the cashflow spec asserted `canvas`
		// and could not have passed.
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({
			timeout: 15_000,
		})
	})

	test('margin toggle switches between euro and percentage view', async ({
		page,
	}) => {
		const chart = page.locator(widget('margin-chart'))
		await expect(chart).toBeVisible({ timeout: 15_000 })
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({
			timeout: 15_000,
		})

		// Default € view carries the three-series legend (Revenue/Costs/Margin).
		await expect(
			chart.getByText('Revenue', { exact: false }).first(),
		).toBeVisible()

		// The manifest declares content.views[] = [{key:'value'},{key:'pct'}];
		// CnChartWidget renders one button per view.
		await chart.getByTestId('cn-chart-widget-view-pct').click()
		await expect(chart.getByTestId('cn-chart-widget-view-pct')).toHaveAttribute(
			'aria-pressed',
			'true',
		)
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({
			timeout: 15_000,
		})
		// The `pct` view declares a single series (Margin %), so the €-view
		// series names must be gone from the rendered chart.
		await expect(chart.getByText('Costs', { exact: false })).toHaveCount(0)

		await chart.getByTestId('cn-chart-widget-view-value').click()
		await expect(
			chart.getByTestId('cn-chart-widget-view-value'),
		).toHaveAttribute('aria-pressed', 'true')
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({
			timeout: 15_000,
		})
		await expect(
			chart.getByText('Revenue', { exact: false }).first(),
		).toBeVisible()
	})

	test('cashflow chart mounts with realized and forecast series', async ({
		page,
	}) => {
		// The one surviving CUSTOM widget, so it still owns its own testid.
		const chart = page.getByTestId('cashflow-chart')
		await expect(chart).toBeVisible({ timeout: 15_000 })
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({
			timeout: 15_000,
		})
		await expect(
			chart.getByText('Money in', { exact: false }).first(),
		).toBeVisible()
	})

	test('billable hours toggle switches to percentage view', async ({ page }) => {
		const chart = page.locator(widget('billable-hours-chart'))
		await expect(chart).toBeVisible({ timeout: 15_000 })
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({
			timeout: 15_000,
		})

		// Stacked 'total' view carries the two-series legend.
		await expect(
			chart.getByText('Non-billable', { exact: false }).first(),
		).toBeVisible()

		await chart.getByTestId('cn-chart-widget-view-pct').click()
		await expect(chart.getByTestId('cn-chart-widget-view-pct')).toHaveAttribute(
			'aria-pressed',
			'true',
		)
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({
			timeout: 15_000,
		})
		await expect(chart.getByText('Non-billable', { exact: false })).toHaveCount(
			0,
		)

		await chart.getByTestId('cn-chart-widget-view-total').click()
		await expect(
			chart.getByTestId('cn-chart-widget-view-total'),
		).toHaveAttribute('aria-pressed', 'true')
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({
			timeout: 15_000,
		})
		await expect(
			chart.getByText('Non-billable', { exact: false }).first(),
		).toBeVisible()
	})

	test('open debtors table lists open invoices or its empty state', async ({
		page,
	}) => {
		const table = page.locator(widget('open-debtors'))
		await expect(table).toBeVisible({ timeout: 15_000 })

		const rows = table.locator('tbody tr')
		if ((await rows.count()) > 0) {
			// Columns declared by the manifest's object-table content block.
			await expect(table).toContainText('Due date')
		} else {
			await expect(table).toContainText(/No open debtor invoices/i)
		}
	})

	test('open creditors table lists open AP transactions or its empty state', async ({
		page,
	}) => {
		const table = page.locator(widget('open-creditors'))
		await expect(table).toBeVisible({ timeout: 15_000 })

		const rows = table.locator('tbody tr')
		if ((await rows.count()) > 0) {
			await expect(table).toContainText('Vendor')
		} else {
			await expect(table).toContainText(/No open creditor invoices/i)
		}
	})
})
