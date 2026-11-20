/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Looking a company up in the KvK while adding a supplier or a customer.
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * KvkLookupDeclarationTest proves the two declarations exist and that every
 * fill source is a string the renderer reads. What it cannot see is whether
 * the schema-driven form really turns `kvkNumber` into the type-ahead, fills
 * the empty fields from a pick, asks before replacing a typed value, and
 * still saves a typed number when no source answers.
 *
 * THE KVK ITSELF IS NOT CALLED
 * ----------------------------
 * The type-ahead asks integriq (`/apps/integriq/api/property-sources/kvk/…`),
 * and integriq asks the configured KvK source. The supplier tests answer the
 * two integriq calls from a fixture in the shape integriq's kvk provider
 * resolves (a KvK Zoeken row, head office first), so they run without a KvK
 * key. They skip, with the reason, when integriq is not installed: the field
 * is then a plain text field on purpose and there is nothing to pick.
 *
 * The save is answered here too, so no supplier or customer is left behind;
 * the assertion is on the body the form posts.
 *
 * @spec openspec/changes/contacts-kvk-lookup/specs/contact-kvk-lookup/spec.md
 * @e2e contact-kvk-lookup/requirement-picking-a-company-fills-the-empty-details-req-kvkl-002/adding-a-supplier-from-the-kvk
 * @e2e contact-kvk-lookup/requirement-picking-a-company-fills-the-empty-details-req-kvkl-002/a-typed-name-is-not-overwritten-without-asking
 * @e2e contact-kvk-lookup/requirement-the-kvk-connection-is-on-and-says-what-it-needs-req-kvkl-003/without-a-source-the-field-still-works
 */

import type { Locator, Page, Route } from '@playwright/test'

import { expect, test } from '@playwright/test'

const SUPPLIERS = '/index.php/apps/shillinq/bookkeeping/payees'
const CUSTOMERS = '/index.php/apps/shillinq/bookkeeping/customers'
const SOURCE = '**/apps/integriq/api/property-sources/kvk/**'

/** The head-office row integriq's kvk provider resolves for 24502797. */
const ENECO = {
	kvkNummer: '24502797',
	naam: 'Eneco Energie B.V.',
	type: 'hoofdvestiging',
	adres: {
		binnenlandsAdres: {
			type: 'bezoekadres',
			straatnaam: 'Marten Meesweg',
			huisnummer: 5,
			postcode: '3068AV',
			plaats: 'Rotterdam',
		},
	},
}

/**
 * Answer integriq's suggest and resolve calls from the fixture.
 *
 * @param page The page.
 */
async function linkTheKvkSource(page: Page): Promise<void> {
	await page.route(SOURCE, (route: Route) => {
		const url = new URL(route.request().url())
		if (url.pathname.endsWith('/suggest')) {
			return route.fulfill({
				json: { results: [{ identifier: ENECO.kvkNummer, label: `${ENECO.naam} (${ENECO.kvkNummer})` }] },
			})
		}
		return route.fulfill({
			json: { value: ENECO, provenance: { provider: 'kvk', readAt: new Date().toISOString() } },
		})
	})
}

/**
 * Capture the object the form posts to OpenRegister and answer it as saved.
 *
 * @param page   The page.
 * @param schema The schema slug the form saves into.
 * @return The posted bodies.
 */
async function captureSave(page: Page, schema: string): Promise<Array<Record<string, unknown>>> {
	const posted: Array<Record<string, unknown>> = []
	await page.route(`**/apps/openregister/api/objects/*/${schema}**`, (route: Route) => {
		const request = route.request()
		if (request.method() !== 'POST' && request.method() !== 'PUT') {
			return route.fallback()
		}
		const body = request.postDataJSON() as Record<string, unknown>
		posted.push(body)
		return route.fulfill({ json: { ...body, id: `e2e-${Date.now()}` } })
	})
	return posted
}

/**
 * Open the index page's own create dialog. The button is labelled from the
 * schema ("Add …"), the dialog renders in a body-level portal.
 *
 * @param page The page.
 * @param path The index page.
 * @return The dialog.
 */
async function openCreateDialog(page: Page, path: string): Promise<Locator> {
	await page.goto(path)
	await page.getByRole('main').getByRole('button', { name: /^Add\b/i }).first().click()
	const dialog = page.getByRole('dialog').last()
	await expect(dialog).toBeVisible({ timeout: 15_000 })
	return dialog
}

/**
 * Fill the required text fields this test does not assert on, so Create
 * enables. A field the form does not show is left alone.
 *
 * @param dialog The create dialog.
 * @param values Accessible-name prefix to value.
 */
async function fillRequired(dialog: Locator, values: Record<string, string>): Promise<void> {
	for (const [label, value] of Object.entries(values)) {
		const box = dialog.getByRole('textbox', { name: new RegExp(`^${label}`, 'i') }).first()
		if (await box.count() > 0 && (await box.inputValue()) === '') {
			await box.fill(value)
		}
	}
}

/**
 * Whether the KvK number field is the type-ahead (integriq installed) rather
 * than the plain text field.
 *
 * @param dialog The create dialog.
 * @return True for the type-ahead.
 */
async function isTypeAhead(dialog: Locator): Promise<boolean> {
	return (await dialog.getByRole('combobox', { name: /KvK Number/i }).count()) > 0
}

/**
 * Type into the type-ahead and pick the first suggestion.
 *
 * @param page   The page (the listbox renders in a portal).
 * @param dialog The create dialog.
 * @param query  What the user types.
 */
async function pickFromTheKvk(page: Page, dialog: Locator, query: string): Promise<void> {
	await dialog.getByRole('combobox', { name: /KvK Number/i }).fill(query)
	const option = page.getByRole('option').filter({ hasText: ENECO.naam }).first()
	await expect(option).toBeVisible({ timeout: 10_000 })
	await option.click()
}

test.describe('contacts-kvk-lookup — adding a supplier from the KvK', () => {
	test('a pick fills the empty details and the save stores them', async ({ page }) => {
		await linkTheKvkSource(page)
		const posted = await captureSave(page, 'Payee')
		const dialog = await openCreateDialog(page, SUPPLIERS)
		test.skip(!(await isTypeAhead(dialog)), 'integriq is not installed, so KvK number is a plain field by design')

		await pickFromTheKvk(page, dialog, 'Eneco')

		await expect(dialog.getByRole('textbox', { name: /^Name/i }).first()).toHaveValue(ENECO.naam)
		await expect(dialog.getByRole('textbox', { name: /^Street/i }).first()).toHaveValue('Marten Meesweg')
		await expect(dialog.getByRole('textbox', { name: /^Postcode/i }).first()).toHaveValue('3068AV')
		await expect(dialog.getByRole('textbox', { name: /^City/i }).first()).toHaveValue('Rotterdam')

		await fillRequired(dialog, { 'Vendor Number': 'E2E-KVK-1', 'Administration ID': 'e2e-administration', 'Payment Term Days': '30' })
		await dialog.getByRole('button', { name: 'Create', exact: true }).click()

		await expect.poll(() => posted.length, { timeout: 15_000 }).toBeGreaterThan(0)
		const saved = posted[posted.length - 1]
		expect(saved.kvkNumber).toBe(ENECO.kvkNummer)
		expect(saved.name).toBe(ENECO.naam)
		expect(saved.address).toMatchObject({ street: 'Marten Meesweg', postcode: '3068AV', city: 'Rotterdam' })
	})

	test('a typed name is kept when the user declines the replace question', async ({ page }) => {
		await linkTheKvkSource(page)
		const dialog = await openCreateDialog(page, SUPPLIERS)
		test.skip(!(await isTypeAhead(dialog)), 'integriq is not installed, so KvK number is a plain field by design')

		const name = dialog.getByRole('textbox', { name: /^Name/i }).first()
		await name.fill('Eneco')
		await pickFromTheKvk(page, dialog, 'Eneco')

		const question = page.getByTestId('cn-replace-values-dialog')
		await expect(question).toBeVisible({ timeout: 10_000 })
		await expect(question).toContainText('Eneco Energie B.V.')
		await page.getByTestId('cn-replace-values-decline').click()

		await expect(question).toBeHidden()
		await expect(name).toHaveValue('Eneco')
		// The empty fields still filled; only the typed one was asked about.
		await expect(dialog.getByRole('textbox', { name: /^City/i }).first()).toHaveValue('Rotterdam')
	})
})

test.describe('contacts-kvk-lookup — without a KvK source', () => {
	test('a typed number saves and the form says why there is no lookup', async ({ page, request }) => {
		const probe = await request.get('/index.php/apps/integriq/api/property-sources/kvk/suggest?q=12345678', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		test.skip(probe.ok(), 'a KvK source is linked on this instance; this scenario needs none')

		const posted = await captureSave(page, 'CustomerMaster')
		const dialog = await openCreateDialog(page, CUSTOMERS)

		if (await isTypeAhead(dialog)) {
			// integriq is installed but has no source: the first search degrades
			// the field to plain text with the reason.
			await dialog.getByRole('combobox', { name: /KvK Number/i }).fill('12345678')
		}
		const field = dialog.getByRole('textbox', { name: /KvK Number/i }).first()
		await expect(field).toBeVisible({ timeout: 10_000 })
		await field.fill('12345678')
		await expect(dialog.getByText(/not available|did not answer|cannot look this up/i).first()).toBeVisible()

		await fillRequired(dialog, {
			'Customer ID': 'E2E-KVK-2',
			'Legal Name': 'Acme Gemeente B.V.',
			Email: 'e2e-kvk@example.invalid',
			'Administration ID': 'e2e-administration',
		})
		await dialog.getByRole('button', { name: 'Create', exact: true }).click()

		await expect.poll(() => posted.length, { timeout: 15_000 }).toBeGreaterThan(0)
		expect(posted[posted.length - 1].kvkNumber).toBe('12345678')
	})
})
