/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the one External Connections page that replaced the fifteen
 * per-adapter admin pages (integration-config-to-openconnector task 6,
 * REQ-ICO-002, REQ-ICO-003, REQ-ICO-007).
 *
 *   1. the roster lists the fifteen declared families: the page at
 *      /external-adapters renders one row per family (REQ-ICO-002);
 *   2. no per-adapter detail route: the fragment declares exactly one page
 *      and no page carries `config.adapterId` (REQ-ICO-002);
 *   3. a provisioned source, an undeclared source and OpenRegister being
 *      unavailable: the `provisioning` status per row (REQ-ICO-003);
 *   4. well-formed deep links per row and no test file naming a removed
 *      per-adapter route (REQ-ICO-007).
 *
 * WHAT CHANGED SINCE THE CHANGE WAS WRITTEN. adopt-connection-registry moved
 * the page onto integriq's `app_connection` rows (declared in
 * lib/Settings/connections.json) and removed `GET /api/admin/external-adapters`
 * together with its `provisioning` field and the "provision in OpenConnector"
 * deep link (appinfo/routes.php says so where the route was). The row count,
 * the single-page manifest and the route scan still hold and are asserted;
 * the provisioning and deep-link scenarios have no surface left to drive and
 * are test.fixme.
 *
 * The listing test asserts whichever state the instance is in: without
 * integriq the page renders the missing-dependency screen and the test is
 * skipped. The caller is the suite's administrator (globalSetup's storage
 * state). The spec writes nothing.
 *
 * Written, not run: it needs a live instance.
 *
 * @spec openspec/changes/integration-config-to-openconnector/specs/integration-config-to-openconnector/spec.md
 * @spec openspec/changes/integration-config-to-openconnector/tasks.md#task-6
 *
 * @e2e integration-config-to-openconnector::the-roster-page-lists-all-15-declared-families
 * @e2e integration-config-to-openconnector::no-per-adapter-detail-route-exists-any-more
 * @e2e integration-config-to-openconnector::a-provisioned-source-shows-its-slug-status
 * @e2e integration-config-to-openconnector::an-undeclared-source-falls-back-to-the-deep-link-prompt
 * @e2e integration-config-to-openconnector::openregister-unavailable-degrades-every-row-not-the-whole-page
 * @e2e integration-config-to-openconnector::every-row-s-deep-link-is-a-well-formed-url
 * @e2e integration-config-to-openconnector::no-test-file-references-a-removed-per-adapter-route
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'

const APP = '/apps/shillinq'
const HEADERS = { 'OCS-APIRequest': 'true' }
const ROOT = join(__dirname, '..', '..')

/** Integriq's objects endpoint for shillinq's connection rows. */
const CONNECTIONS_API = '/index.php/apps/openregister/api/objects/integriq/app_connection?app=shillinq&_limit=200'

/** One declared family in lib/Settings/connections.json. */
interface DeclaredConnection {
	key: string
	title: string
}

/** One row of integriq's app_connection listing. */
interface ConnectionRow {
	key: string
	app: string
	title: string
}

/** A manifest page, as far as this spec reads it. */
interface ManifestPage {
	id: string
	route?: string
	config?: { adapterId?: string }
}

/** A manifest menu node. */
interface ManifestMenu {
	id: string
	route?: string
	children?: ManifestMenu[]
}

/** A manifest fragment. */
interface ManifestFragment {
	menu?: ManifestMenu[]
	pages?: ManifestPage[]
}

/** The declaration integriq syncs, read from the repository. */
const declaration = JSON.parse(readFileSync(join(ROOT, 'lib', 'Settings', 'connections.json'), 'utf8')) as {
	app: string
	connections: DeclaredConnection[]
}

const FAMILY_KEYS = declaration.connections.map((connection) => String(connection.key))

/**
 * Read a manifest file from the repository.
 *
 * @param path The path from the repository root.
 * @return The parsed fragment.
 */
function manifest(path: string): ManifestFragment {
	return JSON.parse(readFileSync(join(ROOT, path), 'utf8')) as ManifestFragment
}

/**
 * Every file under a directory, recursively.
 *
 * @param dir The directory.
 * @return The file paths.
 */
function filesUnder(dir: string): string[] {
	const files: string[] = []
	for (const name of readdirSync(dir)) {
		if (name === 'node_modules' || name.startsWith('.')) {
			continue
		}
		const path = join(dir, name)
		if (statSync(path).isDirectory()) {
			files.push(...filesUnder(path))
		} else {
			files.push(path)
		}
	}
	return files
}

/**
 * Escape a string for use in a regular expression.
 *
 * @param value The literal.
 * @return The escaped literal.
 */
function escapeRegExp(value: string): string {
	return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
	const support = page.locator('[data-testid-modal="cn-support-dialog"], .cn-support-dialog').first()
	if (await support.isVisible().catch(() => false)) {
		await support.getByRole('button', { name: /close|sluiten|dismiss/i }).first().click().catch(() => {})
		await support.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/**
 * Open the page the way the menu entry does, and say which state it is in.
 *
 * @param page The page.
 * @return 'listed' when integriq serves the rows, 'missing' when it is not installed.
 */
async function openRoster(page: Page): Promise<'listed' | 'missing'> {
	await page.goto(`${APP}/external-adapters?app=shillinq`)
	await page.waitForLoadState('domcontentloaded')
	await dismissOverlays(page)

	const missing = page.locator('[data-testid="cn-page-dependency-missing"]')
	const list = page.locator('.cn-index-page')
	await expect.poll(async () => {
		if (await missing.isVisible().catch(() => false)) return 'missing'
		if (await list.isVisible().catch(() => false)) return 'listed'
		return 'loading'
	}, { timeout: 30_000 }).not.toBe('loading')

	return (await missing.isVisible().catch(() => false)) ? 'missing' : 'listed'
}

test.describe('integration-config-to-openconnector: the External Connections roster', () => {
	test('the roster page lists one row per declared family, fifteen in all @spec REQ-ICO-002', async ({ page, request }) => {
		expect(FAMILY_KEYS, 'the declaration holds the fifteen families').toHaveLength(15)

		const state = await openRoster(page)
		test.skip(state === 'missing', 'integriq is not installed on this instance, so the page shows the missing-dependency screen')

		const res = await request.get(CONNECTIONS_API, { headers: HEADERS })
		expect(res.status(), await res.text()).toBe(200)
		const rows = ((await res.json()).results ?? []) as ConnectionRow[]
		for (const row of rows) {
			expect(String(row.app), 'a connection row of another app').toBe('shillinq')
		}
		expect(rows.map((row) => String(row.key)).sort()).toEqual([...FAMILY_KEYS].sort())

		for (const connection of declaration.connections) {
			await expect(page.getByRole('row', { name: new RegExp(escapeRegExp(String(connection.title)), 'i') }).first(), connection.key)
				.toBeVisible({ timeout: 20_000 })
		}
	})

	test('the fragment declares one page and no per-adapter detail page @spec REQ-ICO-002', async ({ page }) => {
		const fragment = manifest('src/manifest.d/external-adapters-w8.json')
		const pages = fragment.pages ?? []
		expect(pages.map((entry) => entry.id)).toEqual(['ExternalAdaptersStatus'])
		expect(pages[0].route).toBe('/external-adapters')
		expect(pages.some((entry) => entry.config?.adapterId !== undefined), 'a page with config.adapterId').toBe(false)

		const menu = (fragment.menu ?? []).find((node) => node.id === 'ExternalConnections')
		expect(menu?.route, 'the menu entry is a single leaf').toBe('ExternalAdaptersStatus')
		expect(menu?.children ?? []).toHaveLength(0)

		const fragments = ['src/manifest.json', ...readdirSync(join(ROOT, 'src', 'manifest.d'))
			.filter((name) => name.endsWith('.json'))
			.map((name) => `src/manifest.d/${name}`)]
		for (const path of fragments) {
			for (const entry of manifest(path).pages ?? []) {
				expect(String(entry.route ?? ''), `${path}: ${entry.id}`).not.toMatch(/^\/external-adapters\/./)
			}
		}

		// A family's old detail path is not a page of its own any more.
		await page.goto(`${APP}/external-adapters/${FAMILY_KEYS[0]}`)
		await page.waitForLoadState('domcontentloaded')
		await dismissOverlays(page)
		await expect(page.locator('[data-testid="cn-detail-page"], .cn-detail-page')).toHaveCount(0)
	})

	// Missing: the `provisioning` field of `GET /api/admin/external-adapters`, removed with that route by adopt-connection-registry.
	test.fixme('a provisioned source shows its slug status @spec REQ-ICO-003', async () => {})

	// Missing: the "declared, provision in OpenConnector" row state, which went with the `provisioning` field.
	test.fixme('an undeclared source falls back to the deep-link prompt @spec REQ-ICO-003', async () => {})

	// Missing: `GET /api/admin/external-adapters`; integriq now works out each row's status.
	test.fixme('OpenRegister being unavailable degrades every row, not the whole page @spec REQ-ICO-003', async () => {})

	// Missing: the per-row "provision in OpenConnector" link; the page's only link column is integriq's settingsUrl.
	test.fixme('every row\'s deep link is a well-formed URL @spec REQ-ICO-007', async () => {})

	test('no test file references a removed per-adapter route @spec REQ-ICO-007', async () => {
		// Built from the declared keys so this file holds no such literal itself.
		const removed = new RegExp(`/external-adapters/(${FAMILY_KEYS.map(escapeRegExp).join('|')})\\b`)
		const offenders = filesUnder(join(ROOT, 'tests', 'e2e'))
			.filter((path) => /\.(ts|js|mjs)$/.test(path))
			.filter((path) => removed.test(readFileSync(path, 'utf8')))
		expect(offenders, 'test files naming a removed per-adapter route').toEqual([])
	})
})
