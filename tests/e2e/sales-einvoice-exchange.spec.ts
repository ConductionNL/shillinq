/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: Send e-invoice hands the invoice to integriq, and integriq
 * writes a Peppol transmission for it (sales-einvoice-exchange task 1.3,
 * REQ-SEIX-001 and REQ-SEIX-002).
 *
 * The live pass on an instance with integriq, driven through the route the AR
 * invoice page's Send e-invoice action posts to:
 *
 *   1. an issued invoice 2026-0420 to Gemeente Voorbeeld, whose CustomerMaster
 *      carries Peppol participant 0106:00000420, with a KvK number of 8 digits
 *      and the BTW number NL000099998B57 the seed uses for a valid one;
 *   2. `POST /api/ar-invoices/{invoiceNumber}/send-einvoice` answers
 *      deliveryStatus queued and a payload reference to the stored UBL XML in
 *      the administration's e-invoices folder, and the stored invoice says the
 *      same (REQ-SEIX-002);
 *   3. integriq's event register holds one
 *      `nl.conduction.peppol.outbound.requested` CloudEvent with sourceApp
 *      shillinq, objectType ar-invoice, the invoice's objectUri, the
 *      participant and that payload reference (REQ-SEIX-001);
 *   4. integriq's PeppolOutboundConsumer takes that object and writes a
 *      `peppol_transmission` for the invoice. This is the check task 1.3 asks
 *      for: when the consumer compares register and schema slugs against the
 *      numeric ids OpenRegister stamps ("the id trap", design.md), no
 *      transmission appears and the test fails saying so;
 *   5. the AR invoice page shows delivery status queued.
 *
 * The transmission uuid the task wants in the PR body is recorded as a test
 * annotation. The spec skips, and says why, when integriq is not installed.
 * VIES is asked about the BTW number; when it is down the send proceeds with a
 * warning, as designed.
 *
 * The invoice lives in an administration of the test's own (a unique id with a
 * membership for the suite's administrator), so no seed invoice is sent. Every
 * object written here, the CloudEvent in integriq's register included, is
 * removed afterwards; integriq's transmission and the stored UBL and PDF files
 * stay, as integriq's and the administrator's records of the send.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no live instance).
 *
 * @spec openspec/changes/sales-einvoice-exchange/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md
 *
 * @e2e bookkeeping-einvoicing-ubl-peppol::a-bookkeeper-sends-an-e-invoice-to-a-municipality
 * @e2e bookkeeping-einvoicing-ubl-peppol::the-e-invoice-file-can-be-opened-afterwards
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const APP = '/index.php/apps/shillinq'
const API = `${APP}/api`
const OR = '/index.php/apps/openregister/api'
const HEADERS = { 'OCS-APIRequest': 'true' }
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMINISTRATION = `${UNIQUE_PREFIX}-seix`
const CUSTOMER = `${UNIQUE_PREFIX}-gemeente`
const PARTICIPANT = '0106:00000420'
const INVOICE_NUMBER = '2026-0420'
const OUTBOUND = 'nl.conduction.peppol.outbound.requested'
const NEEDED = ['AdministrationMembership', 'CustomerMaster', 'ARInvoice']

/** The answer of the send-einvoice route (EInvoiceService::sendEInvoice). */
interface SendResult {
	deliveryStatus: string
	transmissionId: string | null
	payloadFileUri: string | null
	fallback: boolean
	warnings: Array<{ code?: string }>
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
	return String((row['@self'] as Record<string, unknown> | undefined)?.id ?? row.id ?? row.uuid ?? '')
}

/**
 * The rows of an OpenRegister list answer.
 *
 * @param body The answer.
 * @return Its rows.
 */
function rowsOf(body: Record<string, unknown> | unknown[]): Array<Record<string, unknown>> {
	if (Array.isArray(body)) {
		return body as Array<Record<string, unknown>>
	}
	return (body.results ?? body.items ?? []) as Array<Record<string, unknown>>
}

test.describe('sales-einvoice-exchange: Send e-invoice reaches integriq (task 1.3)', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let token = ''
	let integriq = false
	/** integriq CloudEvents about the invoice, removed in afterAll. */
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
	 * The slug of integriq's Peppol transmission schema, looked up rather than
	 * assumed (`peppol_transmission` in the task, `peppol-transmission` in a
	 * dashed slug).
	 *
	 * @return The slug, or '' when OpenRegister knows no such schema.
	 */
	async function transmissionSchema(): Promise<string> {
		const res = await api.get(`${OR}/schemas?_limit=1000`, { headers: HEADERS })
		expect(res.ok(), `schemas: HTTP ${res.status()}`).toBeTruthy()
		const slug = rowsOf(await res.json())
			.map((schema) => String(schema.slug ?? ''))
			.find((candidate) => /^peppol[-_]?transmission$/i.test(candidate))
		return slug ?? ''
	}

	/**
	 * Every object of an integriq schema whose JSON names the needle.
	 *
	 * @param schema The integriq schema slug.
	 * @param needle Text the object must carry.
	 * @param query  Extra list filters.
	 * @return The objects.
	 */
	async function integriqObjects(schema: string, needle: string, query: Record<string, string> = {}): Promise<Array<Record<string, unknown>>> {
		const params = new URLSearchParams({ ...query, _limit: '200', _order: '{"@self.created":"DESC"}' })
		const res = await api.get(`${OR}/objects/integriq/${schema}?${params.toString()}`, { headers: HEADERS })
		expect(res.ok(), `integriq ${schema}: HTTP ${res.status()}`).toBeTruthy()
		return rowsOf(await res.json()).filter((row) => JSON.stringify(row).includes(needle))
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

	test('Send e-invoice queues the invoice and integriq writes a Peppol transmission for it @spec REQ-SEIX-001 @spec REQ-SEIX-002', async ({ page }) => {
		test.skip(!integriq, 'integriq is not installed: the live pass needs its Peppol connector')
		const missing = await fx.missingSchema(NEEDED)
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		// 1. The administration, the municipality and its issued invoice.
		await fx.create('AdministrationMembership', {
			userId: ADMIN_USER,
			administrationId: ADMINISTRATION,
			role: 'controller',
			mayPostJournalEntries: true,
			validFrom: '2024-01-01',
			validUntil: null,
		})
		await fx.create('CustomerMaster', {
			customerId: CUSTOMER,
			legalName: 'Gemeente Voorbeeld',
			email: 'facturen@gemeente-voorbeeld.example',
			kvkNumber: '12340420',
			vatId: 'NL000099998B57',
			administrationId: ADMINISTRATION,
			lifecycleState: 'active',
			peppolParticipantId: PARTICIPANT,
		})
		const { id: invoiceId } = await fx.create('ARInvoice', {
			invoiceNumber: INVOICE_NUMBER,
			customerId: CUSTOMER,
			administrationId: ADMINISTRATION,
			invoiceDate: '2026-11-02',
			dueDate: '2026-12-02',
			periodId: '2026-11',
			netAmount: 1000,
			vatAmount: 210,
			grossAmount: 1210,
			currency: 'EUR',
			buyerLegalRegId: '12340420',
			buyerVatId: 'NL000099998B57',
			deliveryStatus: 'not-sent',
			invoiceLines: [{ lineNumber: 1, description: 'Advies oktober', quantity: 10, unitPrice: 100, vatRate: 21, lineAmount: 1000 }],
			lifecycleState: 'issued',
		})
		if (String((await fx.get('ARInvoice', invoiceId)).lifecycleState ?? '') !== 'issued') {
			const issued = await fx.transition(invoiceId, 'issue')
			expect(issued.ok(), `issue: HTTP ${issued.status()} ${await issued.text()}`).toBeTruthy()
		}
		expect((await fx.get('ARInvoice', invoiceId)).lifecycleState).toBe('issued')
		const objectUri = `openregister://shillinq/ARInvoice/${invoiceId}`

		// 2. Send e-invoice.
		const sent = await api.post(`${API}/ar-invoices/${encodeURIComponent(INVOICE_NUMBER)}/send-einvoice`, {
			headers: await writeHeaders(),
			data: { administrationId: ADMINISTRATION },
		})
		const sentText = await sent.text()
		expect(sent.status(), sentText).toBe(200)
		const result = JSON.parse(sentText) as SendResult
		expect(result.fallback, `no fallback to email: ${sentText}`).toBe(false)
		expect(result.deliveryStatus).toBe('queued')
		const payload = String(result.payloadFileUri ?? '')
		expect(payload, 'the reference names the stored UBL XML').toMatch(/\.xml$/)

		const queued = await fx.get('ARInvoice', invoiceId)
		expect(queued.deliveryStatus).toBe('queued')
		expect(queued.payloadFileUri).toBe(payload)
		expect(queued.buyerPeppolParticipantId).toBe(PARTICIPANT)

		// 3. The outbound request in integriq's event register.
		const outbound = await integriqObjects('event', objectUri, { type: OUTBOUND })
		expect(outbound, 'one outbound request for the invoice').toHaveLength(1)
		events.add(idOf(outbound[0]))
		const request = (outbound[0].data ?? {}) as Record<string, unknown>
		expect(outbound[0].type).toBe(OUTBOUND)
		expect(request.sourceApp).toBe('shillinq')
		expect(request.objectType).toBe('ar-invoice')
		expect(request.objectUri).toBe(objectUri)
		expect(request.recipientPeppolId).toBe(PARTICIPANT)
		expect(request.payloadFileUri).toBe(payload)
		expect(request.administrationId).toBe(ADMINISTRATION)
		test.info().annotations.push({ type: 'outbound.requested event', description: idOf(outbound[0]) })

		// 4. integriq's consumer writes the transmission.
		const schema = await transmissionSchema()
		expect(schema, 'integriq declares a peppol_transmission schema').not.toBe('')
		await expect.poll(async () => (await integriqObjects(schema, objectUri)).length, {
			message: `integriq wrote no ${schema} for ${objectUri}: if its PeppolOutboundConsumer compares the register and schema against slugs, it never matches (the id trap of design.md); open the integriq issue and link it in the PR body`,
			timeout: 60_000,
			intervals: [2_000, 5_000, 10_000],
		}).toBeGreaterThan(0)
		const [transmission] = await integriqObjects(schema, objectUri)
		test.info().annotations.push({ type: 'peppol_transmission uuid', description: idOf(transmission) })

		// 5. The AR invoice page shows the queued delivery.
		await page.goto(`${APP}/bookkeeping/accounts-receivable/${encodeURIComponent(invoiceId)}`)
		await dismissOverlays(page)
		await expect(page.getByText(/queued/i).first()).toBeVisible({ timeout: 20_000 })
	})
})
