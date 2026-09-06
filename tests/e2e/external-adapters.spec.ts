import type { ConsoleMessage, Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * e2e for the integration-config-to-openconnector "External Connections"
 * roster (openspec/changes/integration-config-to-openconnector).
 *
 * Replaces the former W8 index + 15-per-adapter-detail-page coverage
 * (REQ-ICO-007 — this file, tests/e2e/visual/external-adapters.visual.spec.ts
 * and tests/e2e/workflows/external-adapters-admin.spec.ts together replace
 * the three specs that exercised the now-removed per-adapter surfaces): all
 * 15 families now render as rows on ONE page
 * (src/views/external-adapters/ExternalAdaptersStatus.vue), sourced from
 * GET /api/admin/external-adapters (ExternalAdaptersAdminController#index).
 * Integration configuration (credentials, endpoints, protocol mapping)
 * belongs to openconnector per ADR-067/ADR-091/ADR-022 — this app only ever
 * shows the declared source-slug reference, the dormant/live verdict, and
 * the live openconnector-provisioning verdict (REQ-ICO-003, covered in the
 * workflows spec with mocked responses for its three provisioning states).
 *
 * This file asserts:
 *   1. The roster lists all 15 declared families (REQ-ICO-002).
 *   2. No per-adapter detail route exists any more — the old
 *      `/external-adapters/<family-id>` deep links no longer resolve to a
 *      detail panel (REQ-ICO-002 / REQ-ICO-004).
 *   3. Every row's "provision in OpenConnector" deep link is a well-formed
 *      URL (REQ-ICO-007).
 *   4. No test file under tests/e2e/** still references a removed
 *      per-adapter route literal (REQ-ICO-007) — a static check over the
 *      test tree itself, not a page assertion.
 *   5. No shillinq-origin console error / 5xx fires while loading the
 *      roster.
 *
 * @spec openspec/changes/integration-config-to-openconnector/specs/integration-config-to-openconnector/spec.md
 */
import { expect, test } from '@playwright/test'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'

const APP = '/apps/shillinq'

/** All 15 adapter-family slugs ExternalAdaptersAdminController::ADAPTERS declares. */
const ALL_FAMILY_SLUGS = [
	'digipoort-sbr',
	'salarisbureau',
	'rvo',
	'ib47',
	'cbs-bestanden',
	'cbs-iv3',
	'bzk-sisa',
	'mollie',
	'bunq',
	'kvk',
	'uwv',
	'treasury-rates',
	'ccm-rule-engine',
	'csrd-esrs-xbrl',
	'deposit-payment',
]

/** Dismiss the first-run wizard / support dialog if it intercepts the route. */
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
 * Reach the roster. Shillinq's manifest SPA uses HISTORY-mode routing, so
 * the page route is directly addressable at /apps/shillinq/external-adapters
 * (verified live) — no hash, no SPA reset.
 */
async function openRoster(page: Page): Promise<void> {
	await page.goto(`${APP}/external-adapters`)
	await page.waitForLoadState('domcontentloaded')
	await dismissOverlays(page)
	await expect(page).toHaveURL(/external-adapters/, { timeout: 10_000 })
	await expect(page.locator('.external-adapters__list').first()).toBeVisible({
		timeout: 15_000,
	})
}

/** Collect shillinq-origin console errors + 5xx, filtering NC-core / env noise. */
function trackShillinqErrors(page: Page): () => string[] {
	const errors: string[] = []
	const noise =
		/Failed to load resource|favicon|net::ERR|\b404\b|user status|status\.php|Download the (React|Vue) DevTools/i
	page.on('console', (m: ConsoleMessage) => {
		if (m.type() !== 'error') return
		const t = m.text()
		if (noise.test(t)) return
		errors.push(t)
	})
	page.on('response', (r) => {
		if (r.status() >= 500 && r.url().includes('/apps/shillinq/')) {
			errors.push(`HTTP ${r.status()} ${r.url()}`)
		}
	})
	return () => [...new Set(errors)]
}

test.describe('Shillinq — External Connections roster', () => {
	/**
	 * @e2e integration-config-to-openconnector::the-roster-page-lists-all-15-declared-families
	 */
	test('the roster page lists all 15 declared families', async ({ page }) => {
		const errors = trackShillinqErrors(page)

		await openRoster(page)

		await expect(page.locator('.external-adapters__title')).toContainText(
			/External Connections/i,
			{ timeout: 15_000 },
		)
		await expect(page.locator('.external-adapters__summary')).toBeVisible({
			timeout: 10_000,
		})
		await expect(page.locator('.external-adapters__pill--total')).toContainText(
			/15\s+families/i,
		)

		// Every family row is keyed by its data-adapter-id, so the count is the
		// real backend count (ExternalAdaptersAdminController::ADAPTERS), not a
		// fixture.
		const items = page.locator('.external-adapters__item[data-adapter-id]')
		await expect(items).toHaveCount(15)
		for (const slug of ALL_FAMILY_SLUGS) {
			await expect(
				page.locator(`.external-adapters__item[data-adapter-id="${slug}"]`),
			).toBeVisible()
		}

		expect(errors(), `shillinq-origin errors:\n${errors().join('\n')}`).toEqual(
			[],
		)
	})

	/**
	 * @e2e integration-config-to-openconnector::no-per-adapter-detail-route-exists-any-more
	 *
	 * The 15 `ExternalAdapterDetail` pages + their manifest routes were
	 * removed in the same edit as their menu leaves (REQ-ICO-004); the
	 * former `.adapter-detail` panel component (src/views/external-adapters/
	 * ExternalAdapterDetail.vue) no longer exists in the bundle. Deep-linking
	 * the old per-family path must therefore NOT render a detail panel —
	 * the SPA's catch-all resolves it to something else (dashboard/roster),
	 * never `.adapter-detail`.
	 */
	test('no per-adapter detail route exists any more', async ({ page }) => {
		await page.goto(`${APP}/external-adapters/mollie`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissOverlays(page)
		// Give the SPA router a moment to settle on whatever it resolves to.
		await page.waitForTimeout(1_500)

		await expect(page.locator('.adapter-detail')).toHaveCount(0)
	})

	/**
	 * @e2e integration-config-to-openconnector::every-rows-deep-link-is-a-well-formed-url
	 */
	test("every row's deep link is a well-formed URL", async ({ page }) => {
		await openRoster(page)

		const links = page.locator(
			'.external-adapters__item-actions a, .external-adapters__item-actions [href]',
		)
		const count = await links.count()
		expect(count).toBeGreaterThan(0)

		for (let i = 0; i < count; i++) {
			const href = await links.nth(i).getAttribute('href')
			expect(href, `row action ${i} has a href`).toBeTruthy()
			// Well-formed: an absolute URL, or an app-relative path pointing at
			// openconnector's Source admin surface (design.md §3 — no per-slug
			// deep link exists yet, so every row's fallback link is the generic
			// Sources admin page).
			expect(
				/^https?:\/\//.test(href as string)
					|| /\/apps\/openconnector\/sources/.test(href as string),
				`href "${href}" is not a well-formed openconnector deep link`,
			).toBeTruthy()
		}
	})

	/**
	 * @e2e integration-config-to-openconnector::no-test-file-references-a-removed-per-adapter-route
	 *
	 * Static check over the test tree itself (not a browser assertion): no
	 * spec file may still assert against a `/external-adapters/<family-id>`
	 * route this change deletes.
	 */
	test('no test file references a removed per-adapter route', () => {
		const e2eRoot = join(__dirname, '..', 'e2e')
		const offenders: string[] = []
		const familyPathRe = new RegExp(
			`/external-adapters/(${ALL_FAMILY_SLUGS.join('|')})(?![a-z0-9-])`,
		)

		function walk(dir: string): void {
			for (const entry of readdirSync(dir)) {
				const full = join(dir, entry)
				const stat = statSync(full)
				if (stat.isDirectory()) {
					walk(full)
					continue
				}
				if (!/\.(spec|test)\.ts$/.test(entry)) {
					continue
				}
				// This file itself intentionally documents the OLD route shape
				// in its own "no detail route" test and in this comment block —
				// skip self-reference.
				if (full === __filename) {
					continue
				}
				const content = readFileSync(full, 'utf8')
				if (familyPathRe.test(content)) {
					offenders.push(full)
				}
			}
		}

		walk(e2eRoot)

		expect(
			offenders,
			`removed per-adapter route literal(s) still referenced:\n${offenders.join('\n')}`,
		).toEqual([])
	})
})
