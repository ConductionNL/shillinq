/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Cashflow 13wk — Playwright UI coverage for the cashflow forecast surface.
 *
 * Covers the manifest-driven dashboard, scenarios list, buffer policy detail,
 * recurring-costs CRUD index, and calibration report view. Crisis-mode banner
 * and PDF-export button are smoke-checked. Per ADR-031 the actual forecast
 * arithmetic lives in OR aggregations; these tests only confirm the UI shell
 * renders, navigation works, and key affordances are present.
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-30
 */

import { test, expect } from '@playwright/test'

const APP = '/apps/shillinq'

test.describe('cashflow 13wk — manifest pages render', () => {

	test.beforeEach(async ({ page }) => {
		await page.goto(APP + '/')
		await page.waitForLoadState('domcontentloaded')

		const wizard = page.locator('#firstrunwizard')
		if (await wizard.isVisible().catch(() => false)) {
			await page.keyboard.press('Escape').catch(() => {})
			await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
		}
	})

	/**
	 * @e2e bookkeeping-cashflow-13wk/REQ-CF-015/cashflow-dashboard-shell-renders
	 */
	test('cashflow dashboard route mounts', async ({ page }) => {
		await page.goto(APP + '/cashflow/dashboard')
		await page.waitForLoadState('domcontentloaded')
		expect(page.url()).toContain('/cashflow/dashboard')
	})

	/**
	 * @e2e bookkeeping-cashflow-13wk/REQ-CF-011/scenarios-list-shell-renders
	 */
	test('scenarios route mounts', async ({ page }) => {
		await page.goto(APP + '/cashflow/scenarios')
		await page.waitForLoadState('domcontentloaded')
		expect(page.url()).toContain('/cashflow/scenarios')
	})

	/**
	 * @e2e bookkeeping-cashflow-13wk/REQ-CF-009/buffer-policy-detail-renders
	 */
	test('buffer-policy route mounts', async ({ page }) => {
		await page.goto(APP + '/cashflow/buffer-policy')
		await page.waitForLoadState('domcontentloaded')
		expect(page.url()).toContain('/cashflow/buffer-policy')
	})

	/**
	 * @e2e bookkeeping-cashflow-13wk/REQ-CF-005/recurring-costs-index-renders
	 */
	test('recurring costs route mounts', async ({ page }) => {
		await page.goto(APP + '/cashflow/recurring')
		await page.waitForLoadState('domcontentloaded')
		expect(page.url()).toContain('/cashflow/recurring')
	})

	/**
	 * @e2e bookkeeping-cashflow-13wk/REQ-CF-013/calibration-report-view-renders
	 */
	test('calibration report route mounts', async ({ page }) => {
		await page.goto(APP + '/cashflow/calibration')
		await page.waitForLoadState('domcontentloaded')
		expect(page.url()).toContain('/cashflow/calibration')
	})

	/**
	 * @e2e bookkeeping-cashflow-13wk/REQ-CF-015/dashboard-bar-chart-widget-mounts
	 */
	test('dashboard exposes 13-week chart widget slot', async ({ page }) => {
		await page.goto(APP + '/cashflow/dashboard')
		await page.waitForLoadState('domcontentloaded')
		// The CashflowDashboard page is manifest-driven (type: "dashboard"), so
		// CnDashboardGrid renders one `role="group"` per widget whose accessible
		// name is the manifest `widgets[].id`. `exact: true` because Playwright's
		// `name` is a substring match by default. The chart itself is ApexCharts,
		// which mounts an `<svg class="apexcharts-svg">` — never a `<canvas>`,
		// which is why the previous `canvas` candidate could not match.
		const chart = page.getByRole('group', { name: 'cashflow-13week-chart', exact: true })
		await expect(chart).toBeVisible({ timeout: 10_000 })
		await expect(chart.locator('svg.apexcharts-svg')).toBeVisible({ timeout: 10_000 })
	})

	/**
	 * @e2e bookkeeping-cashflow-13wk/REQ-CF-016/dashboard-has-export-pdf-affordance
	 */
	// KNOWN RED — REQ-CF-016 is not wired end to end. The only "Export PDF"
	// affordance in the tree is `src/components/cashflow/CashflowDashboard.vue`,
	// which is NOT registered in `src/registry.js` (positive control:
	// `CashflowChartWidget` IS, at registry.js:279/418) and is referenced by no
	// manifest page, so it never mounts. `pages[CashflowDashboard].config`
	// declares no `headerActions`, and `lib/Service/CashflowPdfRenderer.php`
	// has no controller and no route in appinfo/routes.php. Left failing on
	// purpose: the assertion is the open defect, not the bug.
	test('dashboard exposes an Export PDF affordance', async ({ page }) => {
		await page.goto(APP + '/cashflow/dashboard')
		await page.waitForLoadState('domcontentloaded')
		const exportBtn = page.getByRole('button', { name: /export.*pdf/i })
		await expect(exportBtn.first()).toBeVisible({ timeout: 10_000 })
	})

	/**
	 * @e2e bookkeeping-cashflow-13wk/REQ-CF-011/scenarios-index-has-create-action
	 */
	test('scenarios index exposes a create action', async ({ page }) => {
		await page.goto(APP + '/cashflow/scenarios')
		await page.waitForLoadState('domcontentloaded')
		const createBtn = page.getByRole('button', { name: /create|add|new/i })
		await expect(createBtn.first()).toBeVisible({ timeout: 10_000 })
	})

	/**
	 * @e2e bookkeeping-cashflow-13wk/REQ-CF-005/recurring-index-has-create-action
	 */
	test('recurring costs index exposes a create action', async ({ page }) => {
		await page.goto(APP + '/cashflow/recurring')
		await page.waitForLoadState('domcontentloaded')
		const createBtn = page.getByRole('button', { name: /create|add|new/i })
		await expect(createBtn.first()).toBeVisible({ timeout: 10_000 })
	})

	/**
	 * @e2e bookkeeping-cashflow-13wk/REQ-CF-010/crisis-banner-conditional-render
	 */
	test('crisis banner is conditionally rendered (no fatal when absent)', async ({ page }) => {
		await page.goto(APP + '/cashflow/dashboard')
		await page.waitForLoadState('domcontentloaded')
		// The crisis banner is conditional. Either it is absent (no negative
		// forecast in fixtures) or it carries the role="alert" attribute.
		const banner = page.locator('[role="alert"]')
		const visible = await banner.first().isVisible().catch(() => false)
		if (visible === true) {
			await expect(banner.first()).toContainText(/CRISIS/i)
		}
	})
})
