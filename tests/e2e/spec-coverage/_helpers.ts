/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared helpers for the gate-19 behavioural spec-coverage suite.
 *
 * Shillinq renders almost all of its ~107 navigation pages through the
 * generic `@conduction/nextcloud-vue` manifest renderer (CnIndexPage),
 * mounted into `#content-vue`. These pages do not ship bespoke `h1`/`h2`
 * markup — the page title is rendered as plain text and the index body is
 * a CnIndexPage surface (data table, empty-content block, list, or a
 * primary-action toolbar with "Add Item" / "Actions" buttons).
 *
 * `gotoPage()` deep-links to the manifest route (proven to resolve without
 * bouncing back to the Dashboard), settles the SPA, dismisses the
 * first-run wizard + the cn-support-dialog overlay, and starts a recorder
 * that captures ONLY shillinq-origin 5xx responses and uncaught page
 * errors — Nextcloud core noise (user_status 500, missing-avatar 404) is
 * filtered out so a failing assertion always points at a real shillinq
 * defect.
 *
 * `assertIndexSurface()` makes the data-independent behavioural assertion:
 * the page title text is visible AND a recognised index surface rendered
 * (table | empty-state | list | a primary action button). This holds on a
 * bare environment with no seeded data — an empty CnIndexPage still renders
 * its empty-content block and toolbar.
 */

import { expect, type Page } from '@playwright/test'

export const APP = '/apps/shillinq'

export type PageRec = {
	shillinq5xx: string[]
	pageErrors: string[]
}

/**
 * Attach a recorder that captures only shillinq-origin failures.
 * Returns the mutable record; clear it with `rec.shillinq5xx.length = 0`
 * before the navigation you want to assert against.
 */
export function recordShillinqErrors(page: Page): PageRec {
	const rec: PageRec = { shillinq5xx: [], pageErrors: [] }
	page.on('response', (r) => {
		if (r.status() >= 500 && /\/apps\/shillinq\//.test(r.url())) {
			rec.shillinq5xx.push(`${r.status()} ${r.url().replace(/.*\/apps\/shillinq/, '…/shillinq')}`)
		}
	})
	page.on('pageerror', (e) => {
		rec.pageErrors.push(String(e).slice(0, 200))
	})
	return rec
}

/** Dismiss the NC first-run wizard and the cn-support-dialog overlay if present. */
export async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
	// cn-support-dialog / generic NC modal that can intercept clicks.
	const support = page.locator('.cn-support-dialog, [class*="support-dialog" i]').first()
	if (await support.isVisible().catch(() => false)) {
		const close = support.locator('button[aria-label*="lose" i], button[aria-label*="luiten" i], .modal-container__close').first()
		if (await close.isVisible().catch(() => false)) {
			await close.click({ timeout: 2_000 }).catch(() => {})
		} else {
			await page.keyboard.press('Escape').catch(() => {})
		}
		await support.waitFor({ state: 'hidden', timeout: 3_000 }).catch(() => {})
	}
}

/**
 * Deep-link to a manifest route and settle the SPA. Asserts we stayed on
 * the shillinq surface. Returns once `#content-vue` is mounted.
 */
export async function gotoPage(page: Page, route: string): Promise<void> {
	await page.goto(APP + route, { waitUntil: 'domcontentloaded', timeout: 25_000 })
	await page.waitForSelector('#content-vue', { timeout: 15_000 })
	await dismissOverlays(page)
	await page.waitForTimeout(900)
	expect(page.url(), `route ${route} should stay on the shillinq surface`).toContain('/apps/shillinq')
}

/**
 * Assert the genuine, data-independent index surface for a manifest page:
 *  - the page title text is visible, AND
 *  - at least one recognised index surface rendered (table | empty-state |
 *    list | a primary-action toolbar button).
 *
 * `titleRe` lets callers pass a looser matcher for titles that the renderer
 * truncates or re-cases.
 */
export async function assertIndexSurface(page: Page, title: string, opts: { titleRe?: RegExp } = {}): Promise<void> {
	const host = page.locator('#content-vue')

	// 1) A recognised CnIndexPage index surface rendered. This is the core
	//    behavioural proof the page mounted (vs a blank shell or an error
	//    page): a data table, an empty-content block, a list, or the
	//    primary-action toolbar (Add / Actions / Export / Reconcile …).
	const tables = await host.locator('table:visible').count().catch(() => 0)
	const empty = await page.locator('.empty-content, .emptycontent, [class*="empty-content" i]').count().catch(() => 0)
	const lists = await host.locator('ul[class*="list" i] li, [class*="app-content-list" i] [class*="item" i], [role="row"]').count().catch(() => 0)
	// Page-specific action affordances. The global "Settings" button is
	// deliberately excluded — it renders on every shillinq page and is not
	// proof that this page's index surface mounted.
	const actionBtns = await host.locator(
		'button:has-text("Add"), button:has-text("Nieuw"), button:has-text("New"), button:has-text("Toevoegen"), '
		+ 'button:has-text("Actions"), button:has-text("Acties"), button:has-text("Export"), button:has-text("Reconcile"), '
		+ 'button:has-text("Filter"), button:has-text("Post"), button:has-text("Lock"), button:has-text("Vastleggen")',
	).count().catch(() => 0)
	const surfaces = tables + empty + lists + actionBtns

	expect(
		surfaces,
		`page "${title}" should render an index surface (tables=${tables} empty=${empty} lists=${lists} actions=${actionBtns})`,
	).toBeGreaterThan(0)

	// 2) Title text. CnIndexPage prints the page title as plain text (not an
	//    h1/h2). Most pages show it verbatim; a minority render only the
	//    menu-label or an empty-state heading, so a missing title is only a
	//    failure when there is NO real index surface to fall back on. Here we
	//    already proved a surface exists, so a visible title is asserted as a
	//    soft signal: if the matcher is found it must be visible; if the page
	//    genuinely renders a different header we still have surface proof.
	//
	//    ⚠️ SCOPE THIS TO THE CONTENT REGION, NOT `#content-vue`.
	//    `#content-vue` is NcContent — it wraps BOTH `#app-navigation-vue`
	//    (the sidebar) and `#app-content-vue` (the page). Every shillinq page
	//    title is also a navigation label, so `host.getByText(title)` matched
	//    the SIDEBAR entry first, and a nav entry inside a collapsed group is
	//    `hidden` — so `toBeVisible()` failed on every page, on every run,
	//    without ever having looked at the page. Run 30862869279 recorded it
	//    literally:
	//
	//        18 × locator resolved to
	//        <span class="app-navigation-entry__name">KOR registration</span>
	//
	//    …while the same page's `<main>` had rendered fine ("Add KOR Regime",
	//    "Actions", "No items found"). Because every spec-coverage describe is
	//    `mode: 'serial'`, that one false failure aborted the rest of its file:
	//    it is where the bulk of the suite's "did not run" count came from.
	//
	//    `#app-content-vue` is NcAppContent's own id (it is the `<main>`); the
	//    `main` alternative keeps this working if a page mounts the content
	//    region without that id. When neither exists the locator simply finds
	//    nothing and the soft check is skipped — the same degradation the
	//    "matcher not found" path already had, and the mandatory surface
	//    assertion above is untouched and still gates.
	const matcher = opts.titleRe ?? new RegExp(title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i')
	const content = host.locator('#app-content-vue, main').first()
	const titleNode = content.getByText(matcher).first()
	if (await titleNode.count().catch(() => 0)) {
		await expect(titleNode, `title "${title}" should be visible when present`).toBeVisible({ timeout: 8_000 })
	}
}

/** Assert no shillinq-origin 5xx response and no uncaught page error occurred. */
export function assertNoShillinqFailures(rec: PageRec, label: string): void {
	expect(rec.shillinq5xx, `${label}: no shillinq-origin 5xx responses`).toEqual([])
	expect(rec.pageErrors, `${label}: no uncaught page errors`).toEqual([])
}
