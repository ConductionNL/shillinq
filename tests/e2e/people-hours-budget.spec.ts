/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A project manager sees the hours budget on the project page and finds the
 * projects over budget (people-hours-budget REQ-PHB-001, REQ-PHB-003).
 *
 * The register rows are answered through page.route with the state
 * AssignmentHoursListenerTest pins for Adviesbureau Van Dijk after a 6-hour
 * booking (96 of 120 hours, 80 percent), so this test proves the screen; the
 * sums, the flags and the warning are proven by AssignmentHoursListenerTest.
 *
 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md
 */

import { expect, test } from '@playwright/test'

const projectId = '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f'

const project = {
	id: projectId,
	code: 'P-2026-031',
	name: 'Herinrichting Wmo-loket',
	responsibleUser: 'j.devries',
	state: 'active',
	estimatedHoursTotal: 120,
	loggedHoursTotal: 96,
	remainingHoursTotal: 24,
	hoursOverBudget: false,
}

const assignment = {
	id: 'pa-bakker',
	projectId,
	personId: 'a.bakker',
	estimatedHours: 120,
	loggedHours: 96,
	remainingHours: 24,
	hoursUsedPercent: 80,
	state: 'active',
}

test.describe('people-hours-budget', () => {
	/**
	 * @e2e bookkeeping-consultancy-project-accounting::a-booking-updates-the-assignment
	 */
	test('the project page shows 96 of 120 hours and 80 percent used', async ({
		page,
	}) => {
		await page.route(
			`**/api/objects/shillinq/engagement/${projectId}**`,
			(route) => route.fulfill({ json: project }),
		)
		await page.route('**/api/objects/shillinq/ProjectAssignment**', (route) =>
			route.fulfill({
				json: { results: [assignment], total: 1, page: 1, pages: 1 },
			}),
		)
		await page.goto(
			`/index.php/apps/shillinq/bookkeeping/dimensions/projects/${projectId}`,
		)

		await expect(page.getByText('Hours budget')).toBeVisible()
		const row = page.getByRole('row', { name: /a\.bakker/ })
		await expect(row).toContainText('96')
		await expect(row).toContainText('80')
	})

	/**
	 * @e2e bookkeeping-consultancy-project-accounting::a-manager-lists-projects-over-budget
	 */
	test('the projects list filters on over budget', async ({ page }) => {
		const asked: string[] = []
		await page.route('**/api/objects/shillinq/engagement**', (route) => {
			asked.push(route.request().url())
			return route.fulfill({
				json: {
					results: [
						{ ...project, hoursOverBudget: true, loggedHoursTotal: 125 },
					],
					total: 1,
					page: 1,
					pages: 1,
				},
			})
		})
		await page.goto(
			'/index.php/apps/shillinq/bookkeeping/dimensions/projects?hoursOverBudget=true',
		)

		await expect(
			page.getByRole('row', { name: /Herinrichting Wmo-loket/ }),
		).toBeVisible()
		expect(asked.some((url) => url.includes('hoursOverBudget'))).toBe(true)
	})
})
