/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the sales invoice document (sales-invoice-document task 4.2,
 * REQ-SID-001 to REQ-SID-005).
 *
 *   1. an office sets its house style on the invoice layout settings page and
 *      the preview shows it (REQ-SID-001);
 *   2. without docudesk, asking for an invoice PDF says docudesk is needed
 *      (REQ-SID-002);
 *   3. an English customer's GBP invoice reads in English with pounds and no
 *      euro sign (REQ-SID-003, REQ-SID-004);
 *   4. a Dutch EUR invoice carries the EPC transfer QR for its open amount
 *      with the invoice number as reference (REQ-SID-005).
 *
 * Every step needs the layout page (task 4.1) or the docudesk renderer (task
 * 2.2), so each is `fixme` until those land; the assertions say what to check
 * once they do. Written, not run: it needs a live instance with docudesk.
 *
 * @spec openspec/changes/sales-invoice-document/specs/bookkeeping-accounts-receivable-core/spec.md
 * @spec openspec/changes/sales-invoice-document/tasks.md#task-4.2
 *
 * @e2e bookkeeping-accounts-receivable-core::an-office-sets-its-house-style
 * @e2e bookkeeping-accounts-receivable-core::docudesk-is-not-installed
 * @e2e bookkeeping-accounts-receivable-core::an-english-customer-gets-an-english-invoice
 * @e2e bookkeeping-accounts-receivable-core::a-pound-invoice
 * @e2e bookkeeping-accounts-receivable-core::a-municipality-pays-by-scanning
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/** Open an issued invoice by its number from the AR invoices list. */
async function openInvoice(page: Page, invoiceNumber: string): Promise<void> {
	await page.goto(APP + '/ar-invoices')
	await page.waitForLoadState('domcontentloaded')
	await dismissOverlays(page)
	await page.getByRole('row', { name: new RegExp(invoiceNumber) }).first().click()
}

test.describe('sales-invoice-document: the invoice document', () => {
	test.fixme('an office sets its house style @spec REQ-SID-001', async ({ page }) => {
		// Task 4.1: the Invoice layout settings page with its preview and contrast check.
		await page.goto(APP + '/settings/invoice-layout')
		await dismissOverlays(page)
		await page.getByLabel(/Primary colour|Primaire kleur/i).fill('#1B4F72')
		await page.getByLabel(/Variant/i).selectOption('modern')
		await page.getByRole('button', { name: /Save|Opslaan/i }).click()
		await expect(page.getByTestId('invoice-layout-preview')).toHaveCSS('--invoice-primary', '#1B4F72')

		await page.getByLabel(/Primary colour|Primaire kleur/i).fill('#FFFF66')
		await page.getByRole('button', { name: /Save|Opslaan/i }).click()
		await expect(page.getByText(/contrast/i).first(), 'a pale primary colour is refused').toBeVisible()
	})

	test.fixme('without docudesk the invoice page says it is needed @spec REQ-SID-002', async ({ page }) => {
		// Task 2.2: generatePdf() becomes a docudesk consumer with the absence exception.
		await openInvoice(page, '2026-0042')
		await page.getByRole('button', { name: /PDF/i }).first().click()
		await expect(page.getByText(/docudesk/i).first()).toBeVisible({ timeout: 20_000 })
	})

	test.fixme('an English customer gets an English pound invoice @spec REQ-SID-003 @spec REQ-SID-004', async ({ page }) => {
		// Task 2.2: the PDF rendered by docudesk from InvoiceDocumentData.
		await openInvoice(page, 'Brightside')
		const [download] = await Promise.all([
			page.waitForEvent('download'),
			page.getByRole('button', { name: /PDF/i }).first().click(),
		])
		const text = (await download.createReadStream())?.read()?.toString('latin1') ?? ''
		expect(text.startsWith('%PDF')).toBe(true)
		for (const label of ['Invoice', 'Due date', 'Total']) {
			expect(text, label).toContain(label)
		}
		expect(text).toContain('4,800.00')
		expect(text).not.toContain('€')
	})

	test.fixme('a municipality pays by scanning @spec REQ-SID-005', async ({ request }) => {
		// Task 2.2: the rendered document; its data carries the EPC payload asserted here.
		const res = await request.get('/index.php/apps/shillinq/api/ar-invoices/2026-0042/document-data', { headers: { 'OCS-APIRequest': 'true' } })
		expect(res.status(), await res.text()).toBe(200)
		const body = await res.json() as { qr: { epc: string | null } }
		const fields = (body.qr.epc ?? '').split('\n')
		expect(fields.slice(0, 4)).toEqual(['BCD', '002', '1', 'SCT'])
		expect(fields[7]).toBe('EUR24200.00')
		expect(fields[10]).toBe('2026-0042')
	})
})
