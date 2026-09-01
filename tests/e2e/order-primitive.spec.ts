/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright e2e — abstract-order-primitive (#503).
 *
 * Exercises the order-primitive scenarios that are observable end-to-end
 * through the OpenRegister object + lifecycle-transition API on a live
 * instance:
 *
 *   1. a subsidy is an Order of type subsidy          (create orderType=subsidy)
 *   2. a purchase order is an Order of type purchase     (create orderType=purchase)
 *   3. a DBA engagement is an Order of type engagement   (create orderType=engagement)
 *   4. subsidy keeps its statutory lifecycle            (verleen: aanvraag -> verleend)
 *   5. a transition never crosses orderType boundaries   (approve on a subsidy -> refused)
 *
 * The remaining three scenarios are backend fold / occ-command behaviour that
 * a browser cannot drive, and are covered by their own live/occ + unit e2e:
 *   - "migration is lossless"                    -> FoldIntoOrder live run (#503/#381) + FoldIntoOrderTest
 *   - "money units are normalised ..."           -> FoldIntoOrder purchase path (cent->EUR, live) + FoldIntoOrderTest
 *   - "the audit command detects unmigrated rows"-> `occ shillinq:orders:audit` live run (#388) + OrdersAuditCommandTest
 *
 * Every object created here carries the run-unique prefix and is deleted in
 * afterAll — the shared instance is left exactly as found.
 *
 * @spec openspec/changes/abstract-order-primitive/specs/order-primitive/spec.md
 *
 * Gate-19 scenario traceability (order-primitive spec):
 * @e2e order-primitive::a-subsidy-is-an-order-of-type-subsidy
 * @e2e order-primitive::a-purchase-order-is-an-order-of-type-purchase
 * @e2e order-primitive::a-dba-engagement-is-an-order-of-type-engagement
 * @e2e order-primitive::subsidy-keeps-its-statutory-lifecycle
 * @e2e order-primitive::a-transition-never-crosses-ordertype-boundaries
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const SCHEMA = 'OrderPrimitive'
const ADMIN_ID = 'ADM-001'

test.describe('order-primitive — Order fold + orderType-gated lifecycle (#503)', () => {
	// ── This block used to be `{ mode: 'serial', timeout: 180_000, retries: 3 }`
	// and all three parts were wrong ON CI. Its own comment supplied the
	// disproof: "on a fresh CI instance the whole spec runs in well under a
	// minute". Measured on run 31073204125 (E2E job 92525589609), where all five
	// tests here PASSED on their first attempt:
	//
	//     a subsidie is an Order of type subsidie              1.6s
	//     a purchase order is an Order of type purchase        1.0s
	//     a DBA engagement is an Order of type engagement      1.1s
	//     subsidie keeps its statutory lifecycle               1.9s
	//     a transition never crosses orderType boundaries      1.3s
	//
	// `retries: 3` — a PER-FILE override silently defeats the repo-level
	//   `retries: 0` that playwright.config.ts argues for at length. Nothing
	//   here needed a retry (slowest first-attempt pass: 1.9s), and a retry
	//   budget that is never exercised is indistinguishable from one that is
	//   quietly converting a real intermittent failure into a green. The 18-24s
	//   creates it was written for are a SHARED dev instance's contention; CI
	//   gets its own instance.
	// `timeout: 180_000` — 95x the slowest passing test. Dropped entirely so the
	//   file inherits the config's 60_000, which is still ~31x. This is a CUT,
	//   justified by the measurement above; it is never raised.
	// `mode: 'serial'` — bought nothing and cost four tests their verdict on any
	//   single failure. These five share no state: each creates its OWN object
	//   with its own `${UNIQUE_PREFIX}-*` orderNumber and asserts only about
	//   that object; `beforeAll` shares an API *context*, not ordering.
	//   Removing it adds no concurrency either — playwright.config.ts sets
	//   `fullyParallel: false`, so a file's tests still run one at a time in
	//   declaration order on a single worker. Same reasoning as the header of
	//   tests/e2e/spec-coverage/_helpers.ts.
	//
	// If the shared dev instance is genuinely too slow for these locally, that
	// is an argument for seeding differently there — not for a retry budget that
	// can manufacture green in CI.

	let fx: OrFixtures
	let api: APIRequestContext

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({
			baseURL,
			storageState: 'tests/e2e/.auth/admin.json',
		})
		fx = new OrFixtures(api)
	})

	test.afterAll(async () => {
		await fx?.cleanup()
		await api?.dispose()
	})

	// A successful create is itself the proof that OrderPrimitive is imported as
	// its own schema (create 404s when the schema is absent) — and that it is NOT
	// decidesk's `order`, since these subsidy/engagement shapes would fail
	// decidesk's required fields (orderNumber/orderDate/orderStatus/totalPrice).
	test('a subsidy is an Order of type subsidy @spec REQ-ORD-001', async () => {
		const { id } = await fx.create(SCHEMA, {
			administrationId: ADMIN_ID,
			orderType: 'subsidy',
			direction: 'outgoing',
			orderNumber: `${UNIQUE_PREFIX}-SUB`,
			state: 'request',
		})
		const obj = await fx.get(SCHEMA, id)
		expect(obj.orderType).toBe('subsidy')
	})

	test('a purchase order is an Order of type purchase @spec REQ-ORD-001', async () => {
		const { id } = await fx.create(SCHEMA, {
			administrationId: ADMIN_ID,
			orderType: 'purchase',
			direction: 'incoming',
			orderNumber: `${UNIQUE_PREFIX}-PO`,
			state: 'draft',
		})
		const obj = await fx.get(SCHEMA, id)
		expect(obj.orderType).toBe('purchase')
	})

	test('a DBA engagement is an Order of type engagement @spec REQ-ORD-001', async () => {
		const { id } = await fx.create(SCHEMA, {
			administrationId: ADMIN_ID,
			orderType: 'engagement',
			direction: 'incoming',
			orderNumber: `${UNIQUE_PREFIX}-DBA`,
			state: 'DRAFT',
		})
		const obj = await fx.get(SCHEMA, id)
		expect(obj.orderType).toBe('engagement')
	})

	test('subsidy keeps its statutory lifecycle (verleen: aanvraag → verleend) @spec REQ-ORD-002', async () => {
		const { id } = await fx.create(SCHEMA, {
			administrationId: ADMIN_ID,
			orderType: 'subsidy',
			direction: 'outgoing',
			orderNumber: `${UNIQUE_PREFIX}-SUB-LIFECYCLE`,
			state: 'request',
		})

		const res = await fx.transition(id, 'verleen')
		expect(
			res.ok(),
			`verleen should be allowed on a subsidy in aanvraag: HTTP ${res.status()} ${await res.text()}`,
		).toBeTruthy()

		const after = await fx.get(SCHEMA, id)
		const state =
			(after['@self'] as Record<string, unknown> | undefined)?.state
			?? after.state
		expect(state).toBe('granted')
	})

	test('a transition never crosses orderType boundaries (approve refused on a subsidy) @spec REQ-ORD-002', async () => {
		const { id } = await fx.create(SCHEMA, {
			administrationId: ADMIN_ID,
			orderType: 'subsidy',
			direction: 'outgoing',
			orderNumber: `${UNIQUE_PREFIX}-SUB-CROSS`,
			state: 'request',
		})

		// `approve` is a PURCHASE-only transition (from=draft). It must be refused
		// on a subsidy in aanvraag — the orderType gate (REQ-ORD-002). The refusal
		// itself is the contract under test.
		const res = await fx.transition(id, 'approve')
		expect(
			res.ok(),
			'a purchase transition must NOT succeed on a subsidy',
		).toBeFalsy()
	})
})
