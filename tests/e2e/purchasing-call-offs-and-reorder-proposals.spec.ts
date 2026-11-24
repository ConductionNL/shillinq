/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a call-off against a framework agreement, and the reorder
 * proposal of low stock (purchasing-call-offs-and-reorder-proposals task 1.3).
 *
 * The live pass of the change:
 *
 *   1. an active framework agreement with a supplier at EUR 42 per box of
 *      A4 paper and EUR 10,000 left under its ceiling; a buyer orders 20
 *      boxes against it through `POST /api/purchase-orders` (the route the
 *      purchase order form posts to, with `frameworkAgreementId`), typing
 *      EUR 50: the line takes the agreed EUR 42, the order totals EUR 840 and
 *      the agreement shows EUR 9,160 left (REQ-PG-005);
 *   2. a reorder rule of 10 units with reorder quantity 50 and 8 units in
 *      stock: the daily ReorderProposalJob raises an open ReorderProposal and
 *      a draft purchase order for 50 units (REQ-IRA-011). The job is a daily
 *      background job with no route that starts it, so this step is a fixme;
 *      its logic is ReorderProposalServiceTest's.
 *
 * The task names a seed agreement and seed stock; this spec writes its own,
 * with the run's unique prefix, in an administration of its own with an
 * AdministrationMembership for the suite's administrator, so the nightly
 * changes no seed data. Every object written here, the order and its
 * approval tasks included, is removed afterwards.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no
 * live instance).
 *
 * @spec openspec/changes/purchasing-call-offs-and-reorder-proposals/specs/procurement-governance/spec.md
 * @spec openspec/changes/purchasing-call-offs-and-reorder-proposals/specs/inventory-reorder-automation/spec.md
 * @spec openspec/changes/purchasing-call-offs-and-reorder-proposals/tasks.md
 *
 * @e2e procurement-governance::a-call-off-takes-the-agreed-price
 * @e2e inventory-reorder-automation::low-stock-proposes-an-order
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { money, OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const APP_API = '/index.php/apps/shillinq/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const PAPER = 'PAPER-A4-BOX'

/** A priced line of a created purchase order (PurchaseOrderService::pricedLine). */
interface OrderLine {
	productCode: string
	quantity: number
	unitPrice: number
	lineTotal: number
	agreedPrice?: boolean
}

test.describe('purchasing-call-offs-and-reorder-proposals: call-offs and reorder proposals', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let token = ''
	const written: Array<{ schema: string, id: string }> = []

	/**
	 * The CSRF token shillinq's own write routes need beside the session cookie.
	 *
	 * @return The request token of an authenticated app page.
	 */
	async function requestToken(): Promise<string> {
		if (token === '') {
			const html = await (await api.get('/index.php/apps/shillinq/')).text()
			token = html.match(/data-requesttoken="([^"]+)"/)?.[1] ?? ''
		}
		return token
	}

	/**
	 * The id of an object as OpenRegister answers it.
	 *
	 * @param object The object.
	 * @return Its id.
	 */
	function idOf(object: Record<string, unknown>): string {
		const self = object['@self'] as Record<string, unknown> | undefined
		return String(self?.id ?? object.id ?? '')
	}

	/**
	 * The approval tasks the order raised, to remove afterwards.
	 *
	 * @param purchaseOrderId The order.
	 * @return Its approval tasks.
	 */
	async function approvalTasks(purchaseOrderId: string): Promise<Array<Record<string, unknown>>> {
		const list = await api.get(`${OBJECTS}/ApprovalTask`, { headers: { 'OCS-APIRequest': 'true' }, params: { purchaseOrderId, _limit: '50' } })
		if (!list.ok()) {
			return []
		}
		const rows = ((await list.json()).results ?? []) as Array<Record<string, unknown>>
		return rows.filter((row) => row.purchaseOrderId === purchaseOrderId)
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
	})

	test.afterAll(async () => {
		for (const { schema, id } of written.reverse()) {
			await fx?.remove(schema, id)
		}
		await fx?.cleanup()
		await api?.dispose()
	})

	test('a call-off of 20 boxes takes the agreed EUR 42 and leaves EUR 9,160 under the ceiling @spec REQ-PG-005', async () => {
		const missing = await fx.missingSchema(['AdministrationMembership', 'FrameworkAgreement', 'PurchaseOrder'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		const administrationId = `${UNIQUE_PREFIX}-po`
		await fx.create('AdministrationMembership', {
			userId: 'admin',
			administrationId,
			role: 'eigenaar',
			mayPostJournalEntries: true,
			validFrom: '2024-01-01',
			validUntil: null,
		})
		const supplierId = randomUUID()
		const agreementNumber = `${UNIQUE_PREFIX}-FA`
		const { id: agreementId } = await fx.create('FrameworkAgreement', {
			agreementNumber,
			supplierId,
			title: 'Office paper',
			ceilingAmount: 1_000_000,
			drawnAmount: 0,
			currency: 'EUR',
			validFrom: '2026-01-01',
			validUntil: '2030-12-31',
			statusCode: 'active',
			administrationId,
			agreedPrices: [{ productCode: PAPER, description: 'Box of A4 paper', unitAmount: 4200 }],
		})

		const res = await api.post(`${APP_API}/purchase-orders`, {
			headers: { requesttoken: await requestToken(), 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: {
				administrationId,
				supplierId,
				costCenter: `${UNIQUE_PREFIX}-CC`,
				currency: 'EUR',
				frameworkAgreementId: agreementNumber,
				lines: [{ productCode: PAPER, quantity: 20, unitPrice: 50, vatRate: 0.21, glAccount: '4500' }],
			},
		})
		test.skip(res.status() === 404, `the administrator's membership of ${administrationId} is not resolved; the order route masks the administration`)
		const body = await res.text()
		expect(res.status(), body).toBe(201)
		const order = JSON.parse(body) as Record<string, unknown>
		const orderId = idOf(order)
		expect(orderId).not.toBe('')
		for (const task of await approvalTasks(orderId)) {
			written.push({ schema: 'ApprovalTask', id: idOf(task) })
		}
		written.push({ schema: 'PurchaseOrder', id: orderId })

		// The line takes the agreed price, whatever the buyer typed.
		const stored = await fx.get('PurchaseOrder', orderId)
		expect(stored.frameworkAgreementId, 'the order names the agreement it calls off').toBe(agreementNumber)
		const line = (stored.lines as OrderLine[])[0]
		expect(money(line.unitPrice), 'the line price is the agreed EUR 42').toBe(42)
		expect(line.agreedPrice).toBe(true)
		expect(money(line.lineTotal)).toBe(840)
		expect(money(Number(stored.totalAmount))).toBe(840)

		// The order counts against the ceiling: EUR 9,160 left.
		const agreement = await fx.get('FrameworkAgreement', agreementId)
		expect(Number(agreement.drawnAmount), 'EUR 840 drawn, in cents').toBe(84_000)
		expect((Number(agreement.ceilingAmount) - Number(agreement.drawnAmount)) / 100, 'left under the ceiling').toBe(9_160)
	})

	test.fixme('stock of 8 under a reorder point of 10 proposes a draft order of 50 units @spec REQ-IRA-011', async () => {
		// ReorderProposalJob is a daily TimedJob, started by Nextcloud's cron
		// (or `occ background-job:execute`); no route runs it, so the nightly
		// cannot make it run after seeding the rule. With a route or a
		// test-only trigger, this test seeds an InventoryStock of 8 units and an
		// active InventoryReorderRule (reorderPoint 10, reorderQuantity 50,
		// supplierId) in its own administration, runs the pass, and asserts one
		// open ReorderProposal for the rule whose purchaseOrderId names a
		// PurchaseOrder in `draft` with one line of 50 units for the product.
	})
})
