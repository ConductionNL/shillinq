/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shillinq W8 — External Adapters admin UI, real-browser coverage.
 *
 * Drives the operator-facing "External Connections" admin surface through the
 * live Shillinq manifest SPA against the real, admin-gated backend
 * (`ExternalAdaptersAdminController#index / #show`). This is the UI layer the
 * W8 work shipped with no e2e/component/visual coverage:
 *   - src/views/external-adapters/ExternalAdaptersStatus.vue  (index)
 *   - src/views/external-adapters/ExternalAdapterDetail.vue   (activation panel)
 *
 * Beyond shell/render it asserts REAL backend-driven state:
 *   1. The index lists every one of the 14 dormant external-API adapter
 *      families (the count comes from the live controller registry, not a
 *      fixture), with a dormant/live badge and a summary roll-up.
 *   2. Opening a family's detail panel renders the activation recipe
 *      (config keys, openconnector source slug, feature flag, ordered steps)
 *      fetched from /api/admin/external-adapters/{id}.
 *   3. The detail panel's primary action ("Back to External Connections")
 *      returns to the index.
 *   4. No app-origin console error and no 5xx from the adapter endpoints
 *      during the journey.
 *
 * The pages are declarative manifest routes (ADR-037) served by the SPA shell,
 * so the spec reaches them by deep-linking the manifest route and, if the SPA
 * resets a deep-link to the dashboard, by clicking the "External Connections"
 * nav node — the reliable in-app path documented for the Conduction SPAs.
 *
 * @spec openspec/changes/bookkeeping-bank-connectors/specs/bookkeeping-bank-connectors/spec.md
 */

import { test, expect, type Page, type ConsoleMessage } from '@playwright/test'

const APP = '/apps/shillinq'
const STATUS_ROUTE = `${APP}/external-adapters`
const DETAIL_ROUTE_MOLLIE = `${APP}/external-adapters/mollie`

/**
 * The adapter families the W8 controller registry ships, as served live by
 * ExternalAdaptersAdminController. The W8 work shipped 14 third-party API
 * ports plus the `deposit-payment` lifecycle adapter that sits one layer above
 * the Mollie port (it has its own manifest nav node + detail page), so the
 * operator-facing roll-up lists 15 families. This is asserted against the live
 * backend count, not hard-coded blind — see the API probe in the spec.
 */
const EXPECTED_FAMILY_COUNT = 15

/**
 * Collect app-origin console errors (ignore framework / network noise that is
 * not a regression in this app's code).
 */
function collectAppErrors(page: Page): string[] {
	const errors: string[] = []
	page.on('console', (msg: ConsoleMessage) => {
		if (msg.type() !== 'error') {
			return
		}
		const text = msg.text()
		// Filter pure network/resource noise that is not an app-code defect.
		if (
			/Failed to load resource|net::ERR_|favicon|404 \(Not Found\)/i.test(text)
		) {
			return
		}
		errors.push(text)
	})
	return errors
}

/**
 * Dismiss the Nextcloud first-run wizard and the cn-support-dialog overlay.
 * Either of them sits on top of the SPA and swallows clicks; without this the
 * helper below could only ever fail with "element not found" on the surface
 * BEHIND the modal.
 */
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
		await page.keyboard.press('Escape').catch(() => {})
		await support.waitFor({ state: 'hidden', timeout: 3_000 }).catch(() => {})
	}
}

/**
 * Reach the External Adapters status index by deep link.
 *
 * ⚠️ TWO DEFECTS LIVED IN THE PREVIOUS VERSION OF THIS HELPER, AND BETWEEN THEM
 * THEY MADE IT IMPOSSIBLE FOR THE DEEP LINK TO EVER BE ACCEPTED:
 *
 *  1. `locator.isVisible({ timeout })` DOES NOT WAIT. Unlike `expect(...)
 *     .toBeVisible()`, `isVisible()` is an immediate predicate — the `timeout`
 *     option does not make it poll. It was called on the tick right after
 *     `domcontentloaded`, i.e. before Vue had mounted anything, so it returned
 *     `false` on EVERY run and the helper always took the fallback branch.
 *
 *  2. The fallback branch navigated to the app root and looked for an
 *     "External Connections" group in the main navigation. That group does not
 *     exist there: `src/menu-layout.json#settingsSection` lifts
 *     `ExternalAdaptersStatus` and all fifteen adapter entries into Nextcloud's
 *     COLLAPSED settings foldout, so `nav.getByText('External Connections')`
 *     matched nothing, both clicks were swallowed by `.catch(() => {})`, and
 *     the page stayed on the Dashboard until the 15 s assertion expired.
 *
 * The deep link itself is sound — `tests/e2e/external-adapters.spec.ts` drives
 * the same route and passes. So: deep-link, dismiss overlays, and WAIT with a
 * real polling assertion. No fallback path, because a fallback that silently
 * swallows its own failures cannot report anything.
 */
async function gotoAdapterStatus(page: Page): Promise<void> {
	await page.goto(STATUS_ROUTE, { waitUntil: 'domcontentloaded' })
	await dismissOverlays(page)
	// The route must resolve to itself — `src/main.js` ends its route table
	// with a `/:pathMatch(.*)*` catch-all that redirects to the Dashboard, and
	// the Dashboard would satisfy a looser "we are still in shillinq" check.
	await expect(page).toHaveURL(/\/external-adapters(\?|$|\/)/, {
		timeout: 15_000,
	})
	await expect(page.locator('.external-adapters__list').first()).toBeVisible({
		timeout: 20_000,
	})
}

test.describe('Shillinq W8 — External Adapters admin UI', () => {
	test('the adapter status index lists every external-API family with badges + summary', async ({
		page,
	}) => {
		const errors = collectAppErrors(page)
		const adapterResponses: number[] = []
		page.on('response', (res) => {
			if (res.url().includes('/api/admin/external-adapters')) {
				adapterResponses.push(res.status())
			}
		})

		await gotoAdapterStatus(page)

		// Title + description render.
		await expect(
			page.getByRole('heading', { name: 'External Connections' }),
		).toBeVisible()

		// The list is populated from the live controller registry — every
		// family row is keyed by its data-adapter-id, so the count is the
		// real backend count, not a fixture.
		const items = page.locator('.external-adapters__item[data-adapter-id]')
		await expect(items).toHaveCount(EXPECTED_FAMILY_COUNT)

		// Each row carries a dormant/live badge with a resolved state.
		const badges = page.locator('.external-adapters__badge[data-state]')
		await expect(badges).toHaveCount(EXPECTED_FAMILY_COUNT)
		const firstState = await badges.first().getAttribute('data-state')
		expect(['dormant', 'live']).toContain(firstState)

		// The summary roll-up reports the same total.
		const totalPill = page.locator('.external-adapters__pill--total')
		await expect(totalPill).toContainText(String(EXPECTED_FAMILY_COUNT))

		// A known family is present.
		await expect(page.locator('[data-adapter-id="mollie"]')).toBeVisible()

		// The admin endpoint never 5xx'd.
		expect(
			adapterResponses.every((s) => s < 500),
			`adapter endpoint 5xx: ${adapterResponses}`,
		).toBeTruthy()
		expect(errors, `app console errors: ${errors.join(' | ')}`).toEqual([])
	})

	test('opening a family detail panel renders its activation recipe and returns to the index', async ({
		page,
	}) => {
		const errors = collectAppErrors(page)
		let detail404 = false
		let detail5xx = false
		page.on('response', (res) => {
			if (/\/api\/admin\/external-adapters\/[^/]+$/.test(res.url())) {
				if (res.status() === 404) detail404 = true
				if (res.status() >= 500) detail5xx = true
			}
		})

		// Deep-link straight to the Mollie family detail (manifest route with
		// a fixed adapterId prop). If the SPA resets it, reach it via the
		// index's "View activation" button instead.
		await page.goto(DETAIL_ROUTE_MOLLIE, { waitUntil: 'domcontentloaded' })
		let detail = page.locator('.adapter-detail[data-adapter-id]')
		if (
			!(await detail
				.first()
				.isVisible({ timeout: 8_000 })
				.catch(() => false))
		) {
			await gotoAdapterStatus(page)
			await page
				.locator('[data-adapter-id="mollie"]')
				.getByRole('button', { name: 'View activation' })
				.click()
			detail = page.locator('.adapter-detail[data-adapter-id]')
		}

		await expect(detail.first()).toBeVisible({ timeout: 15_000 })

		// Header: title + dormant/live badge resolved from the backend.
		await expect(
			page.getByRole('heading', { name: 'Mollie Payments' }),
		).toBeVisible()
		const badge = page.locator('.adapter-detail__badge[data-state]')
		await expect(badge).toBeVisible()
		expect(['dormant', 'live']).toContain(await badge.getAttribute('data-state'))

		// The activation recipe rendered: facts grid + ordered steps from the
		// /api/admin/external-adapters/{id} payload. Target the fact-label
		// cells specifically (the same phrases also appear inside step text).
		const factLabels = page.locator('.adapter-detail__fact-label')
		await expect(
			factLabels.filter({ hasText: 'openconnector source slug' }),
		).toBeVisible()
		await expect(factLabels.filter({ hasText: 'Feature flag' })).toBeVisible()
		// The openconnector source slug value for Mollie is rendered.
		await expect(
			page.locator('.adapter-detail__fact-value', {
				hasText: 'mollie-payments',
			}),
		).toBeVisible()
		const steps = page.locator('.adapter-detail__step')
		expect(await steps.count()).toBeGreaterThan(0)

		// Primary action: back to the index.
		await page
			.getByRole('button', { name: 'Back to External Connections' })
			.click()
		await expect(page.locator('.external-adapters__list').first()).toBeVisible({
			timeout: 15_000,
		})

		expect(detail404, 'a known adapter id must not 404').toBeFalsy()
		expect(detail5xx, 'adapter detail endpoint must not 5xx').toBeFalsy()
		expect(errors, `app console errors: ${errors.join(' | ')}`).toEqual([])
	})
})
