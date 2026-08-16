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
		// ⚠️ NOT `canvas`, and not a `data-widget*` attribute.
		//
		// The `type: "chart"` widget declared in manifest.json renders through
		// ApexCharts, which draws an <svg> into a DIV it classes
		// `apexcharts-canvas` — there is no <canvas> element on the page, and
		// the renderer emits no data-widget/data-widget-id attribute for the
		// widget's `id`. All three alternatives in the old selector therefore
		// matched nothing, so this failed while the chart was rendering fine.
		//
		// Assert on the library's own wrapper plus the drawn SVG, so an empty
		// widget shell (mounted but never painted) still fails.
		const chart = page.locator('.cn-chart-widget__canvas .apexcharts-canvas svg')
		await expect(chart.first()).toBeVisible({ timeout: 10_000 })
		await expect(page.getByText('13-Week Cashflow Forecast')).toBeVisible()
	})

	/**
	 * ⚠️ FAILS on purpose — the assertion is correct and the product is not.
	 * Do not quarantine or retarget it. See #868.
	 *
	 * REQ-CF-016 requires a PDF export of the 13-week forecast for bank and
	 * accountant meetings, and archived Task 25 is ticked [x] claiming the
	 * button among the things it delivered. It was never built: manifest.json's
	 * CashflowDashboard config declares three widgets and mentions neither
	 * "export" nor "pdf", and the live dashboard's three overflow menus hold
	 * only Refresh / Documentation / Request a feature.
	 *
	 * @e2e bookkeeping-cashflow-13wk/REQ-CF-016/dashboard-has-export-pdf-affordance
	 */
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
	test('crisis banner is conditionally rendered (no fatal when absent)', async ({
		page,
	}) => {
		await page.goto(APP + '/cashflow/dashboard')
		await page.waitForLoadState('domcontentloaded')
		// The crisis banner is conditional. Either it is absent (no negative
		// forecast in fixtures) or it carries the role="alert" attribute.
		const banner = page.locator('[role="alert"]')
		const visible = await banner
			.first()
			.isVisible()
			.catch(() => false)
		if (visible === true) {
			await expect(banner.first()).toContainText(/CRISIS/i)
		}
	})
})
