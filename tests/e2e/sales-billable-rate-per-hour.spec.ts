/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a billable rate per hour (sales-billable-rate-per-hour
 * task 5.1, REQ-BRPH-003 and REQ-BRPH-005).
 *
 * The seed of design.md: project "Renovatie Kade 12" with rate card
 * "Standaard 2026" (role senior EUR 120, user Alice EUR 130, client EUR 95)
 * and the October 2026 hours of Alice (6 h), Bram (4.5 h), Chris (2 h) and
 * Dana (1 h, no assignment).
 *
 *   1. a project manager sees what October is worth: the Billable hours card
 *      on the project page lists four rows and totals 12.5 rated unbilled
 *      hours worth EUR 1,510 and 1 hour without a rate (REQ-BRPH-003);
 *   2. without an hours source the card says so: when the endpoint answers
 *      `available: false`, the card shows the not-connected line, never a
 *      zero (REQ-BRPH-003);
 *   3. two people on one invoice keep their own rates: drafting a time and
 *      material invoice from Alice's and Bram's hours gives lines totalling
 *      EUR 1,320 with rateApplied 130 and 120 (REQ-BRPH-005).
 *
 * Test 3 writes a draft invoice, so it runs only while those hours are still
 * unbilled, and skips otherwise. Every test skips when the seed project is
 * not on the instance. The caller is the suite's administrator
 * (globalSetup's storage state).
 *
 * Written, not run: it needs a live instance with an hours source.
 *
 * @spec openspec/changes/sales-billable-rate-per-hour/specs/rate-card-management/spec.md
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-5.1
 *
 * @e2e rate-card-management::a-project-manager-sees-what-october-is-worth
 * @e2e rate-card-management::without-an-hours-source-the-card-says-so
 * @e2e rate-card-management::two-people-on-one-invoice-keep-their-own-rates
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const APP = '/apps/shillinq'
const API = '/index.php/apps/shillinq/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const HEADERS = { 'OCS-APIRequest': 'true' }
const PROJECT_NAME = 'Renovatie Kade 12'
const MONTH = '2026-10'

/** One row of the billable hours answer. */
interface HourRow {
	id: string
	person: string
	personId?: string
	date: string
	hours: number
	status: string
	rate?: number
	tier?: string
	value?: number
	invoiceNumber?: string | null
}

/** The billable hours answer of one project month. */
interface BillableHours {
	available: boolean
	reason?: string
	month: string
	rows: HourRow[]
	totals: {
		hours: number
		unbilledHours: number
		unbilledValue: number
		unratedHours: number
	}
}

/**
 * The seed project's id, or null when it is not on the instance.
 *
 * @param request The API context.
 * @return The id, or null.
 */
async function findProject(request: APIRequestContext): Promise<string | null> {
	const response = await request.get(`${OBJECTS}/engagement`, {
		headers: HEADERS,
		params: { _search: PROJECT_NAME, _limit: '20' },
	})
	if (!response.ok()) {
		return null
	}
	const body = await response.json()
	const results: Array<{ id?: string; uuid?: string; name?: string }> =
		body.results ?? []
	const project = results.find((candidate) => candidate.name === PROJECT_NAME)
	return project ? String(project.id ?? project.uuid ?? '') || null : null
}

/**
 * The project's billable hours of the seed month.
 *
 * @param request The API context.
 * @param projectId The project.
 * @return The answer.
 */
async function billableHours(
	request: APIRequestContext,
	projectId: string,
): Promise<BillableHours> {
	const response = await request.get(
		`${API}/v1/projects/${encodeURIComponent(projectId)}/billable-hours`,
		{
			headers: HEADERS,
			params: { month: MONTH },
		},
	)
	expect(response.status()).toBe(200)
	return response.json()
}

test.describe('sales-billable-rate-per-hour', () => {
	test.beforeAll(() => {
		resolveBaseURL()
	})

	test('a project manager sees what October is worth', async ({
		page,
		request,
	}) => {
		const projectId = await findProject(request)
		test.skip(
			projectId === null,
			`the seed project ${PROJECT_NAME} is not on this instance`,
		)

		const answer = await billableHours(request, projectId as string)
		test.skip(
			answer.available !== true,
			'no hours source is connected on this instance',
		)
		test.skip(
			answer.rows.some((row) => row.invoiceNumber),
			'some October hours are already invoiced, so the unbilled totals differ from the seed',
		)

		expect(answer.rows).toHaveLength(4)
		expect(answer.totals.unbilledHours).toBeCloseTo(12.5, 2)
		expect(answer.totals.unbilledValue).toBeCloseTo(1510, 2)
		expect(answer.totals.unratedHours).toBeCloseTo(1, 2)

		await page.goto(
			`${APP}/bookkeeping/dimensions/projects/${encodeURIComponent(projectId as string)}`,
		)
		const card = page.getByTestId('project-billable-hours')
		await expect(card).toBeVisible()
		await card.getByTestId('pbh-month').fill(MONTH)
		await expect(card.getByTestId('pbh-row')).toHaveCount(4)
		const totals = card.getByTestId('pbh-totals')
		await expect(totals).toContainText('12.5')
		await expect(totals).toContainText(/1[.,]?510/)
		await expect(
			card.getByTestId('pbh-row').filter({ hasText: 'Dana' }),
		).toContainText('No rate')
	})

	test('without an hours source the card says so', async ({ page, request }) => {
		const projectId = await findProject(request)
		test.skip(
			projectId === null,
			`the seed project ${PROJECT_NAME} is not on this instance`,
		)

		await page.route('**/api/v1/projects/*/billable-hours**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					available: false,
					reason: 'hours-source-unavailable',
					month: MONTH,
					rows: [],
					totals: {
						hours: 0,
						unbilledHours: 0,
						unbilledValue: 0,
						unratedHours: 0,
					},
				}),
			}),
		)
		await page.goto(
			`${APP}/bookkeeping/dimensions/projects/${encodeURIComponent(projectId as string)}`,
		)
		const card = page.getByTestId('project-billable-hours')
		await expect(card.getByTestId('pbh-unavailable')).toBeVisible()
		await expect(card.getByTestId('pbh-totals')).toHaveCount(0)
	})

	test('two people on one invoice keep their own rates', async ({ request }) => {
		const projectId = await findProject(request)
		test.skip(
			projectId === null,
			`the seed project ${PROJECT_NAME} is not on this instance`,
		)

		const answer = await billableHours(request, projectId as string)
		test.skip(
			answer.available !== true,
			'no hours source is connected on this instance',
		)
		const chosen = answer.rows.filter((row) =>
			/^(Alice|Bram)\b/.test(row.person),
		)
		test.skip(
			chosen.length === 0 || chosen.some((row) => row.invoiceNumber),
			'the hours of Alice and Bram are not all unbilled',
		)

		const project = await (
			await request.get(
				`${OBJECTS}/engagement/${encodeURIComponent(projectId as string)}`,
				{ headers: HEADERS },
			)
		).json()
		const response = await request.post(`${API}/v1/invoices/generate`, {
			headers: HEADERS,
			data: {
				billingModel: 't_and_m',
				customerId: project.customerId ?? project.customer ?? '',
				fromDate: `${MONTH}-01`,
				toDate: `${MONTH}-31`,
				timeEntryIds: chosen.map((row) => row.id),
				expenseIds: [],
				meterReadingIds: [],
				projectId,
			},
		})
		expect(response.status()).toBeLessThan(300)
		const draft = await response.json()
		const lines: Array<{
			rateApplied?: number
			amount?: number
			total?: number
		}> = draft.lines ?? []
		const rates = lines
			.map((line) => Number(line.rateApplied))
			.sort((a, b) => a - b)
		expect(rates).toEqual(expect.arrayContaining([120, 130]))
		const total = lines.reduce(
			(sum, line) => sum + Number(line.amount ?? line.total ?? 0),
			0,
		)
		expect(total).toBeCloseTo(1320, 2)
	})
})
