/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — shillinq bookkeeping-vpb-corporate-tax
 * quarterly tax statement SPA smoke (REQ-VPB-009, REQ-VPB-010, REQ-VPB-011,
 * REQ-VPB-012).
 *
 * The change ships a quarterly statement view at
 * `/bookkeeping/vpb/reports/:fiscalYear/:quarter` (CnDetailPage backed by
 * `TaxReportController::getQuarterlyStatement()`) plus an annual summary
 * roll-up of Q1–Q4 with variance against provisional payments. The reports
 * route is registered by the manifest fragment behind the same Vpb menu
 * group as deadlines / payments.
 *
 * This smoke confirms the SPA mounts on the quarterly + annual report
 * routes and never leaves the shillinq URL surface. The behavioural
 * acceptance — REQ-VPB-009 quarterly aggregation correctness, REQ-VPB-010
 * untagged-posting warning surfacing on tax-relevant accounts, REQ-VPB-011
 * Excel/PDF export through CnMassExportDialog, REQ-VPB-012 annual summary
 * variance against provisional payments — is @e2e excluded here: live
 * UI exercise requires a running OpenRegister with the
 * bookkeeping-vpb-corporate-tax register fragment imported, seeded GL
 * postings spanning a fiscal quarter with mixed `taxTreatment` tags
 * (normal / deductible / nonDeductible / special), a paired TaxDeadline
 * + TaxPaymentTracking dataset for the variance roll-up, and a working
 * ExportService binding for the Excel/PDF download path — none of which
 * the build sandbox provides. The behavioural acceptance is covered by
 * the PHPUnit suites authored under Task 41 (TaxReportControllerTest,
 * TaxReportCalculatorTest, TaxReportServiceTest — assertions over
 * aggregation, account-hierarchy grouping, untagged-posting flagging,
 * and net-taxable-income computation).
 *
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-43
 */

import { test, expect } from '@playwright/test'

const APP = '/apps/shillinq'

const dismissWizard = async (
	page: import('@playwright/test').Page,
): Promise<void> => {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('shillinq — bookkeeping-vpb-corporate-tax quarterly statement SPA smoke', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1280, height: 800 })
	})

	test('Quarterly statement index — mounts on /bookkeeping/vpb/reports (REQ-VPB-009)', async ({
		page,
	}) => {
		await page.goto(APP + '/bookkeeping/vpb/reports')
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		// SPA route resolved by the manifest shell — with no GL postings yet
		// the page renders an empty quarterly-statement state; the
		// bounce-out guard is the behaviour under test for Gate-19.
		expect(page.url()).toContain('/apps/shillinq')
		await expect(page).toHaveTitle(/shillinq/i, { timeout: 15_000 })
	})

	test('Quarterly statement detail — mounts on /bookkeeping/vpb/reports/:year/:quarter (REQ-VPB-009)', async ({
		page,
	}) => {
		// Detail route is parameterised by fiscal year + quarter. With no
		// fixture loaded the page renders an empty aggregation state with
		// account-hierarchy headers; the SPA route must resolve and the
		// shillinq title must persist.
		await page.goto(APP + '/bookkeeping/vpb/reports/2026/1')
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		expect(page.url()).toContain('/apps/shillinq')
		await expect(page).toHaveTitle(/shillinq/i, { timeout: 15_000 })
	})

	test('Vpb settings — mounts on /bookkeeping/vpb/settings (REQ-VPB-014, REQ-VPB-015)', async ({
		page,
	}) => {
		// The settings page hosts deadline-template configuration plus the
		// tax-treatment tag configuration that drives the untagged-posting
		// warning on the quarterly report. Smoke: route resolves, SPA
		// stays mounted under /apps/shillinq.
		await page.goto(APP + '/bookkeeping/vpb/settings')
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		expect(page.url()).toContain('/apps/shillinq')
		await expect(page).toHaveTitle(/shillinq/i, { timeout: 15_000 })
	})
})
