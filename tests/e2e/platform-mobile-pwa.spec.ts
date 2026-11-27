/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shillinq Mobile at phone width, offline and back online.
 *
 * The journey the change's spec draws ("On a train without signal"): with the
 * network switched off a receipt (with its photo) and an invoice draft are
 * saved, both show Queued on the home screen, and once the network is back
 * both show Synced and exist exactly once in the register. A second test runs
 * axe over the three screens at phone width (task 4.2) and checks the touch
 * targets the change promises.
 *
 * Base URL and instance come from the environment (tests/e2e/base-url.ts via
 * playwright.config.ts); never a localhost fallback.
 *
 * Covers, from openspec/changes/platform-mobile-pwa:
 *   REQ-PMP-001 the home screen on /apps/shillinq/mobile
 *   REQ-PMP-002 a receipt with its photo
 *   REQ-PMP-003 queued offline, synced once when the connection returns
 *   task 4.1, task 4.2
 *
 * @spec openspec/changes/archive/2026-11-24-platform-mobile-pwa/specs/shillinq-mobile/spec.md
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as path from 'path'

const APP = '/apps/shillinq'

/** Distinctive enough that finding it proves the read, not a coincidence. */
const STAMP = `E2E-MOBILE-${Date.now()}`

/** A 1x1 PNG: enough of a photo for the receipt's attachment. */
const PHOTO = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
	'base64',
)

/** axe-core from the app's own dev dependencies. */
const AXE = path.resolve(process.cwd(), 'node_modules', 'axe-core', 'axe.min.js')

test.use({
	viewport: { width: 390, height: 844 },
	isMobile: true,
	hasTouch: true,
})

/**
 * Call a route from inside the page, carrying the session and its token.
 *
 * @param page   The page to run in.
 * @param method The HTTP verb.
 * @param url    The url.
 * @param body   The request body, for a write.
 *
 * @return The status and the parsed body.
 */
async function api(
	page: Page,
	method: string,
	url: string,
	body?: Record<string, unknown>,
): Promise<{ status: number; body: Record<string, unknown> }> {
	return await page.evaluate(
		async ({ method, url, body }) => {
			const res = await fetch(url, {
				method,
				headers: {
					'Content-Type': 'application/json',
					requesttoken:
						document
							.querySelector('head[data-requesttoken]')
							?.getAttribute('data-requesttoken') ?? '',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await res.text()
			let parsed: Record<string, unknown>
			try {
				parsed = JSON.parse(text)
			} catch {
				parsed = { raw: text.slice(0, 300) }
			}
			return { status: res.status, body: parsed }
		},
		{ method, url, body },
	)
}

/**
 * Open a mobile route and wait for its heading.
 *
 * @param page    The page.
 * @param route   The route below /apps/shillinq.
 * @param heading The screen's h1.
 */
async function openScreen(page: Page, route: string, heading: string): Promise<void> {
	await page.goto(APP + route, { waitUntil: 'domcontentloaded', timeout: 45_000 })
	await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible({ timeout: 20_000 })
}

/**
 * The register objects of a schema whose text holds this run's stamp.
 *
 * @param page   The page.
 * @param schema The schema slug.
 *
 * @return The matching objects.
 */
async function stamped(page: Page, schema: string): Promise<Array<Record<string, unknown>>> {
	const res = await api(
		page,
		'GET',
		`/index.php/apps/openregister/api/objects/shillinq/${schema}?_search=${encodeURIComponent(STAMP)}&_limit=50`,
	)
	expect(res.status, `could not list ${schema}`).toBeLessThan(400)
	const rows = (res.body.results ?? []) as Array<Record<string, unknown>>
	return rows.filter((row) => JSON.stringify(row).includes(STAMP))
}

test.describe('Shillinq Mobile at phone width', () => {
	// Two screens loaded online, two saves offline, one sync round.
	test.setTimeout(180_000)

	test('a receipt and an invoice draft saved offline are queued, then synced once', async ({
		page,
		context,
	}) => {
		// Online first: the invoice screen remembers the customers for later.
		await openScreen(page, '/mobile/invoice', 'Draft an invoice')
		const customer = page.getByTestId('mobile-invoice-customer')
		await expect(
			customer.locator('option[value]:not([value=""])').first(),
			'the instance needs at least one customer for an invoice draft',
		).toBeAttached({ timeout: 20_000 })

		await openScreen(page, '/mobile', 'Shillinq Mobile')
		await expect(page.getByRole('status')).toHaveText('Online')

		// No signal.
		await context.setOffline(true)
		await expect(page.getByRole('status')).toHaveText('Offline')

		await page.getByRole('link', { name: 'Capture a receipt' }).click()
		await expect(page.getByRole('heading', { level: 1, name: 'Capture a receipt' })).toBeVisible()
		await page.getByTestId('mobile-receipt-photo').setInputFiles({
			name: 'receipt.png',
			mimeType: 'image/png',
			buffer: PHOTO,
		})
		await page.getByTestId('mobile-receipt-amount').fill('24.20')
		await page.getByTestId('mobile-receipt-date').fill(new Date().toISOString().slice(0, 10))
		await page.getByTestId('mobile-receipt-category').selectOption('meals')
		await page.getByTestId('mobile-receipt-description').fill(STAMP)
		await page.getByTestId('mobile-receipt-save').click()
		await expect(page.getByRole('heading', { level: 1, name: 'Shillinq Mobile' })).toBeVisible()

		await page.getByRole('link', { name: 'Draft an invoice' }).click()
		await expect(page.getByRole('heading', { level: 1, name: 'Draft an invoice' })).toBeVisible()
		const firstCustomer = await customer.locator('option[value]:not([value=""])').first().getAttribute('value')
		await customer.selectOption(firstCustomer as string)
		await page.getByTestId('mobile-invoice-date').fill(new Date().toISOString().slice(0, 10))
		await page.getByTestId('mobile-invoice-reference').fill(STAMP)
		const line = page.locator('fieldset').first()
		await line.getByLabel('Description').fill(`Line ${STAMP}`)
		await line.getByLabel('Quantity').fill('1')
		await line.getByLabel('Price excluding VAT (EUR)').fill('20')
		await page.getByTestId('mobile-invoice-save').click()
		await expect(page.getByRole('heading', { level: 1, name: 'Shillinq Mobile' })).toBeVisible()

		// Both wait on the phone.
		const items = page.locator('.mobile-home__item')
		const receipt = items.filter({ hasText: 'EUR 24.20' })
		const draft = items.filter({ hasText: 'Invoice' })
		await expect(receipt.first()).toContainText('Queued')
		await expect(draft.first()).toContainText('Queued')

		// The connection returns: both are sent, and each exists once.
		await context.setOffline(false)
		await expect(page.getByRole('status')).toHaveText('Online')
		await expect(receipt.first()).toContainText('Synced', { timeout: 60_000 })
		await expect(draft.first()).toContainText(/Synced|Issue/, { timeout: 60_000 })

		await expect.poll(async () => (await stamped(page, 'Receipt')).length, { timeout: 20_000 }).toBe(1)
		await expect.poll(async () => (await stamped(page, 'ARInvoice')).length, { timeout: 20_000 }).toBe(1)
	})

	test('the three screens pass axe at phone width, with touch-sized targets', async ({ page }) => {
		for (const [route, heading] of [
			['/mobile', 'Shillinq Mobile'],
			['/mobile/receipt', 'Capture a receipt'],
			['/mobile/invoice', 'Draft an invoice'],
		]) {
			await openScreen(page, route, heading)
			await page.addScriptTag({ path: AXE })
			const violations = await page.evaluate(async () => {
				// @ts-expect-error axe is injected above
				const result = await window.axe.run(document.querySelector('.mobile-home, .mobile-form') ?? document, {
					runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'] },
				})
				return result.violations.map((v: { id: string; nodes: unknown[] }) => `${v.id} (${v.nodes.length})`)
			})
			expect(violations, `axe on ${route}`).toEqual([])

			// WCAG 2.2 2.5.8: every control on the screen is at least 24px, and the
			// change's own promise is 44px for the inputs and the main actions.
			const small = await page.evaluate(() => {
				const root = document.querySelector('.mobile-home, .mobile-form') ?? document.body
				return Array.from(root.querySelectorAll('a[href], button, input, select'))
					.filter((el) => (el as HTMLElement).offsetParent !== null)
					.filter((el) => (el as HTMLInputElement).type !== 'file')
					.map((el) => ({ el, box: el.getBoundingClientRect() }))
					.filter(({ box }) => box.height < 44 && box.width > 0)
					.map(({ el, box }) => `${el.tagName.toLowerCase()} ${Math.round(box.width)}x${Math.round(box.height)}`)
			})
			expect(small, `controls under 44px high on ${route}`).toEqual([])
		}
	})
})
