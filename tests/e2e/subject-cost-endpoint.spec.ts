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
 * either one. Every scenario in the spec was excluded from e2e in favour of
 * PHPUnit, so no end-to-end run could notice there was no endpoint at all.
 * (The exclusion marker is deliberately not spelled out here: a tag named in
 * prose is still a tag to the parser that scans this file.)
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

const HOURS_BASE = '/index.php/apps/openregister/api/objects/humaniq/TimeEntry'
const SUBJECT_COST = '/index.php/apps/shillinq/api/subject-cost'
const HEADERS = { 'OCS-APIRequest': 'true' }

/**
 * A subject id unique to this run, so a leftover row from an earlier run
 * cannot make a broken read look like a working one.
 */
const SUBJECT_ID = `case-e2e-${Date.now()}-${Math.floor(Math.random() * 10_000)}`
const SUBJECT_APP = 'dossiq'

/**
 * An employee id to book against, and the administration humaniq will stamp
 * onto the row from that employee.
 *
 * Resolved from the register rather than invented. `employeeId` is
 * format-checked as a uuid, and `TimeEntryStampListener` copies
 * `administrationId` FROM THE EMPLOYEE, so a made-up id yields a row with no
 * administration, which the endpoint then correctly refuses to attribute. That
 * failure looks exactly like a broken query.
 */
let employeeId = ''
let administrationId = ''

/**
 * A fresh, non-overlapping start for each booked row.
 *
 * Each call advances the clock so two rows never share a window: humaniq
 * refuses impossible spans and derives hours from them, so overlapping
 * fixtures are both a refusal risk and an unreadable total.
 *
 * @returns An ISO-8601 start stamp.
 */
let spanCursor = 8
function spanStart(): string {
	return `2026-03-02T${String(spanCursor).padStart(2, '0')}:00:00+00:00`
}

/**
 * The end of the current span, `hours` after its start, advancing the cursor.
 *
 * @param hours The intended length in hours.
 *
 * @returns An ISO-8601 end stamp.
 */
function spanEnd(hours: number): string {
	const end = spanCursor + hours
	const h = Math.floor(end)
	const m = Math.round((end - h) * 60)
	// Advance to the next WHOLE hour. A fractional cursor formatted straight
	// into the stamp produced `T13.5:00:00`, which OpenRegister coerced to
	// null and reported as a type error on a field the fixture had plainly
	// set.
	spanCursor = Math.ceil(end) + 1
	return `2026-03-02T${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:00+00:00`
}

/**
 * Book hours against the run's subject.
 *
 * `startedAt` and `endedAt` are REQUIRED by the effective merged schema, even
 * though the fragment that declares the property set lists `required: []`.
 * Omitting them is a 400 that never reaches the endpoint under test.
 *
 * The span, not the number, decides the hours. humaniq's
 * TimeEntryStampListener DERIVES `hours` from `startedAt`/`endedAt` and
 * ignores whatever the client sent, so two rows written with one fixed span
 * both come back as that span's length. Sending 2 and 1.5 with a shared
 * 09:00-11:00 window yielded a total of 4, not 3.5, and read as a broken
 * aggregate rather than as the writer doing its job.
 *
 * @param request The API context.
 * @param hours The hours to book.
 * @param type The `<app>:<schema>` literal to book under.
 * @param employee The employee to book for.
 *
 * @returns The created object's id.
 */
async function bookHours(
	request: APIRequestContext,
	hours: number,
	type: string = `${SUBJECT_APP}:case`,
	employee: string = '',
): Promise<string> {
	const created = await request.post(HOURS_BASE, {
		headers: HEADERS,
		data: {
			employeeId: employee !== '' ? employee : employeeId,
			hours,
			description: 'subject-cost endpoint e2e fixture',
			domainObjectType: type,
			domainObjectRef: SUBJECT_ID,
			startedAt: spanStart(),
			endedAt: spanEnd(hours),
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

/**
 * Whether humaniq is installed on the instance under test.
 *
 * `format=json` is not optional. Without it OCS answers XML, the app name
 * comes back as `<element>humaniq</element>`, and a grep for the JSON spelling
 * silently matches nothing, reporting "not installed" on an instance where it
 * plainly is.
 *
 * @param request The API context.
 *
 * @returns True when humaniq is enabled.
 */
async function humaniqInstalled(request: APIRequestContext): Promise<boolean> {
	const res = await request.get(
		'/ocs/v2.php/cloud/apps?filter=enabled&format=json',
		{ headers: { ...HEADERS, Accept: 'application/json' } },
	)
	if (res.ok() === false) {
		return false
	}
	return (await res.text()).includes('"humaniq"')
}

test.describe('subject cost endpoint', () => {
	let humaniq = false
	const created: string[] = []

	test.beforeAll(async ({ request }) => {
		humaniq = await humaniqInstalled(request)
		if (humaniq === false) {
			return
		}

		const res = await request.get(
			'/index.php/apps/openregister/api/objects/humaniq/Employee?_limit=1',
			{ headers: HEADERS },
		)
		if (res.ok() === false) {
			return
		}
		const row = ((await res.json())?.results ?? [])[0] ?? {}
		employeeId = String(row.id ?? '')
		administrationId = String(row.administrationId ?? '')
	})

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

	test('hours booked through humaniq are aggregated for the subject', async ({
		request,
	}) => {
		if (humaniq === false) {
			// Assert the DOCUMENTED degradation rather than skipping. shillinq
			// does not declare humaniq as a dependency, so "no hours store" is
			// a supported state and the endpoint must answer 200 with zero,
			// never 500. A skip would report green while proving nothing about
			// the branch this instance actually runs, and shillinq's own CI
			// stack installs openregister only.
			const degraded = await request.get(SUBJECT_COST, {
				headers: HEADERS,
				params: { subjectApp: SUBJECT_APP, subjectId: SUBJECT_ID },
			})
			expect(degraded.status()).toBe(200)
			expect((await degraded.json()).hours).toBe(0)
			return
		}

		expect(
			employeeId,
			'an employee must be resolvable, or the stamped administration is empty and every row is refused',
		).not.toBe('')

		created.push(await bookHours(request, 2))
		created.push(await bookHours(request, 1.5))

		const response = await request.get(SUBJECT_COST, {
			headers: HEADERS,
			params: {
				subjectApp: SUBJECT_APP,
				subjectId: SUBJECT_ID,
				administrationId,
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
		expect(body.unscopedRowsExcluded).toBe(0)
		// A cost appearing here would mean a rate was invented. Whether one
		// resolves depends on the employee having a priced contract, so the
		// assertion is on the SHAPE: either a complete total, or the
		// documented refusal that names who could not be priced.
		if (body.complete === false) {
			expect(body.costCents).toBeNull()
			expect(body.unpricedPersonIds).toContain(employeeId)
		} else {
			expect(typeof body.costCents).toBe('number')
		}
	})

	test('hours booked against another app are not this subject', async ({
		request,
	}) => {
		test.skip(humaniq === false, 'no hours store without humaniq')

		// Same uuid, different owning app. Filtering on domainObjectRef alone
		// would count these, which is almost always the same answer and
		// occasionally, silently, not.
		created.push(await bookHours(request, 4, 'planninq:project'))

		const response = await request.get(SUBJECT_COST, {
			headers: HEADERS,
			params: {
				subjectApp: SUBJECT_APP,
				subjectId: SUBJECT_ID,
				administrationId,
			},
		})

		expect(response.status()).toBe(200)
		expect(
			(await response.json()).hours,
			"planninq's four hours are not this case's",
		).toBe(3.5)
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
