/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Subject cost endpoint, end to end.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The subject-cost-aggregation capability shipped with no way to call it.
 * `SubjectCostAggregator` and `HrmqCostRateAdapter` were implemented,
 * spec-tagged and unit-tested, and no route, service or listener reached
 * either one. Every scenario in the spec was tagged `@e2e exclude ... asserted
 * by PHPUnit`, so no end-to-end run could notice there was no endpoint at all.
 *
 * These tests are the part PHPUnit cannot assert: that the route is registered,
 * that the controller resolves through the container with its real
 * collaborators, and that OpenRegister answers a filtered read of
 * UrenRegistratie. A unit test passes with all three of those broken.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not the cost. hrmq is not installed on the CI stack, so no wage rate
 * resolves and the aggregator does exactly what its first requirement says:
 * withholds the total, reports `complete: false`, and names the unpriced
 * person. The assertions below therefore check HOURS, which are computed by
 * Shillinq alone, and check that the refusal is the documented one rather than
 * an error. Pricing arithmetic is asserted in SubjectCostAggregatorTest.
 *
 * @spec openspec/specs/subject-cost-aggregation/spec.md#requirement-a-subject-cost-is-reachable-over-http
 * @e2e subject-cost-aggregation/requirement-a-subject-cost-is-reachable-over-http/the-endpoint-answers-for-a-subject-with-booked-hours
 * @e2e subject-cost-aggregation/requirement-a-subject-cost-is-reachable-over-http/a-request-without-a-subject-is-refused
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const HOURS_BASE =
	'/index.php/apps/openregister/api/objects/shillinq/UrenRegistratie'
const SUBJECT_COST = '/index.php/apps/shillinq/api/subject-cost'
const HEADERS = { 'OCS-APIRequest': 'true' }

/**
 * A subject id unique to this run, so a leftover row from an earlier run
 * cannot make a broken read look like a working one.
 */
const SUBJECT_ID = `case-e2e-${Date.now()}-${Math.floor(Math.random() * 10_000)}`
const SUBJECT_APP = 'dossiq'
/**
 * `ADM-001` is the administration first-time setup creates and ci-seed.sh gives
 * the admin user its only AdministrationMembership in. A fixture booked into
 * any other administration is correctly invisible to a scoped read, which is
 * the trap this constant exists to avoid: the endpoint answers 200 with zero
 * hours, and that reads as a broken query rather than as the scope rule
 * working.
 */
const ADMINISTRATION_ID = 'ADM-001'

/**
 * A second administration the caller is NOT a member of, for the scoping
 * assertion below.
 */
const OTHER_ADMINISTRATION_ID = 'adm-e2e-subject-cost-other'
const PERSON_ID = 'person-e2e-subject-cost'

/**
 * Book hours against the run's subject.
 *
 * @param request The API context.
 * @param hours The hours to book.
 * @param administrationId The administration to book them into.
 *
 * @returns The created object's id.
 */
async function bookHours(
	request: APIRequestContext,
	hours: number,
	administrationId: string = ADMINISTRATION_ID,
): Promise<string> {
	const created = await request.post(HOURS_BASE, {
		headers: HEADERS,
		data: {
			administrationId,
			personId: PERSON_ID,
			date: '2026-03-02',
			hours,
			description: 'subject-cost endpoint e2e fixture',
			subjectApp: SUBJECT_APP,
			subjectId: SUBJECT_ID,
		},
	})
	expect(
		created.ok(),
		`booking an hour row must succeed, got HTTP ${created.status()}: ${await created.text()}`,
	).toBeTruthy()
	const body = await created.json()
	const id = body?.id ?? body?.['@self']?.id
	expect(id, 'the booked hour row must come back with an id').toBeTruthy()
	return String(id)
}

test.describe('subject cost endpoint', () => {
	const created: string[] = []

	test.afterAll(async ({ request }) => {
		for (const id of created) {
			const deleted = await request.delete(`${HOURS_BASE}/${id}`, {
				headers: HEADERS,
			})
			if (deleted.ok() === false && deleted.status() !== 404) {
				console.warn(
					`[subject-cost] could not clean up hour row ${id}: HTTP ${deleted.status()}`,
				)
			}
		}
	})

	test('the endpoint answers for a subject with booked hours', async ({
		request,
	}) => {
		created.push(await bookHours(request, 2))
		created.push(await bookHours(request, 1.5))

		const response = await request.get(SUBJECT_COST, {
			headers: HEADERS,
			params: {
				subjectApp: SUBJECT_APP,
				subjectId: SUBJECT_ID,
				administrationId: ADMINISTRATION_ID,
			},
		})

		expect(
			response.status(),
			`the route must be registered and reachable, got: ${await response.text()}`,
		).toBe(200)

		const body = await response.json()
		expect(body.subjectApp).toBe(SUBJECT_APP)
		expect(body.subjectId).toBe(SUBJECT_ID)
		expect(body.hours).toBe(3.5)
		// hrmq is absent here, so the documented refusal is the correct answer.
		// Asserted rather than ignored: a cost appearing out of nowhere would
		// mean a rate was invented, which is the one thing the capability
		// exists to prevent.
		expect(body.complete).toBe(false)
		expect(body.costCents).toBeNull()
		expect(body.unpricedPersonIds).toContain(PERSON_ID)
	})

	test('hours in another administration stay out of a scoped read', async ({
		request,
	}) => {
		// Booked as the admin, who may write anywhere, then read back with the
		// scope narrowed to ADM-001. Without the narrowing the Nextcloud-admin
		// bypass would (correctly) include both, so this is the one shape in
		// which an admin-run suite can assert the scope rule at all.
		created.push(await bookHours(request, 8, OTHER_ADMINISTRATION_ID))

		const scoped = await request.get(SUBJECT_COST, {
			headers: HEADERS,
			params: {
				subjectApp: SUBJECT_APP,
				subjectId: SUBJECT_ID,
				administrationId: ADMINISTRATION_ID,
			},
		})
		expect(scoped.status()).toBe(200)
		expect(
			(await scoped.json()).hours,
			'the other administration\'s eight hours must not be counted',
		).toBe(3.5)

		const unscoped = await request.get(SUBJECT_COST, {
			headers: HEADERS,
			params: { subjectApp: SUBJECT_APP, subjectId: SUBJECT_ID },
		})
		expect(unscoped.status()).toBe(200)
		expect(
			(await unscoped.json()).hours,
			'a Nextcloud admin reads every administration',
		).toBe(11.5)
	})

	test('a subject with no booked hours reports zero, not an error', async ({
		request,
	}) => {
		const response = await request.get(SUBJECT_COST, {
			headers: HEADERS,
			params: { subjectApp: SUBJECT_APP, subjectId: `${SUBJECT_ID}-absent` },
		})

		expect(response.status()).toBe(200)
		const body = await response.json()
		expect(body.hours).toBe(0)
		expect(body.unscopedRowsExcluded).toBe(0)
	})

	test('a request without a subject is refused', async ({ request }) => {
		const response = await request.get(SUBJECT_COST, {
			headers: HEADERS,
			params: { subjectApp: SUBJECT_APP },
		})

		expect(response.status()).toBe(400)
		const body = await response.json()
		expect(body.error).toContain('required')
	})
})
