/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the sales invoice document (sales-invoice-document task 4.2,
 * REQ-SID-001 to REQ-SID-005), driven through the shipped surfaces:
 *
 *   1. an office sets its house style: the administration's InvoiceLayout is
 *      written through the object API, the Invoice layouts index
 *      (`/sales/invoice-layouts`, manifest page `InvoiceLayouts`) lists it, and
 *      the detail page's Preview tab (`InvoiceLayoutPreview`) draws the sample
 *      page in the modern variant and the saved colours with each colour's
 *      contrast written out; a pale primary colour is refused on save with
 *      its ratio (InvoiceLayoutContrastListener, 422) (REQ-SID-001);
 *   2. without docudesk, `GET /api/v1/invoices/{id}/pdf` answers 503
 *      `docudesk-unavailable` with "Docudesk is needed to produce invoice
 *      documents." instead of a file (REQ-SID-002);
 *   3. with docudesk, an English customer's GBP invoice is served as
 *      `application/pdf`, and the text of the PDF reads in English with
 *      pounds and no euro sign (REQ-SID-003, REQ-SID-004);
 *   4. with docudesk, a Dutch EUR invoice is served as a Dutch PDF in euros
 *      (REQ-SID-005, the document the QR is printed on).
 *
 * Still `fixme`: the EPC transfer QR of the Dutch invoice. The only route that
 * serves the document (InvoiceApiController::renderPdf) hands the generator
 * no creditor, so EpcQrPayload gets no IBAN and `qr.epc` is null on every
 * served invoice; no route serves InvoiceDocumentData, and mPDF draws the QR
 * as graphics, so its payload cannot be read back from the PDF. The payload
 * itself is covered by EpcQrPayloadTest and InvoiceDocumentDataTest.
 *
 * Docudesk is detected from the enabled-apps list (OCS, as administrator),
 * falling back to its app route answering; the docudesk tests skip without it
 * and the "without docudesk" test skips with it. Every invoice is a
 * BillableInvoice seeded in the caller's active administration with a
 * per-run number; the layout written in test 1 is restored, or removed when
 * the test created it. The language of a BillableInvoice reaches the document
 * only through its `documentLanguage` (the controller passes no customer), so
 * test 3 is marked fixme on an instance that drops that undeclared property.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no
 * live instance).
 *
 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/specs/bookkeeping-accounts-receivable-core/spec.md
 * @spec openspec/changes/archive/2026-11-24-sales-invoice-document/tasks.md#task-4.2
 *
 * @e2e bookkeeping-accounts-receivable-core::an-office-sets-its-house-style
 * @e2e bookkeeping-accounts-receivable-core::docudesk-is-not-installed
 * @e2e bookkeeping-accounts-receivable-core::an-english-customer-gets-an-english-invoice
 * @e2e bookkeeping-accounts-receivable-core::a-pound-invoice
 * @e2e bookkeeping-accounts-receivable-core::a-municipality-pays-by-scanning
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { inflateSync } from 'node:zlib'
import { OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const APP = '/apps/shillinq'
const APP_API = '/index.php/apps/shillinq/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const OCS = { 'OCS-APIRequest': 'true' }
const DOCUDESK_NEEDED = 'Docudesk is needed to produce invoice documents.'

/** The InvoiceLayout fields the settings page edits. */
const LAYOUT_FIELDS = ['administrationId', 'logoFileId', 'primaryColor', 'accentColor', 'variant', 'footerText', 'showQr']

/** A served invoice document. */
interface ServedPdf {
	status: number
	contentType: string
	disposition: string
	bytes: Buffer
}

/**
 * Close the Nextcloud first-run wizard when it covers the page.
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
 * Whether docudesk is installed: listed among the enabled apps, or, when the
 * OCS list cannot be read, its app route answering.
 *
 * @param api The administrator's request context.
 * @return True when docudesk is there.
 */
async function docudeskInstalled(api: APIRequestContext): Promise<boolean> {
	const apps = await api.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json', {
		headers: { ...OCS, Accept: 'application/json' },
	})
	if (apps.ok()) {
		return (await apps.text()).includes('"docudesk"')
	}
	const route = await api.get('/index.php/apps/docudesk/', { maxRedirects: 0 })
	return route.status() === 200
}

/**
 * The caller's active administration, as shillinq resolves it on the server.
 *
 * @param api The request context.
 * @return Its id, or '' without one.
 */
async function activeAdministration(api: APIRequestContext): Promise<string> {
	const res = await api.get(`${APP_API}/administrations/context`, { headers: OCS })
	if (!res.ok()) {
		return ''
	}
	return String(((await res.json()) as { activeAdministrationId?: string }).activeAdministrationId ?? '')
}

/**
 * Fetch an invoice's document from the invoice PDF route.
 *
 * @param api       The request context.
 * @param invoiceId The BillableInvoice id.
 * @return The status, headers and body.
 */
async function fetchPdf(api: APIRequestContext, invoiceId: string): Promise<ServedPdf> {
	const res = await api.get(`${APP_API}/v1/invoices/${encodeURIComponent(invoiceId)}/pdf`, { headers: OCS })
	const headers = res.headers()
	return {
		status: res.status(),
		contentType: headers['content-type'] ?? '',
		disposition: headers['content-disposition'] ?? '',
		bytes: await res.body(),
	}
}

/**
 * The bytes of one PDF literal string `( … )` starting at `start`, with its
 * escapes resolved, and the index after its closing parenthesis.
 *
 * @param src   The content stream as latin1.
 * @param start The index of the opening parenthesis.
 * @return The string's bytes and where scanning goes on.
 */
function literalString(src: string, start: number): { bytes: number[], end: number } {
	const bytes: number[] = []
	const escapes: Record<string, number> = { n: 10, r: 13, t: 9, b: 8, f: 12 }
	let depth = 1
	let i = start + 1
	while (i < src.length && depth > 0) {
		const c = src[i]
		if (c === '\\') {
			const next = src[i + 1] ?? ''
			const octal = /^[0-7]{1,3}/.exec(src.slice(i + 1, i + 4))
			if (octal !== null) {
				bytes.push(parseInt(octal[0], 8) & 0xff)
				i += 1 + octal[0].length
				continue
			}
			if (next in escapes) {
				bytes.push(escapes[next])
			} else if (next !== '\n' && next !== '\r') {
				bytes.push(next.charCodeAt(0))
			}
			i += 2
			continue
		}
		if (c === '(') {
			depth++
		} else if (c === ')') {
			depth--
			if (depth === 0) {
				break
			}
		}
		bytes.push(c.charCodeAt(0))
		i++
	}
	return { bytes, end: i + 1 }
}

/**
 * Decode the bytes of a shown string: UTF-16BE (mPDF's Unicode fonts) when
 * they look like it, else Latin-1 (the core fonts).
 *
 * @param bytes The string's bytes.
 * @return The text.
 */
function decodeShown(bytes: number[]): string {
	const pairs = Math.floor(bytes.length / 2)
	const zeroHigh = bytes.filter((b, i) => i % 2 === 0 && b === 0).length
	if (bytes.length % 2 === 0 && pairs > 0 && zeroHigh * 2 >= pairs) {
		let out = ''
		for (let i = 0; i < bytes.length; i += 2) {
			out += String.fromCharCode((bytes[i] << 8) | bytes[i + 1])
		}
		return out
	}
	return Buffer.from(bytes).toString('latin1')
}

/**
 * The text a PDF shows: every content stream is inflated when compressed and
 * the literal and hex strings between BT and ET are decoded and joined, so a
 * kerned `[(In) -20 (voice)] TJ` reads "Invoice". Enough to assert labels and
 * amounts, not a general extractor.
 *
 * @param pdf The PDF bytes.
 * @return The shown text, concatenated.
 */
function pdfText(pdf: Buffer): string {
	const raw = pdf.toString('latin1')
	const streams = /<<([\s\S]*?)>>\s*stream\r?\n/g
	let text = ''
	let match: RegExpExecArray | null
	while ((match = streams.exec(raw)) !== null) {
		const from = match.index + match[0].length
		const to = raw.indexOf('endstream', from)
		if (to < 0) {
			break
		}
		let content = pdf.subarray(from, to)
		if (match[1].includes('/FlateDecode')) {
			try {
				content = inflateSync(content)
			} catch {
				continue
			}
		}
		const src = content.toString('latin1')
		if (!/\bBT\b/.test(src) || !/\bTf\b/.test(src)) {
			continue
		}
		let i = 0
		while (i < src.length) {
			if (src[i] === '(') {
				const shown = literalString(src, i)
				text += decodeShown(shown.bytes)
				i = shown.end
			} else if (src[i] === '<' && src[i + 1] !== '<') {
				const close = src.indexOf('>', i)
				if (close < 0) {
					break
				}
				const hex = src.slice(i + 1, close).replace(/\s+/g, '')
				const bytes = (hex.match(/.{1,2}/g) ?? []).map((h) => parseInt(h.padEnd(2, '0'), 16))
				text += decodeShown(bytes)
				i = close + 1
			} else if (src[i] === '<') {
				i += 2
			} else {
				i++
			}
		}
		streams.lastIndex = to
	}
	return text.replace(/\u00a0|\u202f/g, ' ')
}

test.describe('sales-invoice-document: the invoice document', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let token = ''
	let administrationId = ''
	let docudesk = false
	let restoreLayout: { id: string, fields: Record<string, unknown> } | null = null

	/**
	 * The CSRF token shillinq's and OpenRegister's write routes need beside
	 * the session cookie.
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
	 * A BillableInvoice in the active administration, tracked for cleanup.
	 *
	 * @param suffix   Makes the invoice number unique within this run.
	 * @param currency The invoice currency.
	 * @param language The document language (`documentLanguage`).
	 * @param net      The amount excluding VAT; VAT is 21% of it (20% for GBP).
	 * @return The invoice's id and number, and the object as stored.
	 */
	async function seedInvoice(suffix: string, currency: string, language: string, net: number): Promise<{ id: string, invoiceNumber: string, stored: Record<string, unknown> }> {
		const invoiceNumber = `${UNIQUE_PREFIX}-${suffix}`
		const vat = Math.round(net * (currency === 'GBP' ? 0.2 : 0.21) * 100) / 100
		const { id } = await fx.create('BillableInvoice', {
			administrationId,
			invoiceNumber,
			billingModel: 'fixed_fee',
			customerId: `${UNIQUE_PREFIX}-customer-${suffix}`,
			invoiceDate: '2026-11-24',
			dueDate: '2026-12-24',
			netAmount: net,
			vatAmount: vat,
			grossAmount: net + vat,
			currency,
			documentLanguage: language,
			paymentTerms: 'net 30',
			status: 'posted',
		})
		return { id, invoiceNumber, stored: await fx.get('BillableInvoice', id) }
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
		administrationId = await activeAdministration(api)
		docudesk = await docudeskInstalled(api)
	})

	test.afterAll(async () => {
		if (restoreLayout !== null) {
			await fx?.update('InvoiceLayout', restoreLayout.id, restoreLayout.fields).catch(() => undefined)
		}
		await fx?.cleanup()
		await api?.dispose()
	})

	test('an office sets its house style @spec REQ-SID-001', async ({ page }) => {
		const missing = await fx.missingSchema(['InvoiceLayout'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)
		test.skip(administrationId === '', 'the administrator has no active administration; the layout pages are administration-scoped')

		// The administration's layout (seeded one per administration), else a new one.
		const query = new URLSearchParams({ administrationId, _limit: '10' })
		const listed = await api.get(`${OBJECTS}/InvoiceLayout?${query.toString()}`, { headers: OCS })
		expect(listed.ok(), await listed.text()).toBe(true)
		const existing = (((await listed.json()) as { results?: Array<Record<string, unknown>> }).results ?? [])[0]
		const house = {
			administrationId,
			primaryColor: '#1B4F72',
			accentColor: '#FFFF66',
			variant: 'modern',
			footerText: `Registered office ${UNIQUE_PREFIX}`,
			showQr: true,
		}
		let layoutId: string
		if (existing !== undefined) {
			layoutId = String((existing['@self'] as { id?: string } | undefined)?.id ?? existing.id ?? '')
			const fields: Record<string, unknown> = {}
			for (const key of LAYOUT_FIELDS) {
				if (key in existing) {
					fields[key] = existing[key]
				}
			}
			restoreLayout = { id: layoutId, fields }
			await fx.update('InvoiceLayout', layoutId, { ...fields, ...house })
		} else {
			layoutId = (await fx.create('InvoiceLayout', house)).id
		}
		expect(layoutId).not.toBe('')

		// The server refuses a primary colour below 4.5:1 and says its ratio.
		const pale = await api.put(`${OBJECTS}/InvoiceLayout/${layoutId}`, {
			headers: { ...OCS, requesttoken: await requestToken(), 'Content-Type': 'application/json' },
			data: { ...house, primaryColor: '#FFFF66' },
		})
		expect(pale.status(), 'a pale primary colour is refused').toBe(422)
		expect(await pale.text()).toContain('contrast of 1.0:1 against white')
		expect((await fx.get('InvoiceLayout', layoutId)).primaryColor, 'the refused colour is not stored').toBe('#1B4F72')

		// The index lists the administration's layout.
		await page.goto(APP + '/sales/invoice-layouts', { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({ timeout: 15_000 })
		await expect(page.getByTestId('cn-object-list-table')).toBeVisible({ timeout: 15_000 })

		// The detail page's Preview tab draws the house style.
		await page.goto(`${APP}/sales/invoice-layouts/${layoutId}`, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({ timeout: 15_000 })
		const tab = page.getByTestId('cn-object-sidebar-tab-preview')
		await expect(tab).toBeVisible({ timeout: 15_000 })
		await tab.click()

		const preview = page.getByTestId('invoice-layout-preview')
		await expect(preview).toBeVisible({ timeout: 15_000 })
		const sample = preview.getByTestId('invoice-layout-preview-page')
		await expect(sample).toHaveClass(/ilp__page--modern/)
		const primary = await sample.evaluate((el) => (el as HTMLElement).style.getPropertyValue('--ilp-primary').trim())
		expect(primary.toUpperCase()).toBe('#1B4F72')
		await expect(preview).toContainText(/Variant: Modern/)
		await expect(preview).toContainText(house.footerText)
		await expect(preview.getByTestId('invoice-layout-preview-qr')).toBeVisible()

		// Each colour's contrast is written out; the pale accent is an alert in words.
		const primaryCheck = preview.getByTestId('invoice-layout-contrast-primary')
		await expect(primaryCheck).toContainText('8.7:1')
		await expect(primaryCheck).not.toHaveAttribute('role', 'alert')
		const accentCheck = preview.getByTestId('invoice-layout-contrast-accent')
		await expect(accentCheck).toHaveAttribute('role', 'alert')
		await expect(accentCheck).toContainText('1.0:1')
		await expect(accentCheck).toContainText(/4[.,]5:1/)
	})

	test('without docudesk the invoice PDF says it is needed @spec REQ-SID-002', async () => {
		test.skip(docudesk, 'docudesk is installed here; its absence cannot be shown')
		const missing = await fx.missingSchema(['BillableInvoice'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)
		test.skip(administrationId === '', 'the administrator has no active administration; the PDF route answers 403')

		const { id } = await seedInvoice('NODD', 'EUR', 'nl', 1000)
		const served = await fetchPdf(api, id)
		expect(served.status, served.bytes.toString('utf8')).toBe(503)
		expect(served.contentType).toContain('application/json')
		const body = JSON.parse(served.bytes.toString('utf8')) as { error?: string, message?: string }
		expect(body.error).toBe('docudesk-unavailable')
		expect(body.message).toBe(DOCUDESK_NEEDED)
		expect(served.bytes.subarray(0, 4).toString('latin1'), 'no file is served').not.toBe('%PDF')
	})

	test('an English customer gets an English pound invoice @spec REQ-SID-003 @spec REQ-SID-004', async () => {
		test.skip(!docudesk, 'docudesk is not installed; the invoice PDF is rendered by docudesk')
		const missing = await fx.missingSchema(['BillableInvoice'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)
		test.skip(administrationId === '', 'the administrator has no active administration; the PDF route answers 403')

		const { id, invoiceNumber, stored } = await seedInvoice('GB', 'GBP', 'en', 4000)
		test.fixme(
			stored.documentLanguage !== 'en',
			'this instance drops BillableInvoice.documentLanguage (not in the schema), and InvoiceApiController::renderPdf passes no customer or language, so no route reaches an English document',
		)

		const served = await fetchPdf(api, id)
		expect(served.status, served.bytes.toString('utf8').slice(0, 300)).toBe(200)
		expect(served.contentType).toContain('application/pdf')
		expect(served.disposition).toContain(`invoice-${invoiceNumber}.pdf`)
		expect(served.bytes.subarray(0, 4).toString('latin1')).toBe('%PDF')

		const text = pdfText(served.bytes)
		for (const label of ['Invoice number', 'Due date', 'Total excluding VAT', 'Amount due']) {
			expect(text, `the English label "${label}"`).toContain(label)
		}
		for (const dutch of ['Factuurnummer', 'Vervaldatum', 'Openstaand bedrag']) {
			expect(text, `no Dutch label "${dutch}"`).not.toContain(dutch)
		}
		expect(text).toContain(invoiceNumber)
		expect(text, 'the gross in pounds').toMatch(/£\s?4,800\.00/)
		expect(text, 'no euro sign').not.toContain('€')
	})

	test('a Dutch EUR invoice is served as a Dutch PDF in euros @spec REQ-SID-005', async () => {
		test.skip(!docudesk, 'docudesk is not installed; the invoice PDF is rendered by docudesk')
		const missing = await fx.missingSchema(['BillableInvoice'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)
		test.skip(administrationId === '', 'the administrator has no active administration; the PDF route answers 403')

		const { id, invoiceNumber } = await seedInvoice('NL', 'EUR', 'nl', 20000)
		const served = await fetchPdf(api, id)
		expect(served.status, served.bytes.toString('utf8').slice(0, 300)).toBe(200)
		expect(served.contentType).toContain('application/pdf')
		expect(served.disposition).toContain(`invoice-${invoiceNumber}.pdf`)
		expect(served.bytes.subarray(0, 4).toString('latin1')).toBe('%PDF')

		const text = pdfText(served.bytes)
		for (const label of ['Factuurnummer', 'Vervaldatum', 'Openstaand bedrag']) {
			expect(text, `the Dutch label "${label}"`).toContain(label)
		}
		expect(text).toContain(invoiceNumber)
		expect(text, 'the gross in euros').toMatch(/€\s?24\.200,00/)
		expect(text).not.toContain('£')
	})

	test.fixme('a municipality pays by scanning: the EPC QR carries the open amount and invoice number @spec REQ-SID-005', async () => {
		// No reachable surface: InvoiceApiController::renderPdf passes only
		// `userId` as context, so InvoiceDocumentData gets no creditor IBAN and
		// `qr.epc` is null on every served document; no route serves the
		// assembled data, and mPDF draws the QR as graphics, not text. Once a
		// route exposes the data (or the creditor reaches the PDF and a QR
		// decoder is in the suite), assert on the Dutch EUR invoice of 24,200.00:
		//   fields = qr.epc.split('\n')
		//   fields.slice(0, 4) equals ['BCD', '002', '1', 'SCT']
		//   fields[7] is 'EUR24200.00' and fields[10] is the invoice number.
	})
})
