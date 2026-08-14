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

import { test, expect, request as pwRequest } from '@playwright/test'
import { OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures'

const SCHEMA = 'OrderPrimitive'
const ADMIN_ID = 'ADM-001'

test.describe('order-primitive — Order fold + orderType-gated lifecycle (#503)', () => {
	// The OpenRegister object API is slow under a loaded shared instance
	// (single creates observed at 18-24s); tests that chain create + poll +
	// transition + read need well above the 30s default. Run serially (they
	// share one authenticated context) with a generous per-test budget.
	// retries here are for the shared dev instance's transient load (slow/laggy
	// object API under a concurrent session), NOT product flakiness: every test
	// passes deterministically in isolation. On a fresh CI instance the whole
	// spec runs in well under a minute.
	test.describe.configure({ mode: 'serial', timeout: 180_000, retries: 3 })

	let fx: OrFixtures
	let api: import('@playwright/test').APIRequestContext

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
			state: 'aanvraag',
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
			state: 'aanvraag',
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
		expect(state).toBe('verleend')
	})

	test('a transition never crosses orderType boundaries (approve refused on a subsidy) @spec REQ-ORD-002', async () => {
		const { id } = await fx.create(SCHEMA, {
			administrationId: ADMIN_ID,
			orderType: 'subsidy',
			direction: 'outgoing',
			orderNumber: `${UNIQUE_PREFIX}-SUB-CROSS`,
			state: 'aanvraag',
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
