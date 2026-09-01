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

import { expect, test } from '@playwright/test'

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

		// ⚠️ THE PREVIOUS LOCATOR COULD NOT MATCH ANYTHING THIS PAGE RENDERS.
		// It looked for `[data-widget="cashflow-13week-chart"]`, `canvas`, or
		// `[data-widget-id=…]`. All three are wrong:
		//
		//  - `data-widget` appears in exactly one place in the whole app,
		//    `src/components/cashflow/CashflowDashboard.vue`, which is an
		//    ORPHAN: it is imported by nothing, registered in no registry entry,
		//    and bound as no manifest page's `component`. The test was written
		//    against a component that never mounts.
		//  - @conduction/nextcloud-vue emits NO `data-widget` /
		//    `data-widget-id` attribute anywhere (grep: zero hits). Manifest
		//    dashboards render through CnDashboardPage → CnDashboardGrid, whose
		//    grid items are `.grid-stack-item[gs-id="<layout entry id>"]`.
		//  - `canvas` is the wrong element: CnChartWidget renders through
		//    vue3-apexcharts, and ApexCharts draws **SVG**, never a `<canvas>`.
		//
		// So assert what the manifest actually declares and the library
		// actually emits: the dashboard page mounts, and the grid item for the
		// `cashflow-13week-chart` widget (layout entry `layout-chart` in
		// src/manifest.json) is present with the widget's own title.
		await expect(page.getByTestId('cn-dashboard-page')).toBeVisible({
			timeout: 15_000,
		})

		const chartItem = page.locator('.grid-stack-item[gs-id="layout-chart"]')
		await expect(chartItem).toBeVisible({ timeout: 15_000 })

		// The widget body resolved to something real: either the rendered
		// ApexCharts SVG, or CnChartWidget's own honest empty state when the
		// `bufferPolicyEvaluation` aggregation returns no rows on a bare CI
		// instance. A blank grid cell is neither and still fails.
		const chartBody = chartItem.locator(
			'.cn-chart-widget svg, [data-testid="cn-chart-widget-empty"]',
		)
		await expect(chartBody.first()).toBeVisible({ timeout: 15_000 })
	})

	/**
	 * @e2e bookkeeping-cashflow-13wk/REQ-CF-016/dashboard-has-export-pdf-affordance
	 */
	/**
	 * ⚠️ THIS TEST FAILS, AND THAT IS THE SPEC WORKING — see #865.
	 *
	 * REQ-CF-016 ("PDF export for bank/accountant meetings") is a CURRENT
	 * requirement in `openspec/specs/bookkeeping-cashflow-13wk/spec.md`, and it
	 * is unimplemented end to end:
	 *
	 *   - `lib/Service/CashflowPdfRenderer.php` exists and `render()` has ZERO
	 *     callers anywhere in `lib/`;
	 *   - `appinfo/routes.php` registers no cashflow export route at all;
	 *   - the only "Export PDF" button in the codebase lives in
	 *     `src/components/cashflow/CashflowDashboard.vue`, which is an ORPHAN
	 *     component (no import, no registry entry, no manifest binding), and
	 *     even if it mounted its click only `$emit`s `export-pdf` — nothing
	 *     listens.
	 *   - `/cashflow/dashboard` declares no `headerActions` in
	 *     `src/manifest.json`, so no export affordance is rendered.
	 *
	 * Left asserting on purpose. Quarantining it, or relaxing it to "a button
	 * exists somewhere", would convert a missing statutory-reporting capability
	 * into an invisible one — the exact failure mode the fleet's L8 rule
	 * forbids. Either the capability gets built or REQ-CF-016 gets withdrawn
	 * from the spec; a green column must not be the third option.
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
