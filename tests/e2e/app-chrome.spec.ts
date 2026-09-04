/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bottom-left app chrome, in a browser (ADR-114).
 *
 * gate-107 reads the manifest and can prove the entries are DECLARED. It
 * cannot prove they RENDER, and this programme has already produced three
 * defects of exactly that shape: an icon name that is not registered renders
 * NO glyph (not a fallback, not a console error), an entry whose `route` names
 * a page the app does not host renders a row that goes nowhere, and
 * `nav.includePersonalSettings: false` silently removed the entry that reaches
 * the user's notification preferences.
 *
 * Every card on this app's Reports page points at a page that ALREADY EXISTED
 * and had no menu entry at all. That is the thing worth testing here: the
 * cards are the first entry point those seven routes have ever had, so a card
 * pointing at a route that does not resolve would leave them exactly as
 * unreachable as before, while looking fixed.
 *
 * ⚠️ SCOPE EVERY SELECTOR TO `[data-testid="cn-nav"]`. An unscoped selector
 * also matches Nextcloud's own user menu, which is attached-but-hidden:
 * `waitFor({state:'attached'})` passes on it and the click never becomes
 * actionable, so the spec fails with "Target page has been closed" — a timeout
 * wearing a crash's clothes.
 *
 * ⚠️ SETTINGS ENTRIES ARE ATTACHED, NOT VISIBLE, inside a collapsed foldout.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP_BASE = '/apps/shillinq'

/**
 * Escape a literal string for use inside a RegExp.
 *
 * Replacing only `/` (as a first draft did) leaves every other metacharacter
 * — and the backslash itself — live, which CodeQL flags as
 * js/incomplete-sanitization. These paths are constants today; the point is
 * that the helper stays correct when one of them is not.
 *
 * @param literal The string to match literally.
 * @return The escaped pattern source.
 */
function escapeRegExp(literal: string): string {
	return literal.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')
}

/**
 * Dismiss the first-run setup wizard if it is open.
 *
 * ⚠️ On a FRESH instance CnSetupWizard opens over the app and its modal
 * intercepts pointer events, so every nav click resolves its locator and then
 * times out after 30s — a failure that reads like the navigation is broken.
 * Tests that navigate by URL pass, which is what makes this so easy to miss:
 * only the click-through tests fail, and only on a clean install.
 *
 * @param page The page.
 */
async function dismissSetupWizard(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"]')
	if ((await modal.count()) === 0) {
		return
	}
	await modal.first().getByRole('button', { name: 'Close' }).click()
	await expect(modal).toHaveCount(0, { timeout: 15_000 })
}

test.describe('app chrome (ADR-114)', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
		await dismissSetupWizard(page)
	})

	test('the footer reads Documentation, Store, Reports, Features & roadmap, each with a glyph', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await expect(footer).toBeAttached({ timeout: 15_000 })

		const rows = footer.locator('li')
		const texts = (await rows.allInnerTexts())
			.map((t) => t.trim())
			.filter(Boolean)

		const seen = texts.filter((t) =>
			/Documentation|Store|Reports|roadmap/i.test(t),
		)
		expect(seen.length).toBe(4)
		expect(seen[0]).toMatch(/Documentation/i)
		expect(seen[1]).toMatch(/Store/i)
		expect(seen[2]).toMatch(/Reports/i)
		expect(seen[3]).toMatch(/roadmap/i)

		for (const row of await rows.all()) {
			await expect(
				row.locator('svg, .material-design-icon').first(),
			).toBeAttached()
		}
	})

	test('Reports lists all seven returns and statements', async ({ page }) => {
		const nav = page.locator('[data-testid="cn-nav"]')
		await nav
			.locator('[data-testid="cn-nav-entry-ReportsMenu"] a')
			.first()
			.click()
		await expect(page).toHaveURL(/\/apps\/shillinq\/reports(\?|$)/, {
			timeout: 15_000,
		})

		for (const label of [
			'Iv3 returns',
			'SiSa returns',
			'EMU return',
			'Consolidated statements',
			'Budget variance',
			'Generated reports',
			'Destruction report',
		]) {
			await expect(
				page.getByText(label, { exact: false }).first(),
			).toBeVisible({ timeout: 15_000 })
		}
	})

	test('every carded route resolves, because the card is its only entry point', async ({
		page,
	}) => {
		// All seven had NO menu entry — reachable only by someone who already
		// knew the URL. A card pointing at a route that does not resolve would
		// leave them as unreachable as before, while looking fixed.
		for (const path of [
			'/iv3-rapportages',
			'/sisa-rapportages',
			'/emu-rapportage',
			'/financial-statements/consolidated-report',
			'/bookkeeping/variance-report',
			'/reporting-compliance/generated',
			'/bookkeeping/destruction-report',
		]) {
			await page.goto(`${APP_BASE}${path}`)
			await expect(page).toHaveURL(
				new RegExp(`${escapeRegExp(path)}(\\?|$)`),
				{ timeout: 15_000 },
			)
			await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible()
		}
	})

	test('Reporting & Compliance stays in the main navigation, because you act on it', async ({
		page,
	}) => {
		// Deliberate asymmetry with the seven cards. Reporting & Compliance is a
		// GENERATION surface — you pick an output format and press Generate —
		// and ADR-097 keeps work you DO in the main nav, the same reasoning that
		// keeps Dead letters out of integriq's Reports page. If a later sweep
		// cards it with the readings, this fails rather than passing review.
		const nav = page.locator('[data-testid="cn-nav"]')
		await expect(
			nav.locator('[data-testid="cn-nav-entry-ReportingCompliance"]'),
		).toBeAttached({ timeout: 15_000 })
	})

	test('Store opens the hosted store surface, which this app writes no backend for', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await footer
			.getByRole('link', { name: /^Store$/ })
			.first()
			.click()

		await expect(page).toHaveURL(/\/apps\/shillinq\/store(\?|$)/, {
			timeout: 15_000,
		})

		// The page is declarative: openregister hosts the store plane, so this
		// app ships NO store controller (ADR-080, ADR-114 Decision 4). With no
		// registry configured it renders the app's own items and makes NO
		// network call, so this must pass on a plain instance.
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible()
	})

	test('the settings foldout carries Personal settings, Admin settings and Flows', async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		await expect(nav.locator('[data-testid="cn-nav-settings"]')).toBeAttached({
			timeout: 15_000,
		})
		await expect(
			nav.locator('[data-testid="cn-nav-personal-settings"]'),
		).toBeAttached()
		await expect(
			nav.locator('[data-testid="cn-nav-entry-FlowsMenu"]'),
		).toBeAttached()

		const admin = nav.locator('[data-testid="cn-nav-admin-settings"]')
		await expect(admin).toBeAttached()
		await expect(admin.locator('a').first()).toHaveAttribute(
			'href',
			/\/settings\/admin\/shillinq$/,
		)
	})
})
