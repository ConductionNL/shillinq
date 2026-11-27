/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: reverse charge on the invoice and the ICP statement from
 * the books (tax-icp-and-reverse-charge task 3.2, REQ-ICR-001, REQ-ICR-002,
 * REQ-ICR-003).
 *
 *   1. an invoice to a Belgian business: the PDF of an issued invoice with a
 *      line in VAT category K and a buyer VAT number is served; the notice
 *      text in the PDF and category K in the UBL have no endpoint to read
 *      them from yet (REQ-ICR-001);
 *   2. a missing VAT number: issuing a draft invoice with a category K line
 *      and no buyer VAT number is refused, saying the number is required for
 *      reverse charge (REQ-ICR-001);
 *   3. the third quarter: Prepare statement for 2026-Q3 on the ICP page
 *      writes a draft statement with one line per buyer VAT number and
 *      supply type, adding up to the ledger of the period, and shows the
 *      reconciliation with box 3b and the difference (REQ-ICR-002);
 *   4. filing: while no Digipoort hand-off is bound, File refuses and points
 *      at the XML download, which serves the statement; submitted and
 *      accepted wait for the hand-off (REQ-ICR-003).
 *
 * Invariants are asserted on whatever the caller's active administration
 * holds; the scenario's amounts (EUR 12,000 to BE0123456749 and EUR 3,000 to
 * DE123456789) are checked only where those supplies are posted. The spec
 * writes a draft invoice it deletes afterwards and the 2026-Q3 draft
 * statement, which it deletes when there was none before. The caller is the
 * suite's administrator (globalSetup's storage state).
 *
 * Written, not run: it needs a live instance.
 *
 * @spec openspec/changes/archive/2026-11-24-tax-icp-and-reverse-charge/specs/bookkeeping-icp-opgaaf/spec.md
 * @spec openspec/changes/archive/2026-11-24-tax-icp-and-reverse-charge/tasks.md#task-3.2
 *
 * @e2e bookkeeping-icp-opgaaf::a-bookkeeper-issues-an-invoice-to-a-belgian-business
 * @e2e bookkeeping-icp-opgaaf::a-missing-vat-number-is-refused
 * @e2e bookkeeping-icp-opgaaf::a-bookkeeper-prepares-the-third-quarter
 * @e2e bookkeeping-icp-opgaaf::a-bookkeeper-files-the-statement
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const API = '/index.php/apps/shillinq/api'
const OR = '/index.php/apps/openregister/api/objects'
const OBJECTS = `${OR}/shillinq`
const HEADERS = { 'OCS-APIRequest': 'true' }
const PERIOD = '2026-Q3'
const RUN = Date.now().toString(36)

/** An OpenRegister object as the list and item endpoints answer it. */
interface RegisterObject {
	id?: string
	'@self'?: { id?: string }
	[field: string]: unknown
}

/** One line of an ARInvoice, as far as the reverse charge reads it. */
interface InvoiceLine {
	vatCategory?: string
	vatRate?: number
}

/** One line of the ICP statement. */
interface StatementLine {
	buyerVatId: string
	supplyType: string
	amountExclVat: number
}

/** IcpService::reconcile. */
interface Reconciliation {
	period: string
	icpTotal: number
	rubriek3b: number | null
	matches: boolean
	missing: boolean
	difference: number
}

/** IcpService::ledger. */
interface Ledger {
	period: string
	total: number
	supplyCount: number
}

/** The id of a register object, wherever OpenRegister put it. */
function idOf(object: RegisterObject): string {
	return String(object.id ?? object['@self']?.id ?? '')
}

/** Compare two euro amounts to the cent. */
function cents(value: number): number {
	return Math.round(value * 100)
}

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/** Run a page's header action: a button when it stands alone, else an entry of its Actions menu. */
async function runHeaderAction(page: Page, name: RegExp): Promise<void> {
	const button = page.getByRole('button', { name }).first()
	if (await button.isVisible().catch(() => false)) {
		await button.click()
		return
	}
	await page.getByRole('button', { name: /^(Actions|Acties)$/i }).first().click()
	await page.getByRole('menuitem', { name }).first().click()
}

/** The caller's active administration, or '' when there is none. */
async function activeAdministration(request: APIRequestContext): Promise<string> {
	const context = await request.get(`${API}/administrations/context`, { headers: HEADERS })
	if (!context.ok()) {
		return ''
	}
	return String((await context.json() as { activeAdministrationId?: string | null }).activeAdministrationId ?? '')
}

/** List register objects matching the given fields. */
async function list(request: APIRequestContext, schema: string, filters: Record<string, string>): Promise<RegisterObject[]> {
	const query = new URLSearchParams({ ...filters, _limit: '500' })
	const res = await request.get(`${OBJECTS}/${schema}?${query.toString()}`, { headers: HEADERS })
	expect(res.ok(), `list ${schema}: ${res.status()}`).toBe(true)
	return ((await res.json()) as { results?: RegisterObject[] }).results ?? []
}

/** Whether an invoice has a line or a VAT group in a reverse-charge category. */
function reverseCharged(invoice: RegisterObject): boolean {
	const lines = (invoice.invoiceLines as InvoiceLine[] | undefined) ?? []
	const groups = (invoice.vatBreakdown as Array<{ category?: string }> | undefined) ?? []
	return [...lines.map((line) => line.vatCategory), ...groups.map((group) => group.category)]
		.some((category) => ['K', 'AE'].includes(String(category ?? '').toUpperCase()))
}

test.describe('tax-icp-and-reverse-charge: reverse charge and the ICP statement', () => {
	let administrationId = ''

	test.beforeAll(async ({ request }) => {
		administrationId = await activeAdministration(request)
	})

	test('the PDF of an issued reverse-charged invoice with a buyer VAT number is served @spec REQ-ICR-001', async ({ request }) => {
		test.skip(administrationId === '', 'the e2e user works in no administration on this stack')
		const invoices = await list(request, 'ARInvoice', { administrationId, lifecycleState: 'issued' })
		const invoice = invoices.find((candidate) => reverseCharged(candidate) && String(candidate.buyerVatId ?? '') !== '')
		test.skip(invoice === undefined, 'the administration has no issued reverse-charged invoice with a buyer VAT number')

		const res = await request.get(`${API}/v1/invoices/${idOf(invoice!)}/pdf`, { headers: HEADERS })
		test.skip(res.status() === 503, 'docudesk is not installed, so no PDF can be rendered')
		expect(res.status(), await res.text()).toBe(200)
		expect(res.headers()['content-type'] ?? '').toContain('application/pdf')
		expect((await res.body()).subarray(0, 4).toString('latin1')).toBe('%PDF')
	})

	// Missing: no endpoint serves an AR invoice's UBL without sending it over Peppol, and the PDF's text (notice "Btw verlegd", BE0123456749) has no read endpoint.
	test.fixme('the PDF shows "Btw verlegd" and BE0123456749 and the UBL line carries category K at 0% @spec REQ-ICR-001', async () => {})

	test('issuing a reverse-charged invoice without a buyer VAT number is refused @spec REQ-ICR-001', async ({ request }) => {
		test.skip(administrationId === '', 'the e2e user works in no administration on this stack')
		const today = new Date().toISOString().slice(0, 10)
		const created = await request.post(`${OBJECTS}/ARInvoice`, {
			headers: HEADERS,
			data: {
				invoiceNumber: `E2E-ICR-${RUN}`,
				administrationId,
				customerId: `e2e-brouwerij-het-anker-${RUN}`,
				invoiceDate: today,
				dueDate: today,
				periodId: today.slice(0, 7),
				currency: 'EUR',
				lifecycleState: 'draft',
				invoiceType: 'standard',
				netAmount: 1000,
				vatAmount: 0,
				grossAmount: 1000,
				invoiceLines: [{ lineId: '1', quantity: 1, unitCode: 'C62', itemName: 'Levering binnen de EU', netPrice: 1000, netAmount: 1000, vatCategory: 'K', vatRate: 0 }],
				vatBreakdown: [{ category: 'K', rate: 0, taxableAmount: 1000, taxAmount: 0 }],
			},
		})
		expect(created.ok(), await created.text()).toBe(true)
		const invoiceId = idOf(await created.json() as RegisterObject)

		try {
			const issue = await request.post(`${OR}/${invoiceId}/transition`, { headers: HEADERS, data: { action: 'issue' } })
			const answer = await issue.text()
			expect(issue.ok(), 'the issue is refused').toBe(false)
			expect(answer).toMatch(/VAT number is required for reverse charge|btw-nummer/i)

			const after = await request.get(`${OBJECTS}/ARInvoice/${invoiceId}`, { headers: HEADERS })
			expect((await after.json() as RegisterObject).lifecycleState, 'the invoice stays a draft').toBe('draft')
		} finally {
			await request.delete(`${OBJECTS}/ARInvoice/${invoiceId}`, { headers: HEADERS }).catch(() => {})
		}
	})

	test('Prepare statement for 2026-Q3 writes the draft from the books and shows the box 3b reconciliation @spec REQ-ICR-002', async ({ page, request }) => {
		test.skip(administrationId === '', 'the e2e user works in no administration on this stack')
		const before = await list(request, 'IcpOpgaaf', { administrationId, period: PERIOD })
		const settled = before.find((statement) => String(statement.status ?? 'draft') !== 'draft')
		test.skip(settled !== undefined, `the ${PERIOD} statement is past draft and is never replaced`)

		await page.goto(`${APP}/belastingen/icp-opgaaf`)
		await dismissOverlays(page)
		await runHeaderAction(page, /^(Prepare statement|Opgaaf opstellen)$/i)
		const dialog = page.getByTestId('prepare-icp-modal')
		await expect(dialog).toBeVisible({ timeout: 10_000 })
		await dialog.getByTestId('prepare-icp-year').locator('input').fill('2026')
		await dialog.getByTestId('prepare-icp-number').locator('input').fill('3')
		await dialog.getByTestId('prepare-icp-submit').click()

		const refusal = dialog.getByTestId('prepare-icp-error')
		const created = dialog.getByTestId('prepare-icp-created')
		await expect(created.or(refusal)).toBeVisible({ timeout: 30_000 })
		const refused = await refusal.isVisible()
		test.skip(refused && /Dutch|jurisdiction|rules/i.test(await refusal.innerText()), 'the administration is not kept under Dutch rules')
		expect(refused, 'the statement is prepared').toBe(false)
		await expect(dialog.getByTestId('prepare-icp-reconciliation'), 'the reconciliation with box 3b').toBeVisible()

		try {
			const statement = (await list(request, 'IcpOpgaaf', { administrationId, period: PERIOD }))[0]
			expect(statement, `a ${PERIOD} statement`).toBeDefined()
			expect(statement.status).toBe('draft')
			const lines = (statement.lines as StatementLine[] | undefined) ?? []
			const keys = lines.map((line) => `${line.buyerVatId}|${line.supplyType}`)
			expect(new Set(keys).size, 'one line per buyer VAT number and supply type').toBe(keys.length)
			const sum = lines.reduce((total, line) => total + cents(line.amountExclVat), 0)
			expect(sum, 'the lines add up to the total').toBe(cents(Number(statement.total)))

			const query = `period_id=${PERIOD}&administration_id=${encodeURIComponent(administrationId)}`
			const ledger = await (await request.get(`${API}/icp/ledger?${query}`, { headers: HEADERS })).json() as Ledger
			expect(cents(ledger.total), 'the statement is the ledger of the period').toBe(cents(Number(statement.total)))

			const reconciliation = await (await request.get(`${API}/icp/reconcile?${query}`, { headers: HEADERS })).json() as Reconciliation
			expect(reconciliation.period).toBe(PERIOD)
			expect(cents(reconciliation.icpTotal)).toBe(cents(Number(statement.total)))
			if (reconciliation.rubriek3b !== null) {
				expect(Math.abs(cents(reconciliation.difference)), 'the difference with box 3b').toBe(Math.abs(cents(reconciliation.icpTotal) - cents(reconciliation.rubriek3b)))
			} else {
				expect(reconciliation.missing, 'no Q3 return: box 3b is missing').toBe(true)
			}

			// The scenario's two supplies, where they are posted.
			const belgian = lines.find((line) => line.buyerVatId === 'BE0123456749')
			const german = lines.find((line) => line.buyerVatId === 'DE123456789')
			if (belgian !== undefined && german !== undefined) {
				expect(cents(belgian.amountExclVat)).toBe(1_200_000)
				expect(cents(german.amountExclVat)).toBe(300_000)
			}
		} finally {
			if (before.length === 0) {
				for (const statement of await list(request, 'IcpOpgaaf', { administrationId, period: PERIOD })) {
					await request.delete(`${OBJECTS}/IcpOpgaaf/${idOf(statement)}`, { headers: HEADERS }).catch(() => {})
				}
			}
		}
	})

	test('File refuses while Digipoort is not bound and the XML download serves the statement @spec REQ-ICR-003', async ({ request }) => {
		test.skip(administrationId === '', 'the e2e user works in no administration on this stack')
		const filing = await request.get(`${API}/icp/statements/filing`, { headers: HEADERS })
		expect(filing.status()).toBe(200)
		const available = ((await filing.json()) as { available: boolean }).available
		test.skip(available, 'Digipoort is bound: filing would reach the Belastingdienst for real')

		const file = await request.post(`${API}/icp/statements/file`, { headers: HEADERS, data: { period: PERIOD, administration_id: administrationId } })
		expect(file.status(), await file.text()).toBe(409)
		const refusal = await file.json() as { error: string, message: string }
		expect(refusal.error).toBe('not-filed')
		expect(refusal.message).toMatch(/not available yet.*Download the XML/i)

		const statements = await list(request, 'IcpOpgaaf', { administrationId })
		test.skip(statements.length === 0, 'the administration has no ICP statement to download')
		const period = String(statements[0].period)
		const xml = await request.get(`${API}/icp/statements/xml?period=${encodeURIComponent(period)}&administration_id=${encodeURIComponent(administrationId)}`, { headers: HEADERS })
		expect(xml.status(), await xml.text()).toBe(200)
		expect(xml.headers()['content-type'] ?? '').toContain('application/xml')
		expect(await xml.text()).toContain('<')
	})

	// Missing: the Digipoort hand-off of tax-digipoort-filing is not bound (only the dormant log adapter), so a filing never reaches submitted or accepted.
	test.fixme('filing a finalised 2026-Q3 statement shows submitted, then accepted once Digipoort accepts it @spec REQ-ICR-003', async () => {})
})
