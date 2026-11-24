/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a purchase order sent by Peppol through integriq, pending
 * until integriq's delivery status says sent (purchasing-order-dispatch-and-
 * receipt task 5.1, REQ-PODR-001 and REQ-PODR-003).
 *
 * The live pass against integriq's log Peppol provider, driven through the
 * route the order page's Send via Peppol posts to:
 *
 *   1. an approved order PO-2026-031 to Drukkerij Van der Meer B.V., Peppol
 *      participant 0106:12345678;
 *   2. `POST /api/purchase-orders/{id}/transmit/peppol` hands it to integriq
 *      (IntegriqPeppolOrderAdapter): the order answers dispatch channel peppol,
 *      dispatch status pending, message reference
 *      `openregister://shillinq/PurchaseOrder/{id}`, and stays approved;
 *      integriq's event register holds one `nl.conduction.peppol.outbound.
 *      requested` CloudEvent naming that order with documentType Order and the
 *      stored UBL file; the order page says Waiting for delivery;
 *   3. integriq's log provider takes the request and reports
 *      `nl.conduction.peppol.delivery.status`; shillinq's
 *      PeppolDeliveryStatusListener moves the order through the declared
 *      `send` transition: statusCode sent, dispatch status sent, peppolSentAt
 *      set; the order page says Delivered.
 *
 * The task wants the event ids and the order state in the PR body; they are
 * recorded as test annotations. The spec skips, and says why, when integriq is
 * not installed or the dispatch-channels route reports Peppol unavailable.
 *
 * The order lives in an administration of the test's own (a unique id with a
 * membership for the suite's administrator), so no seed order is sent. The
 * supplier's Peppol id is held on a CustomerMaster whose customerId is the
 * order's supplierId: LogPeppolTransmissionAdapter, the party directory
 * IntegriqPeppolOrderAdapter reads, looks in `Vendor` first, a schema the
 * shillinq register no longer declares, and then in CustomerMaster. The
 * dispatch is posted to the route rather than clicked: PurchaseOrderDetail
 * enables Send via Peppol only for an order with a fully decided
 * `approvalChain`, a history field nothing writes any more.
 *
 * Every object written here, in shillinq's register and the CloudEvents in
 * integriq's, is removed afterwards; the UBL file integriq was handed stays in
 * the administrator's Files under /Shillinq/PurchaseOrders/.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no live instance).
 *
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/specs/bookkeeping-purchase-order-3way/spec.md
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md
 *
 * @e2e bookkeeping-purchase-order-3way::a-buyer-sends-an-order-to-a-peppol-supplier
 * @e2e bookkeeping-purchase-order-3way::sending-an-order-runs-the-declared-transition
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const APP = '/index.php/apps/shillinq'
const API = `${APP}/api`
const OR = '/index.php/apps/openregister/api'
const HEADERS = { 'OCS-APIRequest': 'true' }
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMINISTRATION = `${UNIQUE_PREFIX}-podr`
const SUPPLIER = `${UNIQUE_PREFIX}-drukkerij`
const PARTICIPANT = '0106:12345678'
const PO_NUMBER = 'PO-2026-031'
const OUTBOUND = 'nl.conduction.peppol.outbound.requested'
const DELIVERY = 'nl.conduction.peppol.delivery.status'
const NEEDED = ['AdministrationMembership', 'CustomerMaster', 'PurchaseOrder']

/** One dispatch channel as `GET /api/purchase-orders/dispatch-channels` answers it. */
interface DispatchChannel {
	available?: boolean
	reason?: string
}

/**
 * Whether an app is enabled: listed among the enabled apps, or, when the OCS
 * list cannot be read, its app route answering.
 *
 * @param api   The administrator's request context.
 * @param appId The app id.
 * @return True when the app is there.
 */
async function appInstalled(api: APIRequestContext, appId: string): Promise<boolean> {
	const apps = await api.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json', {
		headers: { ...HEADERS, Accept: 'application/json' },
	})
	if (apps.ok()) {
		return (await apps.text()).includes(`"${appId}"`)
	}
	const route = await api.get(`/index.php/apps/${appId}/`, { maxRedirects: 0 })
	return route.status() === 200
}

/**
 * Close Nextcloud's first-run wizard when it covers the page.
 *
 * @param page The page.
 */
async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/**
 * The id OpenRegister answers an object under.
 *
 * @param row The object.
 * @return Its id.
 */
function idOf(row: Record<string, unknown>): string {
	return String((row['@self'] as Record<string, unknown> | undefined)?.id ?? row.id ?? '')
}

test.describe('purchasing-order-dispatch-and-receipt: Peppol dispatch through integriq (task 5.1)', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let token = ''
	let integriq = false
	/** integriq CloudEvents about the order, removed in afterAll. */
	const events = new Set<string>()

	/**
	 * Headers for a write by the signed-in administrator.
	 *
	 * @return The headers with the CSRF token of an authenticated app page.
	 */
	async function writeHeaders(): Promise<Record<string, string>> {
		if (token === '') {
			const html = await (await api.get(`${APP}/`)).text()
			token = html.match(/data-requesttoken="([^"]+)"/)?.[1] ?? ''
		}
		return { ...HEADERS, requesttoken: token, 'Content-Type': 'application/json' }
	}

	/**
	 * The CloudEvents of a type in integriq's event register whose data names the object.
	 *
	 * @param type      The CloudEvent type.
	 * @param objectUri The objectUri the data must carry.
	 * @return The events.
	 */
	async function integriqEvents(type: string, objectUri: string): Promise<Array<Record<string, unknown>>> {
		const query = new URLSearchParams({ type, _limit: '200', _order: '{"@self.created":"DESC"}' })
		const res = await api.get(`${OR}/objects/integriq/event?${query.toString()}`, { headers: HEADERS })
		expect(res.ok(), `integriq event register: HTTP ${res.status()}`).toBeTruthy()
		const body = await res.json()
		const rows = (body.results ?? body.items ?? []) as Array<Record<string, unknown>>
		return rows.filter((row) => row.type === type
			&& ((row.data ?? {}) as Record<string, unknown>).objectUri === objectUri)
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
		integriq = await appInstalled(api, 'integriq')
	})

	test.afterAll(async () => {
		const headers = api ? await writeHeaders().catch(() => HEADERS) : HEADERS
		for (const id of events) {
			await api?.delete(`${OR}/objects/integriq/event/${id}`, { headers }).catch(() => undefined)
		}
		await fx?.cleanup()
		await api?.dispose()
	})

	test('an order sent by Peppol is pending, then sent after integriq\'s delivery status @spec REQ-PODR-001 @spec REQ-PODR-003', async ({ page }) => {
		test.skip(!integriq, 'integriq is not installed: the live pass needs its Peppol connector with the log provider')
		const missing = await fx.missingSchema(NEEDED)
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		const channels = await api.get(`${API}/purchase-orders/dispatch-channels`, { headers: HEADERS })
		expect(channels.ok(), await channels.text()).toBeTruthy()
		const peppol = ((await channels.json()) as Record<string, DispatchChannel>).peppol ?? {}
		test.skip(peppol.available !== true, `integriq is installed but Peppol dispatch is unavailable: ${peppol.reason ?? 'no reason given'}`)

		// 1. The administration, the supplier's Peppol id and the approved order.
		await fx.create('AdministrationMembership', {
			userId: ADMIN_USER,
			administrationId: ADMINISTRATION,
			role: 'controller',
			mayPostJournalEntries: true,
			validFrom: '2024-01-01',
			validUntil: null,
		})
		await fx.create('CustomerMaster', {
			customerId: SUPPLIER,
			legalName: 'Drukkerij Van der Meer B.V.',
			email: 'orders@drukkerij-example.nl',
			administrationId: ADMINISTRATION,
			lifecycleState: 'active',
			peppolParticipantId: PARTICIPANT,
		})
		const { id: orderId } = await fx.create('PurchaseOrder', {
			poNumber: PO_NUMBER,
			supplierId: SUPPLIER,
			requester: ADMIN_USER,
			costCenter: 'CC-100',
			currency: 'EUR',
			totalExclVat: 125000,
			totalVat: 26250,
			totalInclVat: 151250,
			statusCode: 'approved',
			administrationId: ADMINISTRATION,
		})
		if (String((await fx.get('PurchaseOrder', orderId)).statusCode ?? '') !== 'approved') {
			const approved = await fx.transition(orderId, 'approve')
			expect(approved.ok(), `approve: HTTP ${approved.status()} ${await approved.text()}`).toBeTruthy()
		}
		expect((await fx.get('PurchaseOrder', orderId)).statusCode).toBe('approved')
		const objectUri = `openregister://shillinq/PurchaseOrder/${orderId}`

		// 2. Send via Peppol: handed to integriq, pending.
		const sent = await api.post(`${API}/purchase-orders/${encodeURIComponent(orderId)}/transmit/peppol`, {
			headers: await writeHeaders(),
			data: { administrationId: ADMINISTRATION },
		})
		const sentText = await sent.text()
		expect(sent.ok(), `transmit/peppol: HTTP ${sent.status()} ${sentText}`).toBeTruthy()

		const handedOff = await fx.get('PurchaseOrder', orderId)
		expect(handedOff.dispatchError ?? null, 'integriq took the order').toBeNull()
		expect(handedOff.dispatchChannel).toBe('peppol')
		expect(handedOff.peppolMessageId).toBe(objectUri)
		// integriq's log provider may answer before this read; pending or already sent.
		expect(['pending', 'sent']).toContain(handedOff.dispatchStatus)

		const outbound = await integriqEvents(OUTBOUND, objectUri)
		expect(outbound, 'one outbound request for the order').toHaveLength(1)
		const request = (outbound[0].data ?? {}) as Record<string, unknown>
		expect(request.sourceApp).toBe('shillinq')
		expect(request.objectType).toBe('purchase-order')
		expect(request.recipientPeppolId).toBe(PARTICIPANT)
		expect(request.documentType).toBe('Order')
		expect(String(request.payloadFileUri ?? '')).toContain('/Shillinq/PurchaseOrders/')
		events.add(idOf(outbound[0]))
		test.info().annotations.push({ type: 'outbound.requested event', description: idOf(outbound[0]) })

		if (handedOff.dispatchStatus === 'pending') {
			expect(handedOff.statusCode, 'approved until integriq reports delivery').toBe('approved')
			await page.goto(`${APP}/inkoop/purchase-orders/${encodeURIComponent(orderId)}`)
			await dismissOverlays(page)
			await expect(page.getByText('Waiting for delivery').first()).toBeVisible({ timeout: 20_000 })
			test.info().annotations.push({ type: 'order state after send', description: 'approved, dispatch pending via peppol' })
		}

		// 3. integriq reports the delivery; the order moves to sent.
		await expect.poll(async () => (await integriqEvents(DELIVERY, objectUri)).length, {
			message: 'integriq reported a delivery status for the order',
			timeout: 120_000,
			intervals: [2_000, 5_000, 10_000],
		}).toBeGreaterThan(0)
		for (const event of await integriqEvents(DELIVERY, objectUri)) {
			events.add(idOf(event))
			const data = (event.data ?? {}) as Record<string, unknown>
			test.info().annotations.push({ type: 'delivery.status event', description: `${idOf(event)} status=${String(data.status)} transmissionId=${String(data.transmissionId ?? '')}` })
		}

		await expect.poll(async () => (await fx.get('PurchaseOrder', orderId)).statusCode, { timeout: 30_000 }).toBe('sent')
		const delivered = await fx.get('PurchaseOrder', orderId)
		expect(delivered.dispatchStatus).toBe('sent')
		expect(delivered.dispatchError ?? null).toBeNull()
		expect(String(delivered.peppolSentAt ?? '')).not.toBe('')
		test.info().annotations.push({ type: 'order state after delivery status', description: `${String(delivered.statusCode)} at ${String(delivered.peppolSentAt)}` })

		await page.goto(`${APP}/inkoop/purchase-orders/${encodeURIComponent(orderId)}`)
		await dismissOverlays(page)
		await expect(page.getByText('Delivered').first()).toBeVisible({ timeout: 20_000 })
		await expect(page.getByTestId('po-detail-already-sent')).toBeVisible()
	})

	// REQ-PODR-003 also says the audit trail shows the send transition; the
	// audit trail is OpenRegister's own, and this file asserts only what
	// shillinq's routes and the order page show.
})
