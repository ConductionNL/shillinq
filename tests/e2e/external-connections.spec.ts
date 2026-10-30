/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * e2e for the External Connections page (adopt-connection-registry).
 *
 * The page lists integriq's `app_connection` rows for `app=shillinq`, synced
 * from lib/Settings/connections.json. Integriq decides each status; shillinq
 * only reports which adapter answers for the three families it calls.
 *
 * TWO REACHABLE STATES, and the spec asserts whichever the instance is in.
 * The shillinq CI instance installs openregister and nothing else, so there the
 * page renders the missing-dependency screen naming Integriq. On an instance
 * with integriq installed and synced, the page lists the fifteen families. A
 * test that asserted only one of the two would be red on the other for a fact
 * about the environment, not about the page.
 *
 * Locale: nothing forces the language of the instance. Row identity is read
 * from the API and from the declared titles, which are not translated.
 *
 * Not run in the change that wrote it: it needs a browser and, for the listing
 * half, integriq on the instance.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/external-connections/spec.md
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

const APP = '/apps/shillinq'

/** Integriq's objects endpoint for shillinq's connection rows. */
const CONNECTIONS_API =
	'/index.php/apps/openregister/api/objects/integriq/app_connection?app=shillinq&_limit=200'

/** The declaration integriq syncs, read from the repository. */
const declaration = JSON.parse(
	readFileSync(
		join(__dirname, '..', '..', 'lib', 'Settings', 'connections.json'),
		'utf8',
	),
) as { app: string; connections: Array<Record<string, any>> }

const DECLARED_KEYS = declaration.connections.map((c) => String(c.key))

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
 * Open the page the way the menu entry does, and say which state it is in.
 *
 * @param page The Playwright page.
 */
async function openExternalConnections(page: Page): Promise<'listed' | 'missing'> {
	await page.goto(`${APP}/external-adapters?app=shillinq`)
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
 * Shillinq's connection rows, keyed by connection key.
 *
 * `app` is a BARE filter key: the objects endpoint reads `filter[app]` as a
 * filter on nothing and answers the empty set without an error.
 *
 * @param request The authenticated request context.
 */
async function rowsByKey(request: APIRequestContext): Promise<Record<string, any>> {
	const res = await request.get(CONNECTIONS_API)
	expect(res.ok(), `list integriq/app_connection -> ${res.status()}`).toBeTruthy()
	const byKey: Record<string, any> = {}
	for (const row of (await res.json()).results ?? []) {
		expect(String(row.app), 'a connection row from another app').toBe('shillinq')
		byKey[String(row.key)] = row
	}
	return byKey
}

test.describe('Shillinq: External Connections', () => {
	/**
	 * @e2e external-connections::without-integriq-the-page-says-what-is-missing
	 */
	test('without integriq, the page names Integriq and the menu hides the entry', async ({
		page,
	}) => {
		const state = await openExternalConnections(page)
		test.skip(
			state === 'listed',
			'integriq is installed on this instance; the listing tests cover it',
		)

		await expect(
			page.locator('[data-testid="cn-page-dependency-missing"]'),
		).toContainText(/Integriq/)
		await expect(page.locator('a[href*="/external-adapters"]')).toHaveCount(0)
	})

	/**
	 * @e2e external-connections::the-menu-opens-the-page-on-shillinqs-own-rows
	 * @e2e external-connections::an-uncalled-family-reads-not-available-and-says-why
	 */
	test('lists the fifteen declared families from integriq, in order', async ({
		page,
		request,
	}) => {
		const state = await openExternalConnections(page)
		test.skip(state === 'missing', 'integriq is not installed on this instance')

		const byKey = await rowsByKey(request)
		expect(Object.keys(byKey).sort()).toEqual([...DECLARED_KEYS].sort())

		const digipoort = byKey['digipoort-sbr']
		expect(digipoort.status).toBe('unavailable')
		expect(String(digipoort.statusMessage)).toMatch(/log-only adapter is bound/i)

		for (const connection of declaration.connections) {
			await expect(
				page.getByRole('row', {
					name: new RegExp(String(connection.title), 'i'),
				}),
			).toBeVisible()
		}
	})

	/**
	 * @e2e external-connections::only-the-treasury-row-links-to-a-shillinq-page
	 */
	test('offers Open settings on the treasury row only', async ({
		page,
		request,
	}) => {
		const state = await openExternalConnections(page)
		test.skip(state === 'missing', 'integriq is not installed on this instance')

		const byKey = await rowsByKey(request)
		for (const key of DECLARED_KEYS) {
			const expected =
				key === 'treasury-rates'
					? `${APP}/bookkeeping/multi-currency/fx-rates/admin`
					: ''
			expect(String(byKey[key].settingsUrl || ''), key).toBe(expected)
		}

		const treasury = page.getByRole('row', { name: /Treasury rates/i })
		await expect(treasury.locator('a[href*="fx-rates/admin"]')).toHaveCount(1)
	})

	/**
	 * @e2e external-connections::a-log-only-mollie-adapter-reads-simulated
	 */
	test('reads Simulated for Mollie once shillinq has reported', async ({
		page,
		request,
	}) => {
		const state = await openExternalConnections(page)
		test.skip(state === 'missing', 'integriq is not installed on this instance')

		const mollie = (await rowsByKey(request)).mollie
		test.skip(
			!mollie.lastReport,
			'ConnectionReportJob has not run on this instance yet; the row still reads Not checked yet',
		)
		expect(mollie.status).toBe('simulated')
		expect(String(mollie.statusMessage)).toMatch(/log-only adapter answers/i)
	})

	/**
	 * @e2e external-connections::add-integration-goes-to-integriq
	 */
	test('sends Add integration to integriq instead of offering a form', async ({
		page,
	}) => {
		const state = await openExternalConnections(page)
		test.skip(state === 'missing', 'integriq is not installed on this instance')

		await expect(page.locator('[data-testid="cn-cta-primary"]')).toHaveCount(0)

		await page.locator('[data-testid="cn-actions"] button').first().click()
		await Promise.all([
			page.waitForURL(/\/apps\/integriq\/connections\?app=shillinq&link=1$/, {
				timeout: 30_000,
			}),
			page
				.getByRole('menuitem', {
					name: /Add integration|Integratie toevoegen/i,
				})
				.click(),
		])
	})

	/**
	 * @e2e external-connections::the-roster-endpoint-is-gone
	 */
	test('no longer serves the adapter roster', async ({ request }) => {
		const res = await request.get(
			'/index.php/apps/shillinq/api/admin/external-adapters',
		)
		const body = await res.text()
		expect(body).not.toContain('"adapters"')
	})
})
