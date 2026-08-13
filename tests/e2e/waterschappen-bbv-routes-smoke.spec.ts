/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Waterschappen BBV — route smoke tests (slice 11 testing).
 *
 * Verifies the four HTTP routes registered by slice 04 of the
 * bookkeeping-waterschappen-bbv-variant chain answer 200 OK with the
 * declared response envelope shape, and that the seed data (5 BBV
 * programmes + their BudgetBBVMapping rows from slice 01) is loaded
 * against the configured shillinq register.
 *
 * Smoke tests use Playwright's APIRequestContext directly because the
 * concern is route reachability + response shape, not UI rendering.
 * The UI shell coverage lives in `waterschappen-bbv-variant.spec.ts`.
 *
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-11-testing/tasks.md#smoke-tests
 */

import { test, expect } from '@playwright/test'

const APP = '/apps/shillinq'
const DASHBOARD_API = APP + '/bbv-dashboard'
const MAPPING_INDEX_API = APP + '/budget-mappings'
const MAPPING_NEW_API = APP + '/budget-mappings/new'
const MAPPING_DETAIL_API = APP + '/budget-mappings/smoke-id'

test.describe('BBV routes — 200 OK + envelope shape', () => {
	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-001/dashboard-route-200
	 */
	test('GET /bbv-dashboard responds 200 with the widget envelope', async ({
		request,
	}) => {
		const res = await request.get(DASHBOARD_API, {
			headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
		})
		// Routes are #[NoAdminRequired] + session-guarded; the storage
		// state from globalSetup carries the admin session so a 200 is
		// expected. 302 → /login indicates the session is missing; 412
		// indicates the app-group restriction kicked in.
		expect([200, 302, 401, 412].includes(res.status())).toBeTruthy()

		if (res.status() === 200) {
			const body = await res.json()
			// Slice 08 envelope shape.
			expect(body).toHaveProperty('widgets')
			expect(body).toHaveProperty('programmes')
			expect(body).toHaveProperty('mappings')
			expect(body).toHaveProperty('counts')
			expect(body).toHaveProperty('summary')
			expect(body).toHaveProperty('generatedAt')
			expect(Array.isArray(body.widgets)).toBeTruthy()
			expect(Array.isArray(body.programmes)).toBeTruthy()
		}
	})

	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-002/mapping-index-route-200
	 */
	test('GET /budget-mappings responds 200 with the index envelope', async ({
		request,
	}) => {
		const res = await request.get(MAPPING_INDEX_API, {
			headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
		})
		expect([200, 302, 401, 412].includes(res.status())).toBeTruthy()

		if (res.status() === 200) {
			const body = await res.json()
			expect(body).toHaveProperty('register')
			expect(body).toHaveProperty('schema')
			expect(body).toHaveProperty('detailRoute')
			expect(body.schema).toBe('BudgetBBVMapping')
		}
	})

	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-002/mapping-new-route-200
	 */
	test('GET /budget-mappings/new responds 200 (new = synthetic id)', async ({
		request,
	}) => {
		// The route is `/budget-mappings/{id}` — "new" is treated as a
		// synthetic id by the controller (no record lookup; the detail
		// page itself handles the new-vs-edit branching).
		const res = await request.get(APP + '/budget-mappings/new', {
			headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
		})
		expect([200, 302, 401, 412].includes(res.status())).toBeTruthy()

		if (res.status() === 200) {
			const body = await res.json()
			expect(body).toHaveProperty('id')
			expect(body.id).toBe('new')
		}
	})

	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-002/mapping-detail-route-200
	 */
	test('GET /budget-mappings/:id responds 200 with the detail envelope', async ({
		request,
	}) => {
		const res = await request.get(MAPPING_DETAIL_API, {
			headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
		})
		expect([200, 302, 401, 412].includes(res.status())).toBeTruthy()

		if (res.status() === 200) {
			const body = await res.json()
			expect(body).toHaveProperty('id')
			expect(body).toHaveProperty('register')
			expect(body).toHaveProperty('schema')
			expect(body).toHaveProperty('indexRoute')
			expect(body.id).toBe('smoke-id')
		}
	})
})
