/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Documentation screenshot capture suite — shillinq.
 *
 * This spec is *not* a regression test — it drives the Shillinq UI
 * through every flow documented under `docs/tutorials/{user,admin}/*.md`
 * and writes a fresh PNG into `docs/static/screenshots/tutorials/<track>/`
 * for each step the markdown references.
 *
 * Run manually whenever the UI changes and tutorial screenshots need
 * to be refreshed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       npx playwright test --project docs-capture
 *
 * Excluded from the default `npm run test:e2e` run via the
 * `docs-capture` project flag in `playwright.config.ts` so PR
 * pipelines don't reshoot screenshots on every push.
 *
 * Authentication: `playwright.config.ts` wires `globalSetup` (a one-time
 * Nextcloud login → storage state) and `use.storageState`, so the
 * `page` fixture here arrives already signed in.
 *
 * Data dependency: Shillinq is not yet installed in the dev container at
 * the time of writing, *and* most of the user-track features described
 * in `docs/tutorials/user/` (invoicing, bills, POs, contracts, banking,
 * VAT, reporting) are not yet implemented — the live scaffold currently
 * renders only the Dashboard and Settings pages. The capture below
 * navigates per the tutorials' numbered steps as best it can; when a
 * route doesn't exist yet, the dashboard / settings page stands in.
 * Selector misses are the expected first-run failure mode (UI markup
 * drifts faster than docs); failures land per-test in `test-results/`
 * rather than killing the suite. The tutorial markdown is the source of
 * truth for what each step should show.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/).
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
	'tutorials',
)
const APP = '/apps/shillinq'

/**
 * Save a viewport screenshot under
 * `docs/static/screenshots/tutorials/<track>/<file>`.
 * Lives under `static/` so Docusaurus copies the PNG into the build
 * root — markdown image refs use `/screenshots/...` (root-absolute).
 */
async function shoot(
	page: Page,
	track: 'user' | 'admin',
	file: string,
): Promise<void> {
	const dir = path.join(SHOT_ROOT, track)
	if (!fs.existsSync(dir)) {
		fs.mkdirSync(dir, { recursive: true })
	}
	await page.screenshot({
		path: path.join(dir, file),
		fullPage: false,
		type: 'png',
	})
}

/**
 * Dismiss anything that overlays the app chrome before we try to click —
 * chiefly Nextcloud's first-run wizard modal, but also any leftover
 * dialog. Best-effort: silently no-op when nothing's there.
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

/**
 * Navigate to an app route (relative paths join /apps/shillinq) or to
 * an absolute Nextcloud route (paths starting with `/apps/` or
 * `/settings` are passed through). Settles network + dismisses overlays.
 *
 * Shillinq routes off the manifest with an SPA catch-all in
 * appinfo/routes.php; once the feature pages land they will be reached
 * via /apps/shillinq/<route>.
 */
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

// ---------------------------------------------------------------------------
// USER TRACK — see docs/tutorials/user/
// ---------------------------------------------------------------------------

test.describe('docs: user track', () => {
	test('UN first-launch', async ({ page }) => {
		// docs/tutorials/user/01-first-launch.md
		await go(page, '/')
		await shoot(page, 'user', '01-first-launch-01.png')
		await shoot(page, 'user', '01-first-launch-02.png')
		await shoot(page, 'user', '01-first-launch-03.png')
		await go(page, '/settings')
		await shoot(page, 'user', '01-first-launch-04.png')
		expect(page.url()).toContain('/apps/shillinq')
	})

	test('UN send-invoice', async ({ page }) => {
		// docs/tutorials/user/02-send-invoice.md — invoicing surface not
		// yet implemented; dashboard stands in for each step.
		await go(page, '/invoices')
		await shoot(page, 'user', '02-send-invoice-01.png')
		await shoot(page, 'user', '02-send-invoice-02.png')
		await shoot(page, 'user', '02-send-invoice-03.png')
		await shoot(page, 'user', '02-send-invoice-04.png')
		await shoot(page, 'user', '02-send-invoice-05.png')
	})

	test('UN record-bill', async ({ page }) => {
		// docs/tutorials/user/03-record-bill.md
		await go(page, '/bills')
		await shoot(page, 'user', '03-record-bill-01.png')
		await shoot(page, 'user', '03-record-bill-02.png')
		await shoot(page, 'user', '03-record-bill-03.png')
		await shoot(page, 'user', '03-record-bill-04.png')
		await shoot(page, 'user', '03-record-bill-05.png')
	})

	test('UN create-purchase-order', async ({ page }) => {
		// docs/tutorials/user/04-create-purchase-order.md
		await go(page, '/purchase-orders')
		await shoot(page, 'user', '04-create-purchase-order-01.png')
		await shoot(page, 'user', '04-create-purchase-order-02.png')
		await shoot(page, 'user', '04-create-purchase-order-03.png')
		await shoot(page, 'user', '04-create-purchase-order-04.png')
		await shoot(page, 'user', '04-create-purchase-order-05.png')
	})

	test('UN bank-reconciliation', async ({ page }) => {
		// docs/tutorials/user/05-bank-reconciliation.md
		await go(page, '/reconciliation')
		await shoot(page, 'user', '05-bank-reconciliation-01.png')
		await shoot(page, 'user', '05-bank-reconciliation-02.png')
		await shoot(page, 'user', '05-bank-reconciliation-03.png')
		await shoot(page, 'user', '05-bank-reconciliation-04.png')
		await shoot(page, 'user', '05-bank-reconciliation-05.png')
	})

	test('UN manage-contract', async ({ page }) => {
		// docs/tutorials/user/06-manage-contract.md
		await go(page, '/contracts')
		await shoot(page, 'user', '06-manage-contract-01.png')
		await shoot(page, 'user', '06-manage-contract-02.png')
		await shoot(page, 'user', '06-manage-contract-03.png')
		await shoot(page, 'user', '06-manage-contract-04.png')
		await shoot(page, 'user', '06-manage-contract-05.png')
	})

	test('UN vat-return', async ({ page }) => {
		// docs/tutorials/user/07-vat-return.md
		await go(page, '/vat-returns')
		await shoot(page, 'user', '07-vat-return-01.png')
		await shoot(page, 'user', '07-vat-return-02.png')
		await shoot(page, 'user', '07-vat-return-03.png')
		await shoot(page, 'user', '07-vat-return-04.png')
		await shoot(page, 'user', '07-vat-return-05.png')
	})

	test('UN financial-statements', async ({ page }) => {
		// docs/tutorials/user/08-financial-statements.md
		await go(page, '/reports')
		await shoot(page, 'user', '08-financial-statements-01.png')
		await shoot(page, 'user', '08-financial-statements-02.png')
		await shoot(page, 'user', '08-financial-statements-03.png')
		await shoot(page, 'user', '08-financial-statements-04.png')
		await shoot(page, 'user', '08-financial-statements-05.png')
	})

	test('UN post-journal-entry', async ({ page }) => {
		// docs/user-guide/user/11-post-journal-entry.md — bookkeeping
		// foundation T1 journal entries (manifest-rendered CnIndexPage /
		// CnDetailPage on the JournalEntry register, per REQ-JE-009).
		await go(page, '/journals')
		await shoot(page, 'user', '11-post-journal-entry-01.png')
		await shoot(page, 'user', '11-post-journal-entry-02.png')
		await shoot(page, 'user', '11-post-journal-entry-03.png')
		await shoot(page, 'user', '11-post-journal-entry-04.png')
		await shoot(page, 'user', '11-post-journal-entry-05.png')
	})
})

// ---------------------------------------------------------------------------
// ADMIN TRACK — see docs/tutorials/admin/
// ---------------------------------------------------------------------------

test.describe('docs: admin track', () => {
	test('AN chart-of-accounts', async ({ page }) => {
		// docs/tutorials/admin/01-chart-of-accounts.md — chart-of-accounts
		// section under the Shillinq admin settings page.
		await go(page, '/settings/admin/shillinq')
		await shoot(page, 'admin', '01-chart-of-accounts-01.png')
		await shoot(page, 'admin', '01-chart-of-accounts-02.png')
		await shoot(page, 'admin', '01-chart-of-accounts-03.png')
		await shoot(page, 'admin', '01-chart-of-accounts-04.png')
		await shoot(page, 'admin', '01-chart-of-accounts-05.png')
	})

	test('AN approval-chains', async ({ page }) => {
		// docs/tutorials/admin/02-approval-chains.md
		await go(page, '/settings/admin/shillinq')
		await shoot(page, 'admin', '02-approval-chains-01.png')
		await shoot(page, 'admin', '02-approval-chains-02.png')
		await shoot(page, 'admin', '02-approval-chains-03.png')
		await shoot(page, 'admin', '02-approval-chains-04.png')
		await shoot(page, 'admin', '02-approval-chains-05.png')
	})

	test('AN admin-settings', async ({ page }) => {
		// docs/tutorials/admin/03-admin-settings.md — Shillinq's admin
		// settings page in the Nextcloud administration panel.
		await go(page, '/settings/admin/shillinq')
		await shoot(page, 'admin', '03-admin-settings-01.png')
		await page.evaluate(() => window.scrollTo(0, 0))
		await page.waitForTimeout(300)
		await shoot(page, 'admin', '03-admin-settings-02.png')
		await shoot(page, 'admin', '03-admin-settings-03.png')
		await shoot(page, 'admin', '03-admin-settings-04.png')
		await go(page, '/')
		await shoot(page, 'admin', '03-admin-settings-05.png')
	})
})
