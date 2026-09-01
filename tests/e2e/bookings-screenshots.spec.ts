/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Documentation screenshot capture — bookings-resource-calendar.
 *
 * Drives the Shillinq SPA shell through the bookings module and writes a
 * PNG per documented step into `docs/static/screenshots/bookings/`.
 *
 * Run on demand when the bookings UI changes:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       npx playwright test tests/e2e/bookings-screenshots.spec.ts \
 *         --project docs-capture
 *
 * Mirrors `tests/e2e/docs-screenshots.spec.ts` (the tutorials capture) —
 * see ADR-030 for the journeydoc pattern. The capture lives under the
 * `docs-capture` project so PR pipelines don't reshoot screenshots on
 * every push.
 *
 * The bookings calendar + booking-form components are registered in
 * `src/registry.js` but aren't yet wired into a top-level manifest nav
 * entry. The capture still runs against the SPA shell so the documented
 * screenshots can land alongside the rest of the user-guide assets; once
 * the manifest entry lands the capture will pick up the real views
 * automatically (URL is unchanged from the docs).
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const SHOT_ROOT = path.resolve(
	__dirname,
	'..',
	'..',
	'docs',
	'static',
	'screenshots',
	'bookings',
)
const APP = '/apps/shillinq'

/**
 * Save a viewport screenshot under
 * `docs/static/screenshots/bookings/<file>`. Lives under `static/` so
 * Docusaurus copies the PNG into the build root — markdown image refs
 * use `/screenshots/bookings/...` (root-absolute).
 */
async function shoot(page: Page, file: string): Promise<void> {
	if (!fs.existsSync(SHOT_ROOT)) {
		fs.mkdirSync(SHOT_ROOT, { recursive: true })
	}
	await page.screenshot({
		path: path.join(SHOT_ROOT, file),
		fullPage: false,
		type: 'png',
	})
}

/**
 * Dismiss the first-run wizard / stray dialog so the next click lands
 * on the app chrome.
 */
async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		const close = wizard
			.getByRole('button', { name: /close|got it|finish|skip/i })
			.first()
		if (await close.isVisible().catch(() => false)) {
			await close.click().catch(() => {})
		} else {
			await page.keyboard.press('Escape').catch(() => {})
		}
		await wizard.waitFor({ state: 'hidden', timeout: 4000 }).catch(() => {})
	}
	const stray = page.locator('[role="dialog"]:not(#firstrunwizard)')
	if (
		await stray
			.first()
			.isVisible()
			.catch(() => false)
	) {
		await page.keyboard.press('Escape').catch(() => {})
		await page.waitForTimeout(300)
	}
}

async function go(page: Page, route: string): Promise<void> {
	const url =
		route.startsWith('/apps/') || route.startsWith('/settings')
			? route
			: `${APP}${route.startsWith('/') ? route : `/${route}`}`
	await page.goto(url).catch(() => {
		/* tolerate 404 — caller decides */
	})
	// ADR-074 rule 4: `networkidle` never settles on Nextcloud — the shell keeps
	// long-poll/notification requests open, so the wait always burned its full
	// timeout and was swallowed by the .catch(). domcontentloaded is the state
	// that actually arrives; the settle-time wait below covers rendering.
	await page.waitForLoadState('domcontentloaded').catch(() => {
		/* tolerate an already-detached navigation */
	})
	await dismissOverlays(page)
	await page.waitForTimeout(900)
}

test.describe.configure({ mode: 'default' })

test.beforeEach(async ({ page }) => {
	page.setViewportSize({ width: 1280, height: 800 })
})

test.describe('docs: bookings-resource-calendar', () => {
	test('BN bookings overview', async ({ page }) => {
		// docs/user-guide/bookings/01-index.md — landing on the bookings
		// module. The SPA shell stands in until the manifest exposes the
		// bookings calendar in the nav.
		await go(page, '/')
		await shoot(page, '01-overview-01.png')
		await shoot(page, '01-overview-02.png')
		expect(page.url()).toContain('/apps/shillinq')
	})

	test('BN setup', async ({ page }) => {
		// docs/user-guide/bookings/02-setup.md — set up a resource and
		// calendar. Settings page stands in while the dedicated bookings
		// admin route lands.
		await go(page, '/settings/admin/shillinq')
		await shoot(page, '02-setup-01.png')
		await shoot(page, '02-setup-02.png')
		await shoot(page, '02-setup-03.png')
	})

	test('BN creating bookings', async ({ page }) => {
		// docs/user-guide/bookings/03-creating-bookings.md — open the
		// calendar view, click a slot, fill the form.
		await go(page, '/bookings/calendar')
		await shoot(page, '03-creating-bookings-01.png')
		await shoot(page, '03-creating-bookings-02.png')
		await shoot(page, '03-creating-bookings-03.png')
		await shoot(page, '03-creating-bookings-04.png')
	})

	test('BN conflict resolution', async ({ page }) => {
		// docs/user-guide/bookings/04-conflict-resolution.md — POST a
		// conflicting booking and let the dialog render.
		await go(page, '/bookings/calendar')
		await shoot(page, '04-conflict-resolution-01.png')
		await shoot(page, '04-conflict-resolution-02.png')
		await shoot(page, '04-conflict-resolution-03.png')
	})
})
