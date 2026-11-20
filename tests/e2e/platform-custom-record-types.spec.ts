/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Own record types (platform-custom-record-types REQ-CRT-001..003): an
 * administrator adds a schema to the shillinq register in Open Register,
 * shows it in the shillinq admin settings, and a user lists, adds and opens
 * its records on the generic /records/:schema pages.
 *
 * The server half (shipped slugs refused, the stored list) is proven by the
 * CustomRecordTypeService and CustomRecordTypeController unit tests; these
 * flows prove the pages and the admin-only write reach it on a live instance.
 *
 * Every fixture (the schema, its place in the register, the shown switch and
 * the bookkeeper account) is created here with a unique suffix and removed in
 * afterAll, so the spec leaves the instance as it found it.
 *
 * @spec openspec/changes/platform-custom-record-types/tasks.md#task-3.1
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const OR_API = '/index.php/apps/openregister/api'
const APP_API = '/index.php/apps/shillinq/api'
const SUFFIX = Date.now().toString(36)
const SCHEMA_SLUG = `parkeerplaats-${SUFFIX}`
const SCHEMA_TITLE = `Parkeerplaats ${SUFFIX}`
const BOOKKEEPER = `e2e-crt-${SUFFIX}`
const BOOKKEEPER_PASS = `Crt-${SUFFIX}-pass-2026!`

/** What afterAll has to undo. */
const created: {
	schemaId?: string | number
	registerId?: string | number
	registerSchemas?: Array<string | number>
	user?: boolean
} = {}

/**
 * The request token of the admin's browser session; Nextcloud refuses a
 * cookie-authenticated write without it.
 *
 * @param page An authenticated page.
 * @return The headers a write needs.
 */
async function writeHeaders(page: Page): Promise<Record<string, string>> {
	await page.goto('/index.php/apps/shillinq/')
	const requesttoken = await page.evaluate(() => (window as any).OC.requestToken)
	return { requesttoken, 'Content-Type': 'application/json' }
}

/**
 * The id of a register or schema entry, whichever shape the API returned.
 *
 * @param entry A list entry: an id, or an object carrying one.
 * @return The id.
 */
function idOf(entry: any): string | number {
	return typeof entry === 'object' && entry !== null ? entry.id : entry
}

/**
 * Create the Parkeerplaats schema and put it in the shillinq register.
 *
 * @param page An authenticated admin page.
 */
async function addOwnSchema(page: Page): Promise<void> {
	const headers = await writeHeaders(page)

	const schema = await page.request.post(`${OR_API}/schemas`, {
		headers,
		data: {
			title: SCHEMA_TITLE,
			slug: SCHEMA_SLUG,
			description: 'A parking space the administration rents out.',
			properties: {
				nummer: { type: 'string', title: 'Nummer' },
				huurder: { type: 'string', title: 'Huurder' },
			},
			required: ['nummer'],
		},
	})
	expect(schema.ok(), `create schema ${SCHEMA_SLUG}: ${schema.status()}`).toBe(true)
	created.schemaId = (await schema.json()).id

	const registers = await page.request.get(`${OR_API}/registers?_limit=2000`)
	expect(registers.ok(), 'list registers').toBe(true)
	const list = (await registers.json()).results ?? []
	const register = list.find((entry: any) => String(entry.slug).toLowerCase() === 'shillinq')
	expect(register, 'the shillinq register is installed').toBeTruthy()

	created.registerId = register.id
	created.registerSchemas = (register.schemas ?? []).map(idOf)
	const patched = await page.request.patch(`${OR_API}/registers/${register.id}`, {
		headers,
		data: { schemas: [...created.registerSchemas, created.schemaId] },
	})
	expect(patched.ok(), `add the schema to the register: ${patched.status()}`).toBe(true)
}

/**
 * A bookkeeper without admin rights, signed in over basic auth only: an
 * empty storage state, so none of the admin's cookies come along.
 *
 * @param baseURL The instance.
 * @return The bookkeeper's request context.
 */
async function bookkeeperContext(baseURL: string): Promise<APIRequestContext> {
	return playwrightRequest.newContext({
		baseURL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: { username: BOOKKEEPER, password: BOOKKEEPER_PASS },
		extraHTTPHeaders: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
	})
}

test.describe('platform-custom-record-types', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage({ storageState: 'tests/e2e/.auth/admin.json' })
		await addOwnSchema(page)

		const headers = await writeHeaders(page)
		const user = await page.request.post('/ocs/v2.php/cloud/users?format=json', {
			headers: { ...headers, 'OCS-APIRequest': 'true' },
			data: { userid: BOOKKEEPER, password: BOOKKEEPER_PASS },
		})
		expect(user.ok(), `create the bookkeeper: ${user.status()}`).toBe(true)
		created.user = true
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage({ storageState: 'tests/e2e/.auth/admin.json' })
		const headers = await writeHeaders(page)
		await page.request.patch(`${APP_API}/custom-record-types`, {
			headers,
			data: { slug: SCHEMA_SLUG, shown: false },
		}).catch(() => undefined)
		if (created.registerId !== undefined && created.registerSchemas !== undefined) {
			await page.request.patch(`${OR_API}/registers/${created.registerId}`, {
				headers,
				data: { schemas: created.registerSchemas },
			}).catch(() => undefined)
		}
		if (created.schemaId !== undefined) {
			await page.request.delete(`${OR_API}/schemas/${created.schemaId}`, { headers })
				.catch(() => undefined)
		}
		if (created.user === true) {
			await page.request.delete(`/ocs/v2.php/cloud/users/${BOOKKEEPER}`, {
				headers: { ...headers, 'OCS-APIRequest': 'true' },
			}).catch(() => undefined)
		}
		await page.close()
	})

	/**
	 * @e2e app-administration::an-administrator-shows-a-parking-space-register
	 */
	test('the admin settings list the own schema and not a shipped one', async ({ page }) => {
		const available = await page.request.get(`${APP_API}/custom-record-types/available`)
		expect(available.ok()).toBe(true)
		const slugs = ((await available.json()).types ?? []).map((type: any) => String(type.slug).toLowerCase())
		expect(slugs).toContain(SCHEMA_SLUG)
		expect(slugs).not.toContain('invoice')

		await page.goto('/index.php/settings/admin/shillinq')
		const row = page.getByRole('row', { name: new RegExp(SCHEMA_TITLE) })
		await expect(row).toBeVisible({ timeout: 30_000 })
		await expect(page.getByRole('row', { name: /^Invoice\b/ })).toHaveCount(0)

		await row.getByRole('switch').or(row.getByRole('checkbox')).first().click()
		await expect.poll(async () => {
			const shown = await page.request.get(`${APP_API}/custom-record-types`)
			return ((await shown.json()).types ?? []).map((type: any) => type.slug)
		}, { timeout: 15_000 }).toContain(SCHEMA_SLUG)
	})

	/**
	 * @e2e app-administration::a-user-adds-a-parking-space
	 */
	test('a user opens the card, adds a parking space and opens it', async ({ page }) => {
		await page.goto('/index.php/apps/shillinq/records')
		await expect(page.getByTestId('custom-record-types')).toBeVisible()
		await page.getByText(SCHEMA_TITLE, { exact: true }).click()
		await expect(page).toHaveURL(new RegExp(`/records/${SCHEMA_SLUG}$`, 'i'))

		await page.getByTestId('cn-cta-primary').or(
			page.getByRole('button', { name: /^(Add|New|Toevoegen|Nieuw)/i }),
		).first().click()
		const dialog = page.getByRole('dialog')
		await dialog.getByLabel(/Nummer/i).fill('P-12')
		await dialog.getByLabel(/Huurder/i).fill('Jansen Bouw')
		await dialog.getByRole('button', { name: /^(Save|Create|Add|Opslaan|Aanmaken)/i }).click()

		const record = page.getByRole('row', { name: /P-12/ })
		await expect(record).toBeVisible({ timeout: 20_000 })
		await record.click()
		await expect(page).toHaveURL(new RegExp(`/records/${SCHEMA_SLUG}/[^/]+$`, 'i'))
		await expect(page.getByText('Jansen Bouw')).toBeVisible()
	})

	/**
	 * @e2e app-administration::a-typed-url-for-a-shipped-schema
	 */
	test('a typed URL for a shipped schema shows the not-available state', async ({ page }) => {
		await page.goto('/index.php/apps/shillinq/records/Invoice')
		await expect(page.getByTestId('custom-record-types-unavailable')).toContainText('Invoice')
		await expect(page).toHaveURL(/\/records(\?|$)/)
	})

	/**
	 * @e2e app-administration::a-non-admin-tries-to-change-the-list
	 */
	test('a bookkeeper without admin rights is refused with 403', async () => {
		const bookkeeper = await bookkeeperContext(resolveBaseURL())
		try {
			const put = await bookkeeper.put(`${APP_API}/custom-record-types`, {
				data: { shown: [SCHEMA_SLUG] },
			})
			expect(put.status()).toBe(403)
			const patch = await bookkeeper.patch(`${APP_API}/custom-record-types`, {
				data: { slug: SCHEMA_SLUG, shown: false },
			})
			expect(patch.status()).toBe(403)

			// Reading the shown list stays open to every signed-in user.
			const read = await bookkeeper.get(`${APP_API}/custom-record-types`)
			expect(read.status()).toBe(200)
		} finally {
			await bookkeeper.dispose()
		}
	})
})
