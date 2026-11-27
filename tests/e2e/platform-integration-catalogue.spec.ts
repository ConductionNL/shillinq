/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * e2e for the Integrations page (platform-integration-catalogue, task 2.2).
 *
 * The page lists integriq's `catalog_item` rows in the six bookkeeping
 * categories as cards, and under them integriq's `app_connection` rows for
 * `app=shillinq` as "Not available yet". Without integriq it names Integriq
 * instead of showing an empty grid.
 *
 * WITH AND WITHOUT INTEGRIQ, and the spec asserts whichever the instance is
 * in, as external-connections.spec.ts does. The shillinq CI instance installs
 * openregister only, so there the dependency screen renders and the listing
 * tests skip; on an instance with integriq they run and the dependency test
 * skips. Each skip names the fact about the environment that caused it.
 *
 * Rows are read from the API and compared with what the page draws, so the
 * spec holds for whatever catalogue the instance carries. Locale: nothing
 * forces the language; item names and connection titles are data, not strings.
 *
 * Not run in the change that wrote it (decision 139): the live pass runs it.
 *
 * @spec openspec/changes/archive/2026-11-24-platform-integration-catalogue/specs/integration-catalogue/spec.md
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'

/** The six bookkeeping categories the page opens on (REQ-PIC-001). */
const CATEGORIES = ['Bank', 'Payments', 'E-invoicing', 'Tax filing', 'Commerce', 'Payroll']

/** Integriq's objects endpoints. */
const ITEMS_API = '/index.php/apps/openregister/api/objects/integriq/catalog_item?_limit=500'
const CONNECTIONS_API =
	'/index.php/apps/openregister/api/objects/integriq/app_connection?app=shillinq&_limit=200'

/** Dismiss the first-run wizard or support dialog if it covers the page. */
async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
	const support = page
		.locator('[data-testid-modal="cn-support-dialog"], .cn-support-dialog')
		.first()
	if (await support.isVisible().catch(() => false)) {
		await support
			.getByRole('button', { name: /close|sluiten|dismiss/i })
			.first()
			.click()
			.catch(() => {})
		await support.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/**
 * Open the Integrations page and say which state it is in.
 *
 * @param page The Playwright page.
 * @return 'listed' with integriq, 'missing' without.
 */
async function openIntegrations(page: Page): Promise<'listed' | 'missing'> {
	await page.goto(`${APP}/integrations`)
	await page.waitForLoadState('domcontentloaded')
	await dismissOverlays(page)

	const missing = page.locator('[data-testid="cn-page-dependency-missing"]')
	const list = page.locator('.cn-index-page')

	await expect
		.poll(
			async () => {
				if (await missing.isVisible().catch(() => false)) return 'missing'
				if (await list.isVisible().catch(() => false)) return 'listed'
				return 'loading'
			},
			{ timeout: 30_000 },
		)
		.not.toBe('loading')

	return (await missing.isVisible().catch(() => false)) ? 'missing' : 'listed'
}

/**
 * The catalogue items in the bookkeeping categories.
 *
 * @param request The authenticated request context.
 * @return The items.
 */
async function bookkeepingItems(request: APIRequestContext): Promise<any[]> {
	const res = await request.get(ITEMS_API)
	expect(res.ok(), `list integriq/catalog_item -> ${res.status()}`).toBeTruthy()
	const rows = ((await res.json()).results ?? []) as any[]

	return rows.filter((row) => CATEGORIES.includes(String(row.category ?? '')))
}

/**
 * Shillinq's connection rows.
 *
 * @param request The authenticated request context.
 * @return The rows.
 */
async function connectionRows(request: APIRequestContext): Promise<any[]> {
	const res = await request.get(CONNECTIONS_API)
	expect(res.ok(), `list integriq/app_connection -> ${res.status()}`).toBeTruthy()

	return ((await res.json()).results ?? []) as any[]
}

test.describe('Shillinq: Integrations', () => {
	/**
	 * @e2e openspec/changes/archive/2026-11-24-platform-integration-catalogue/specs/integration-catalogue/spec.md#scenario-an-instance-without-integriq
	 */
	test('without integriq, the page says integrations come from Integriq', async ({ page }) => {
		const state = await openIntegrations(page)
		test.skip(state === 'listed', 'integriq is installed on this instance; the listing tests cover it')

		await expect(page.locator('[data-testid="cn-page-dependency-missing"]')).toContainText(/Integriq/)
		await expect(page.locator('.cn-card-grid__grid')).toHaveCount(0)
	})

	/**
	 * @e2e openspec/changes/archive/2026-11-24-platform-integration-catalogue/specs/integration-catalogue/spec.md#scenario-an-administrator-looks-for-a-payment-provider
	 */
	test('a bookkeeping catalogue item shows as a card with its status and opens in integriq', async ({
		page,
		request,
	}) => {
		const state = await openIntegrations(page)
		test.skip(state === 'missing', 'integriq is not installed on this instance')

		const items = await bookkeepingItems(request)
		test.skip(items.length === 0, 'integriq has no catalogue item in a bookkeeping category yet')

		// Mollie when the catalogue has it (the scenario's example), else the first item.
		const item = items.find((row) => /mollie/i.test(String(row.name ?? ''))) ?? items[0]
		const name = String(item.name)

		const card = page.locator('.cn-object-card').filter({ hasText: name }).first()
		await expect(card).toBeVisible({ timeout: 30_000 })
		await expect(card).toContainText(String(item.category))
		if (String(item.status ?? '') !== '') {
			await expect(card).toContainText(String(item.status))
		}

		// No card outside the six categories.
		const others = ((await (await request.get(ITEMS_API)).json()).results ?? [])
			.filter((row: any) => !CATEGORIES.includes(String(row.category ?? '')))
			.map((row: any) => String(row.name ?? ''))
			.filter((other: string) => other !== '' && !items.some((row) => String(row.name) === other))
		for (const other of others.slice(0, 5)) {
			await expect(page.locator('.cn-object-card').filter({ hasText: other })).toHaveCount(0)
		}

		// Open in Integriq lands on integriq's store, searched on the item's name.
		await card.hover()
		const action = card.getByRole('button', { name: /Open in Integriq|Openen in Integriq/ })
		if (!(await action.isVisible().catch(() => false))) {
			await card.getByRole('button', { name: /actions|acties/i }).first().click()
		}
		await page.getByRole('menuitem', { name: /Open in Integriq|Openen in Integriq/ }).or(action).first().click()
		await expect(page).toHaveURL(/\/apps\/integriq\/store/, { timeout: 30_000 })
		expect(new URL(page.url()).searchParams.get('_search')).toBe(name)
	})

	/**
	 * @e2e openspec/changes/archive/2026-11-24-platform-integration-catalogue/specs/integration-catalogue/spec.md#scenario-digipoort-is-not-in-the-catalogue
	 */
	test('a connection family without a catalogue item is listed as not available yet, with its reason', async ({
		page,
		request,
	}) => {
		const state = await openIntegrations(page)
		test.skip(state === 'missing', 'integriq is not installed on this instance')

		const rows = await connectionRows(request)
		test.skip(rows.length === 0, 'integriq has not synced shillinq\'s connections on this instance')

		const digipoort = rows.find((row) => String(row.key) === 'digipoort-sbr') ?? rows[0]
		const section = page.locator('.cn-index-page').getByText(/Not available yet|Nog niet beschikbaar/).first()
		await expect(section).toBeVisible({ timeout: 30_000 })

		const line = page.locator('tr').filter({ hasText: String(digipoort.title) }).first()
		await expect(line).toBeVisible()
		if (String(digipoort.statusMessage ?? '') !== '') {
			await expect(line).toContainText(String(digipoort.statusMessage))
		}
	})
})
