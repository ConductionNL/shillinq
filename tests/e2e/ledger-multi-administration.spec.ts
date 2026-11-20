/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ledger-multi-administration (task 4.1): a bookkeeper with two
 * administrations switches to the second, the choice survives a reload and a
 * fresh session, the journals list shows only the active administration's
 * entries, and a new administration starts from an office template saved from
 * the first.
 *
 * Every object is created under this run's stamp, with the logged-in user as
 * a member, and removed afterwards. The target comes from PLAYWRIGHT_BASE_URL
 * (base-url.ts, through playwright.config.ts); there is no localhost default.
 *
 * @e2e bookkeeping-multi-administratie::a-bookkeeper-switches-and-reloads
 * @e2e bookkeeping-multi-administratie::the-general-ledger-list-follows-the-switch
 * @e2e bookkeeping-multi-administratie::the-office-saves-its-bakery-chart
 * @e2e bookkeeping-multi-administratie::a-new-client-starts-from-the-office-template
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const APP = '/index.php/apps/shillinq'
const API = `${APP}/api`
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const STAMP = Date.now()

/** What this run created, as schema and id, removed in reverse order. */
const created: Array<{ schema: string; id: string }> = []

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
 * Create one object, remember it for clean-up, and return its id.
 *
 * @param page   The page to run in.
 * @param schema The schema.
 * @param data   The object.
 *
 * @return The created id.
 */
async function seed(
	page: Page,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const res = await api(page, 'POST', `${OBJECTS}/${schema}`, data)
	expect(
		res.status,
		`could not seed ${schema}: ${JSON.stringify(res.body).slice(0, 300)}`,
	).toBeLessThan(400)
	const body = res.body as Record<string, never>
	const id = String(body?.id ?? body?.['@self']?.id ?? '')
	created.push({ schema, id })
	return id
}

/**
 * Open the app and wait for the shell, past a first-run wizard.
 *
 * @param page  The page.
 * @param route The app route, from the app root.
 *
 * @return Resolves once the page has loaded.
 */
async function open(page: Page, route = '/'): Promise<void> {
	await page.goto(`${APP}${route}`)
	await page.waitForLoadState('domcontentloaded')
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/**
 * An administration with the current user as member.
 *
 * @param page   The page.
 * @param userId The member.
 * @param name   Its name.
 * @param code   Its code.
 *
 * @return The administration's id.
 */
async function administration(
	page: Page,
	userId: string,
	name: string,
	code: string,
): Promise<string> {
	const id = await seed(page, 'Administration', {
		administrationCode: code,
		name,
		legalForm: 'bv',
		status: 'actief',
	})
	await seed(page, 'AdministrationMembership', {
		userId,
		administrationId: id,
		role: 'eigenaar',
	})
	return id
}

/**
 * A posted two-line journal entry in an administration.
 *
 * @param page             The page.
 * @param administrationId The administration.
 * @param description      Its description, which the list shows.
 *
 * @return The entry's id.
 */
async function journal(
	page: Page,
	administrationId: string,
	description: string,
): Promise<string> {
	return await seed(page, 'JournalEntry', {
		journalNumber: `E2E-${STAMP}-${administrationId.slice(0, 6)}`,
		entryDate: '2026-11-02',
		description,
		journalType: 'manual',
		approvalState: 'not-required',
		state: 'draft',
		administrationId,
		lines: [
			{ accountNumber: '1000', side: 'debit', amount: 10 },
			{ accountNumber: '8000', side: 'credit', amount: 10 },
		],
	})
}

test.describe('Working in more than one administration', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(180_000)

	const bakery = `Bakkerij e2e ${STAMP}`
	const salon = `Kapsalon e2e ${STAMP}`
	let bakeryId = ''
	let salonId = ''

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await open(page)
		for (const { schema, id } of created.reverse()) {
			if (id !== '') {
				await api(page, 'DELETE', `${OBJECTS}/${schema}/${id}`)
			}
		}
		await page.close()
	})

	test('the switch to the second administration survives a reload and a new session', async ({
		page,
		browser,
	}) => {
		await open(page)
		const context = await api(page, 'GET', `${API}/administrations/context`)
		expect(context.status).toBe(200)
		const userId = String(context.body.userId ?? '')
		expect(userId).not.toBe('')

		bakeryId = await administration(page, userId, bakery, `B${STAMP}`)
		salonId = await administration(page, userId, salon, `K${STAMP}`)

		await open(page, '/administratie/wisselen')
		const switcher = page.getByRole('region', { name: 'Switch administration' })
		await expect(switcher).toBeVisible({ timeout: 30_000 })
		await switcher.getByRole('button').first().click()
		await page.getByRole('menuitem', { name: new RegExp(salon) }).click()

		await page.waitForLoadState('domcontentloaded')
		await expect(
			page.getByRole('region', { name: 'Switch administration' }),
		).toContainText(salon, { timeout: 30_000 })
		await page.reload()
		await expect(
			page.getByRole('region', { name: 'Switch administration' }),
		).toContainText(salon, { timeout: 30_000 })

		// A new session of the same user reads the stored choice, not the membership order.
		const fresh = await browser.newContext({
			baseURL: resolveBaseURL(),
			storageState: await page.context().storageState(),
		})
		const again = await fresh.newPage()
		await open(again)
		const stored = await api(again, 'GET', `${API}/administrations/context`)
		expect(stored.body.activeAdministrationId).toBe(salonId)
		await fresh.close()
	})

	test('the journals list shows only the active administration', async ({
		page,
	}) => {
		await open(page)
		await journal(page, bakeryId, `Bakery sale ${STAMP}`)
		await journal(page, salonId, `Salon sale ${STAMP}`)

		await open(page, '/journals')
		await expect(page.getByText(`Salon sale ${STAMP}`).first()).toBeVisible({
			timeout: 30_000,
		})
		await expect(page.getByText(`Bakery sale ${STAMP}`)).toHaveCount(0)
	})

	test('a new administration starts from the template saved from the first', async ({
		page,
	}) => {
		await open(page)
		await seed(page, 'Account', {
			accountNumber: '8050',
			name: `Omzet catering ${STAMP}`,
			accountType: 'revenue',
			currency: 'EUR',
			administrationId: bakeryId,
			lifecycleState: 'active',
		})

		const saved = await api(
			page,
			'POST',
			`${API}/administrations/${bakeryId}/template`,
			{ name: `Kantoor e2e ${STAMP}` },
		)
		expect(saved.status, JSON.stringify(saved.body).slice(0, 300)).toBe(201)
		const templateId = String(
			saved.body.id
				?? (saved.body['@self'] as Record<string, unknown>)?.id
				?? '',
		)
		created.push({ schema: 'AdministrationTemplate', id: templateId })
		expect(saved.body.version).toBe(1)
		const accounts = (saved.body.accounts ?? []) as Array<
			Record<string, unknown>
		>
		expect(accounts.map((row) => row.accountNumber)).toContain('8050')
		// A template carries the chart, never the bookings.
		expect(saved.body).not.toHaveProperty('journalEntries')

		const listed = await api(page, 'GET', `${API}/administration-templates`)
		expect(
			(listed.body.results as Array<Record<string, unknown>>).map(
				(row) => row.name,
			),
		).toContain(`Kantoor e2e ${STAMP}`)

		const context = await api(page, 'GET', `${API}/administrations/context`)
		const lunchroomId = await administration(
			page,
			String(context.body.userId),
			`Lunchroom e2e ${STAMP}`,
			`L${STAMP}`,
		)
		const seeded = await api(
			page,
			'POST',
			`${API}/administrations/${lunchroomId}/seed-template`,
			{ templateId },
		)
		expect(seeded.status, JSON.stringify(seeded.body).slice(0, 300)).toBe(200)

		const chart = await api(
			page,
			'GET',
			`${OBJECTS}/Account?administrationId=${lunchroomId}&_limit=200`,
		)
		const rows = (chart.body.results ?? []) as Array<Record<string, unknown>>
		for (const row of rows) {
			created.push({
				schema: 'Account',
				id: String(
					row.id ?? (row['@self'] as Record<string, unknown>)?.id ?? '',
				),
			})
		}
		expect(rows.find((row) => row.accountNumber === '8050')?.name).toBe(
			`Omzet catering ${STAMP}`,
		)
	})

	test('a visitor without a session gets no administration context', async ({
		browser,
	}) => {
		const anonymous = await browser.newContext({
			baseURL: resolveBaseURL(),
			storageState: { cookies: [], origins: [] },
		})
		const res = await anonymous.request.get(`${API}/administrations/context`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(res.status()).toBeGreaterThanOrEqual(400)
		await anonymous.close()
	})
})
