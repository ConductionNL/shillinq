/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a closed dossiq tenant month becomes a draft invoice
 * (billable-period-becomes-an-invoice task 1.5, REQ-UMB-005).
 *
 * The live pass of the change, as far as a test can drive it:
 *
 *   1. a customer of the test's own administration carries the dossiq tenant
 *      id as its `externalReference` (`E2E_DOSSIQ_TENANT_ID`, or a fresh id),
 *      and OpenRegister's filter on that field, the lookup
 *      BillablePeriodInvoiceService::resolveCustomer() runs, finds exactly that
 *      one customer;
 *   2. before any run, shillinq holds no TimeIntakeBatch keyed
 *      `dossiq:<tenant>:<period>` and no BillableInvoice for the customer, so a
 *      later run's invoice can only be the run's own;
 *   3. dossiq's monthly invoicing for the tenant, its repeat, and the run after
 *      the reference is removed are a test.fixme (below).
 *
 * Why the run itself is a fixme: dossiq raises BillablePeriodClosedEvent in
 * the same PHP process from its monthly invoicing, and shillinq answers it with
 * BillablePeriodClosedListener. Shillinq has no route for the event (by design,
 * ADR-041: the target app defines a typed in-process event), and nothing in
 * this repository names the dossiq route or job that runs the monthly
 * invoicing, so the test cannot start it or raise the event as dossiq would.
 * When it runs, the outcome to check in shillinq is one draft BillableInvoice
 * for the customer with one usage line per billable event, a TimeIntakeBatch
 * `dossiq:<tenant>:<period>` with status `invoiced` naming that invoice, one
 * MeterReading per line with meterId `dossiq:<tenant>`, the same invoice on a
 * second run, and, with the reference removed, a refusal dossiq records naming
 * the tenant id.
 *
 * The test skips when dossiq (or its old id procest) is not enabled. Every
 * object written here is removed afterwards.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no live instance).
 *
 * @spec openspec/changes/billable-period-becomes-an-invoice/specs/usage-metered-billing/spec.md
 * @spec openspec/changes/billable-period-becomes-an-invoice/tasks.md
 *
 * @e2e usage-metered-billing::a-tenant-month-becomes-a-draft-invoice
 * @e2e usage-metered-billing::the-same-month-twice
 * @e2e usage-metered-billing::no-customer-carries-the-tenant
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMINISTRATION_ID = `${UNIQUE_PREFIX}-saas`
const TENANT_ID = process.env.E2E_DOSSIQ_TENANT_ID ?? randomUUID()
const PERIOD = process.env.E2E_DOSSIQ_BILLING_PERIOD ?? '2026-10'

test.describe('billable-period-becomes-an-invoice: a dossiq tenant month becomes a draft invoice', () => {
	let api: APIRequestContext
	let fx: OrFixtures

	/**
	 * Whether one of an app's ids is enabled: the provisioning list first, a
	 * probe of the app's own page when that list cannot be read.
	 *
	 * @param ids The app's ids, newest first (FleetAppId's candidates).
	 * @return True when one of them is enabled.
	 */
	async function appEnabled(ids: string[]): Promise<boolean> {
		const res = await api.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json', { headers: { 'OCS-APIRequest': 'true' } })
		const body = res.ok() ? await res.json().catch(() => ({})) : {}
		const enabled = new Set<string>((body?.ocs?.data?.apps ?? []) as string[])
		for (const id of ids) {
			if (enabled.size > 0 ? enabled.has(id) : (await api.get(`/index.php/apps/${id}/`, { maxRedirects: 0 })).status() === 200) {
				return true
			}
		}
		return false
	}

	/**
	 * The objects of a schema whose fields equal the filters.
	 *
	 * @param schema  The schema slug.
	 * @param filters Field filters.
	 * @return The objects.
	 */
	async function list(schema: string, filters: Record<string, string>): Promise<Array<Record<string, unknown>>> {
		const query = new URLSearchParams({ ...filters, _limit: '50' })
		const res = await api.get(`${OBJECTS}/${schema}?${query.toString()}`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(res.ok(), `list ${schema}: HTTP ${res.status()}`).toBeTruthy()
		const body = await res.json()
		const rows = (body.results ?? body.items ?? []) as Array<Record<string, unknown>>
		// Keep only exact matches, whatever the list route does with an unknown filter.
		return rows.filter((row) => Object.entries(filters).every(([field, value]) => String(row[field] ?? '') === value))
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
	})

	test.afterAll(async () => {
		await fx?.cleanup()
		await api?.dispose()
	})

	test('a customer carrying the dossiq tenant id is the one the closed month is drafted for @spec REQ-UMB-005', async () => {
		test.skip(!(await appEnabled(['dossiq', 'procest'])), 'dossiq is not enabled on this instance; it is the app that raises BillablePeriodClosedEvent')
		const missing = await fx.missingSchema(['AdministrationMembership', 'CustomerMaster', 'BillableInvoice', 'MeterReading', 'UsageRatePlan', 'TimeIntakeBatch'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)
		test.skip(
			(await list('CustomerMaster', { externalReference: TENANT_ID })).length > 0,
			`a customer of this instance already carries ${TENANT_ID}; the listener would refuse a second one`,
		)

		// 1. The customer that carries the tenant id, in an administration of its own.
		await fx.create('AdministrationMembership', {
			userId: ADMIN_USER,
			administrationId: ADMINISTRATION_ID,
			role: 'controller',
			mayPostJournalEntries: true,
			validFrom: '2024-01-01',
			validUntil: null,
		})
		const customerNumber = `${UNIQUE_PREFIX}-TENANT`
		const { id: customerId } = await fx.create('CustomerMaster', {
			customerId: customerNumber,
			legalName: `Tenant ${UNIQUE_PREFIX}`,
			email: `tenant-${UNIQUE_PREFIX}@example.org`,
			administrationId: ADMINISTRATION_ID,
			lifecycleState: 'active',
			externalReference: TENANT_ID,
		})
		const carriers = await list('CustomerMaster', { externalReference: TENANT_ID })
		expect(carriers, 'exactly one customer carries the tenant id').toHaveLength(1)
		expect(String((carriers[0]['@self'] as Record<string, unknown> | undefined)?.id ?? carriers[0].id)).toBe(customerId)
		expect(carriers[0].administrationId).toBe(ADMINISTRATION_ID)
		expect(carriers[0].customerId).toBe(customerNumber)

		// 2. Nothing is drafted for the month yet.
		expect(await list('TimeIntakeBatch', { administrationId: ADMINISTRATION_ID, batchId: `dossiq:${TENANT_ID}:${PERIOD}` })).toHaveLength(0)
		expect(await list('BillableInvoice', { customerId: customerNumber })).toHaveLength(0)
		expect(await list('MeterReading', { meterId: `dossiq:${TENANT_ID}` })).toHaveLength(0)

		// 3. The run itself.
		test.fixme(true, 'dossiq raises BillablePeriodClosedEvent in-process from its monthly invoicing; shillinq has no route for the event and this repo names no dossiq route or job that runs the invoicing, so the run, its repeat and the refusal after the reference is removed cannot be started from the test')
	})
})
