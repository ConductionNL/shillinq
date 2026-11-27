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
 *   3. three usage events of the tenant in the month (dossiq's
 *      `tenantBillingEvent`), and dossiq's monthly invoicing for them,
 *      `POST /index.php/apps/dossiq/api/saas/tenants/{tenant}/billing/{month}/run`
 *      (TenantBillingController::run, admin): dossiq raises
 *      BillablePeriodClosedEvent in-process and shillinq's
 *      BillablePeriodClosedListener answers it. The outcome is one draft usage
 *      BillableInvoice for the customer with one line per event, a
 *      TimeIntakeBatch `dossiq:<tenant>:<period>` `invoiced` naming that
 *      invoice and one MeterReading per event with meterId `dossiq:<tenant>`;
 *   4. the same month again (its events un-stamped, so dossiq sends the same
 *      lines): the same invoice, and still one for the customer;
 *   5. with the reference removed from the customer: dossiq answers 409 with
 *      shillinq's refusal naming the tenant id and leaves the events unbilled.
 *
 * The test skips when dossiq (or its old id procest) is not enabled. Every
 * object written here is removed afterwards.
 *
 * Written 24 Nov 2026, runs in the nightly. Run live 26 Nov 2026 on a sandbox
 * Nextcloud 34 + PostgreSQL + OpenRegister main with dossiq development.
 *
 * @spec openspec/changes/archive/2026-11-26-billable-period-becomes-an-invoice/specs/usage-metered-billing/spec.md
 * @spec openspec/changes/archive/2026-11-26-billable-period-becomes-an-invoice/tasks.md
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
const DOSSIQ_OBJECTS = '/index.php/apps/openregister/api/objects/dossiq'
const JSON_HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
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

		// 3. Three billable events of the tenant in the month, and dossiq's run.
		const eventIds: string[] = []
		const drafted: Array<{ schema: string, id: string }> = []
		const run = async () => await api.post(`/index.php/apps/dossiq/api/saas/tenants/${TENANT_ID}/billing/${PERIOD}/run`, { headers: JSON_HEADERS })
		const unstamp = async () => {
			for (const id of eventIds) {
				const res = await api.patch(`${DOSSIQ_OBJECTS}/tenantBillingEvent/${id}`, { headers: JSON_HEADERS, data: { invoiceRef: null } })
				expect(res.ok(), `un-stamp ${id}: HTTP ${res.status()}`).toBeTruthy()
			}
		}
		try {
			for (const eventType of ['case_created', 'case_created', 'case_closed']) {
				const res = await api.post(`${DOSSIQ_OBJECTS}/tenantBillingEvent`, {
					headers: JSON_HEADERS,
					data: { tenantRef: TENANT_ID, eventType, quantity: 1, unitPrice: 2.5, currency: 'EUR', occurredAt: `${PERIOD}-15T10:00:00+00:00`, invoiceRef: null },
				})
				test.skip(!res.ok(), `dossiq's tenantBillingEvent cannot be written here: HTTP ${res.status()}`)
				eventIds.push(String((await res.json()).id))
			}

			const first = await run()
			const firstBody = await first.text()
			expect(first.status(), firstBody).toBe(200)
			const invoiceRef = String(JSON.parse(firstBody).invoice?.invoiceRef ?? '')
			expect(invoiceRef, 'dossiq records the invoice shillinq drafted').not.toBe('')

			const invoices = await list('BillableInvoice', { customerId: customerNumber })
			expect(invoices, 'one draft invoice for the customer').toHaveLength(1)
			const invoice = invoices[0]
			drafted.push({ schema: 'BillableInvoice', id: String(invoice.id) })
			expect(String(invoice.id)).toBe(invoiceRef)
			expect(invoice.status).toBe('draft')
			expect(invoice.billingModel).toBe('usage')
			expect((invoice.lineItemsByModel as Record<string, { count: number, totalCents: number }>).usage, 'one usage line per billable event').toEqual({ count: 3, totalCents: 750 })
			const batches = await list('TimeIntakeBatch', { administrationId: ADMINISTRATION_ID, batchId: `dossiq:${TENANT_ID}:${PERIOD}` })
			expect(batches).toHaveLength(1)
			drafted.push({ schema: 'TimeIntakeBatch', id: String(batches[0].id) })
			expect(batches[0].status).toBe('invoiced')
			expect(batches[0].invoiceId).toBe(invoiceRef)
			const readings = await list('MeterReading', { meterId: `dossiq:${TENANT_ID}` })
			drafted.push(...readings.map((reading) => ({ schema: 'MeterReading', id: String(reading.id) })))
			expect(readings, 'one meter reading per event').toHaveLength(3)

			// 4. The same month again: dossiq stamped the events, so they are
			// un-stamped to make it send the same lines; shillinq answers with the
			// invoice it drafted.
			await unstamp()
			const again = await run()
			const againBody = await again.text()
			expect(again.status(), againBody).toBe(200)
			expect(JSON.parse(againBody).invoice?.invoiceRef, 'the same invoice').toBe(invoiceRef)
			expect(await list('BillableInvoice', { customerId: customerNumber }), 'still one invoice').toHaveLength(1)

			// 5. No customer carries the tenant any more: shillinq refuses, dossiq
			// says so and leaves the month unbilled.
			const removed = await api.patch(`${OBJECTS}/CustomerMaster/${customerId}`, { headers: JSON_HEADERS, data: { externalReference: null } })
			expect(removed.ok(), `remove the reference: HTTP ${removed.status()}`).toBeTruthy()
			await unstamp()
			const refused = await run()
			const refusedBody = await refused.text()
			expect(refused.status(), refusedBody).toBe(409)
			expect(String(JSON.parse(refusedBody).error), 'the refusal names the tenant').toContain(TENANT_ID)
			expect(JSON.parse(refusedBody).invoice?.exported).toBe(false)
		} finally {
			for (const { schema, id } of drafted.reverse()) {
				await fx.remove(schema, id)
			}
			for (const id of eventIds) {
				await api.delete(`${DOSSIQ_OBJECTS}/tenantBillingEvent/${id}`, { headers: { 'OCS-APIRequest': 'true' } })
			}
		}
	})
})
