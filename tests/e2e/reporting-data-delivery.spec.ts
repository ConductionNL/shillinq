/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A controller schedules the monthly budget report from the report dialog
 * (reporting-data-delivery REQ-RDD-001).
 *
 * The catalogue, the administration context and the ReportSchedule create are
 * answered through page.route, so the test proves the screen and the exact
 * schedule it posts. That the posted schedule validates against the register
 * is proven by ReportSchedulePayloadTest; the run itself by
 * ScheduledReportRunnerTest.
 *
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 */

import { expect, test } from '@playwright/test'

test.describe('reporting-data-delivery', () => {
	/**
	 * @e2e report-delivery::the-monthly-budget-report-is-scheduled
	 */
	test('a controller schedules the monthly budget report', async ({ page }) => {
		const posted: Record<string, unknown>[] = []
		await page.route('**/apps/shillinq/api/reporting/types', (route) =>
			route.fulfill({
				json: {
					types: [
						{
							id: 'budget-vs-actual',
							label: 'Budget versus realisatie',
							category: 'statements',
							formats: ['pdf'],
						},
					],
				},
			}),
		)
		await page.route('**/apps/shillinq/api/administrations/context', (route) =>
			route.fulfill({
				json: {
					activeAdministrationId: 'adm-voorbeeld',
					administrations: [
						{
							administrationId: 'adm-voorbeeld',
							name: 'Gemeente Voorbeeld',
						},
					],
				},
			}),
		)
		await page.route(
			'**/apps/openregister/api/objects/shillinq/ReportSchedule',
			(route) => {
				const body = route.request().postDataJSON()
				posted.push(body)
				return route.fulfill({ json: { id: 'sched-1', ...body } })
			},
		)

		await page.goto('/index.php/apps/shillinq/reporting-compliance')
		await page.getByTestId('reporting-generate-budget-vs-actual').click()
		await page.getByTestId('generate-report-dialog-schedule').click()
		await page.getByLabel('Frequency').selectOption('monthly')
		await page.getByLabel('Day of the month').fill('5')
		await page.getByLabel('Period').last().selectOption('previous-period')
		await page.getByLabel('Recipients').fill('group:controllers')
		await page.getByLabel('Folder').fill('/Rapportages/Maand')
		await page.getByTestId('generate-report-dialog-save-schedule').click()

		await expect(page.getByTestId('generate-report-scheduled')).toBeVisible()
		expect(posted).toHaveLength(1)
		expect(posted[0]).toMatchObject({
			reportType: 'budget-vs-actual',
			format: 'pdf',
			frequency: 'monthly',
			runDay: 5,
			periodRule: 'previous-period',
			recipients: ['group:controllers'],
			folderPath: '/Rapportages/Maand',
			status: 'active',
		})
		expect(String(posted[0].nextRunAt)).toMatch(/-05T00:00:00/)
	})
})
