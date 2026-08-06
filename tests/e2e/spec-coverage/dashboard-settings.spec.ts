/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural UI coverage — Shillinq top-level pages: Dashboard,
 * Settings, Features & roadmap. Drives each through the real UI and asserts
 * its surface mounted with no shillinq-origin 5xx / page error.
 * Data-independent.
 */

import { test, expect } from '@playwright/test'
import { APP, gotoPage, dismissOverlays, assertNoShillinqFailures, recordShillinqErrors } from './_helpers'

test.describe('shillinq spec-coverage — Dashboard & Settings', () => {
	// No `mode: 'serial'` — see the header of ./_helpers.ts. The Dashboard
	// test's uncaught "Element not found" page error was silently costing
	// the Settings tests below it their verdict.


	test('Dashboard — root SPA mounts with the Dashboard surface', async ({ page }) => {
		const rec = recordShillinqErrors(page)
		await page.goto(APP + '/', { waitUntil: 'domcontentloaded', timeout: 25_000 })
		await page.waitForSelector('#content-vue', { timeout: 15_000 })
		await dismissOverlays(page)
		await page.waitForTimeout(1_000)
		expect(page.url()).toContain('/apps/shillinq')

		// Dashboard title text is rendered by the SPA.
		await expect(page.locator('#content-vue').getByText(/Dashboard/i).first()).toBeVisible({ timeout: 12_000 })
		// Dashboard renders either widget cards/empty-content blocks or a
		// toolbar — at least one real surface must be present.
		const surfaces = await page.locator(
			'#content-vue .empty-content, #content-vue [class*="widget" i], #content-vue [class*="card" i], #content-vue button:visible',
		).count().catch(() => 0)
		expect(surfaces, 'dashboard should render widgets / cards / toolbar').toBeGreaterThan(0)
		assertNoShillinqFailures(rec, '/')
	})

	test('Settings — settings page mounts', async ({ page }) => {
		const rec = recordShillinqErrors(page)
		await gotoPage(page, '/settings')
		await expect(page.locator('#content-vue').getByText(/Settings|Instelling/i).first())
			.toBeVisible({ timeout: 12_000 })
		// A settings surface: form fields, toggles, sections, or save buttons.
		const surfaces = await page.locator(
			'#content-vue input, #content-vue [class*="settings" i], #content-vue [role="switch"], #content-vue button:visible, #content-vue fieldset, #content-vue section',
		).count().catch(() => 0)
		expect(surfaces, 'settings should render a settings surface').toBeGreaterThan(0)
		assertNoShillinqFailures(rec, '/settings')
	})

	test('Features & roadmap — page mounts', async ({ page }) => {
		const rec = recordShillinqErrors(page)
		// `/features-roadmap` is the ROUTE; `FeaturesRoadmap` is the page ID.
		// The id was used as a path here, so the deep link fell through
		// main.js's '/:pathMatch(.*)*' catch-all onto the Dashboard.
		await gotoPage(page, '/features-roadmap')
		// Renders a roadmap surface (content text / cards / list).
		const surfaces = await page.locator(
			'#content-vue .empty-content, #content-vue [class*="card" i], #content-vue [class*="feature" i], #content-vue li, #content-vue table',
		).count().catch(() => 0)
		const hasText = (await page.locator('#content-vue').innerText().catch(() => '')).trim().length > 20
		expect(surfaces > 0 || hasText, 'features & roadmap should render content').toBeTruthy()
		assertNoShillinqFailures(rec, '/features-roadmap')
	})
})
