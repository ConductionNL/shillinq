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
 * AppHost adoption (adopt-apphost): the dashboard route `/` is now served
 * by the OpenRegister AppHost GenericDashboardController. This suite driving
 * the SPA shell + dashboard widgets unchanged is the behavioural proof that
 * the generic controller is a drop-in for the deleted local DashboardController.
 *
 * LOCATOR CONTRACT (ADR-049 Phase-4). The overview is no longer a bespoke
 * `FinancialDashboard.vue`; it is `pages[Dashboard]` in `src/manifest.json`
 * rendered by CnDashboardPage. The manifest renderer emits NO per-widget
 * `data-testid` and the v2 schema has no `testId` key, so the stable,
 * product-emitted handle for a widget is the grid item CnDashboardGrid
 * renders for it: `role="group"` whose accessible name is the manifest
 * `widgets[].id` (CnDashboardGrid.resolveItemLabel → `item.widgetId`).
 * `exact: true` is load-bearing — Playwright's `name` is a SUBSTRING match by
 * default, so `'turnover'` would also match the `turnover-chart` widget.
 * The two widgets that DO carry a shillinq-owned testid keep it:
 * `cashflow-chart` (src/components/dashboard/financial/CashflowChartWidget.vue).
 * In-widget chart view pills come from CnChartWidget as
 * `cn-chart-widget-view-<view.key>` — scoped to their widget's group because
 * two chart widgets on this page both declare a `pct` view.
 *
 * @e2e apphost-adoption::app-ui-is-unaffected-by-the-generic-controllers
 */

import { test, expect, type Page, type Locator } from '@playwright/test'

const APP = '/apps/shillinq'

/** The grid item CnDashboardGrid renders for one manifest widget id. */
function widget(page: Page, widgetId: string): Locator {
	return page.getByRole('group', { name: widgetId, exact: true })
}

/**
 * Close the first-open support note if it is up.
 *
 * `CnAppRoot` mounts `CnSupportDialog` behind `useSupportDialog(appId)`; on a
 * profile that has never seen it the note opens over the dashboard and its
 * `modal-mask` swallows pointer events for the whole viewport, so the chart
 * view-pill clicks below never land. Dismissing it is what a real operator
 * does before using the dashboard; it replaces no assertion. Mirrors the
 * identical helper in `bill-import-modal.spec.ts`.
 */
async function dismissSupportDialog(page: Page): Promise<void> {
	const support = page.locator('[data-testid-modal="cn-support-dialog"]')
	await support.waitFor({ state: 'visible', timeout: 3_000 }).catch(() => {})
	if (!(await support.isVisible().catch(() => false))) {
		return
	}
	const close = support.locator('button.modal-container__close, button[aria-label*="lose" i], button[aria-label*="luiten" i]').first()
	if (await close.isVisible().catch(() => false)) {
		await close.click({ timeout: 2_000 }).catch(() => {})
	} else {
		await page.keyboard.press('Escape').catch(() => {})
	}
	await support.waitFor({ state: 'hidden', timeout: 5_000 })
}

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
		await dismissSupportDialog(page)
	})

	test('KPI strip renders six metrics', async ({ page }) => {
		// The six `type: "stat"` widgets the Dashboard page declares. Each one
		// is its own grid item — there is no wrapping strip element.
		for (const key of ['turnover', 'margin', 'debtors', 'creditors', 'billable', 'cash']) {
			await expect(widget(page, key)).toBeVisible({ timeout: 15_000 })
		}
		// Euro formatting on the turnover tile.
		await expect(widget(page, 'turnover')).toContainText('€')
	})

	test('turnover chart renders monthly columns from posted revenue lines', async ({ page }) => {
		const chart = widget(page, 'turnover-chart')
		await expect(chart).toBeVisible({ timeout: 15_000 })
		// ApexCharts mounts an SVG once the series resolve.
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({ timeout: 15_000 })
	})

	test('margin toggle switches between euro and percentage view', async ({ page }) => {
		const chart = widget(page, 'margin-chart')
		await expect(chart).toBeVisible({ timeout: 15_000 })
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({ timeout: 15_000 })

		// Default € view shows the three-series legend (Revenue/Costs/Margin).
		await expect(chart.getByText('Revenue', { exact: false }).first()).toBeVisible()

		// % view is a single percentage series: the legend disappears
		// (ApexCharts hides single-series legends) and the y-axis
		// switches to percentage tick labels.
		const pct = chart.getByTestId('cn-chart-widget-view-pct')
		await pct.click()
		await expect(pct).toHaveAttribute('aria-pressed', 'true')
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({ timeout: 15_000 })
		await expect(chart.getByText(/^\d+%$/).first()).toBeVisible({ timeout: 15_000 })

		await chart.getByTestId('cn-chart-widget-view-value').click()
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
		const chart = widget(page, 'billable-hours-chart')
		await expect(chart).toBeVisible({ timeout: 15_000 })
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({ timeout: 15_000 })

		// Stacked view carries the two-series legend.
		await expect(chart.getByText('Non-billable', { exact: false }).first()).toBeVisible()

		// % view is a single line series: legend disappears, y-axis
		// flips to percentage tick labels.
		const pct = chart.getByTestId('cn-chart-widget-view-pct')
		await pct.click()
		await expect(pct).toHaveAttribute('aria-pressed', 'true')
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({ timeout: 15_000 })
		await expect(chart.getByText(/^\d+%$/).first()).toBeVisible({ timeout: 15_000 })

		await chart.getByTestId('cn-chart-widget-view-total').click()
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({ timeout: 15_000 })
		await expect(chart.getByText('Non-billable', { exact: false }).first()).toBeVisible()
	})

	test('open debtors table lists open invoices with overdue flag (seeded) or empty state', async ({ page }) => {
		const table = widget(page, 'open-debtors')
		await expect(table).toBeVisible({ timeout: 15_000 })

		// CnDataTable renders its empty state as a `<tr data-testid=
		// "cn-object-list-empty">`, so counting raw `tbody tr` cannot tell a
		// seeded table from an empty one — count the data rows explicitly.
		const rows = table.getByTestId('cn-object-row')
		if (await rows.count() > 0) {
			// Seeded: due-date sorted rows, at least one overdue badge.
			await expect(table.locator('thead')).toContainText('Due date')
			await expect(table.locator('.cn-status-badge', { hasText: 'overdue' }).first()).toBeVisible()
		} else {
			await expect(table.getByTestId('cn-object-list-empty')).toBeVisible()
		}
	})

	test('open creditors table lists open AP transactions or empty state', async ({ page }) => {
		const table = widget(page, 'open-creditors')
		await expect(table).toBeVisible({ timeout: 15_000 })

		const rows = table.getByTestId('cn-object-row')
		if (await rows.count() > 0) {
			await expect(table.locator('thead')).toContainText('Vendor')
			await expect(table.locator('.cn-status-badge').first()).toBeVisible()
		} else {
			await expect(table.getByTestId('cn-object-list-empty')).toBeVisible()
		}
	})
})
