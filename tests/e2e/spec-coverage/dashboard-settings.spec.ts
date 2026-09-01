/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural UI coverage — Shillinq top-level pages: Dashboard,
 * Settings, Features & roadmap. Drives each through the real UI and asserts
 * its surface mounted with no shillinq-origin 5xx / page error.
 * Data-independent.
 */

import { expect, test } from '@playwright/test'
import {
	APP,
	assertNoShillinqFailures,
	dismissOverlays,
	gotoPage,
	recordShillinqErrors,
} from './_helpers.ts'

test.describe('shillinq spec-coverage — Dashboard & Settings', () => {
	// No `mode: 'serial'` — see the header of ./_helpers.ts. The Dashboard
	// test's uncaught "Element not found" page error was silently costing
	// the Settings tests below it their verdict.

	// object-store-retirement (REQ-OSR-003): this test's mount path runs
	// App.vue's `created()` → `initializeStores()` — the exact function edited
	// to drop the dead `object.js` store. A broken bootstrap after that
	// removal would surface here as a shillinq-origin console/page error.
	/**
	 * @e2e object-store-retirement::dashboard-boots-without-object-store
	 */
	test('Dashboard — root SPA mounts with the Dashboard surface', async ({
		page,
	}) => {
		const rec = recordShillinqErrors(page)
		await page.goto(APP + '/', {
			waitUntil: 'domcontentloaded',
			timeout: 25_000,
		})
		await page.waitForSelector('#content-vue', { timeout: 15_000 })
		await dismissOverlays(page)
		await page.waitForTimeout(1_000)
		expect(page.url()).toContain('/apps/shillinq')

		// Dashboard title text is rendered by the SPA.
		await expect(
			page
				.locator('#content-vue')
				.getByText(/Dashboard/i)
				.first(),
		).toBeVisible({ timeout: 12_000 })
		// Dashboard renders either widget cards/empty-content blocks or a
		// toolbar — at least one real surface must be present.
		const surfaces = await page
			.locator(
				'#content-vue .empty-content, #content-vue [class*="widget" i], #content-vue [class*="card" i], #content-vue button:visible',
			)
			.count()
			.catch(() => 0)
		expect(
			surfaces,
			'dashboard should render widgets / cards / toolbar',
		).toBeGreaterThan(0)
		assertNoShillinqFailures(rec, '/')
	})

	// ADR-079 D1: app configuration lives in the Nextcloud settings framework
	// at /settings/admin/shillinq (AdminSettings.php → AdminRoot.vue), NOT on
	// an in-app `type: settings` page. This test used to drive the in-app
	// /settings route, which duplicated the same register form and shipped
	// three link sections whose `links[]` key CnSettingsPage does not read, so
	// they rendered as empty cards. That page has been deleted; the surface
	// this asserts is the platform one — hence no `gotoPage`, which pins the
	// URL to /apps/shillinq.
	// object-store-retirement (REQ-OSR-003): this test's mount path runs
	// AdminRoot.vue's `created()` → `initializeStores()` — the same edited
	// function as the Dashboard test above, exercised via the platform admin
	// settings surface instead of the in-app SPA.
	/**
	 * @e2e object-store-retirement::admin-settings-boots-without-object-store
	 */
	test('Settings — the platform admin settings section mounts', async ({
		page,
	}) => {
		const rec = recordShillinqErrors(page)
		await page.goto('/index.php/settings/admin/shillinq', {
			waitUntil: 'domcontentloaded',
			timeout: 25_000,
		})
		await page.waitForSelector('#shillinq-settings', { timeout: 15_000 })
		await dismissOverlays(page)
		await page.waitForTimeout(1_000)
		await expect(
			page
				.locator('#shillinq-settings')
				.getByText(/Configuration|Configuratie/i)
				.first(),
		).toBeVisible({ timeout: 12_000 })
		// A settings surface: form fields, toggles, sections, or save buttons.
		const surfaces = await page
			.locator(
				'#shillinq-settings input, #shillinq-settings [class*="settings" i], #shillinq-settings [role="switch"], #shillinq-settings button:visible, #shillinq-settings fieldset, #shillinq-settings section',
			)
			.count()
			.catch(() => 0)
		expect(
			surfaces,
			'admin settings should render a settings surface',
		).toBeGreaterThan(0)
		assertNoShillinqFailures(rec, '/settings/admin/shillinq')
	})

	test('Features & roadmap — page mounts', async ({ page }) => {
		const rec = recordShillinqErrors(page)
		// `/features-roadmap` is the ROUTE; `FeaturesRoadmap` is the page ID.
		// The id was used as a path here, so the deep link fell through
		// main.js's '/:pathMatch(.*)*' catch-all onto the Dashboard.
		await gotoPage(page, '/features-roadmap')
		// Renders a roadmap surface (content text / cards / list).
		const surfaces = await page
			.locator(
				'#content-vue .empty-content, #content-vue [class*="card" i], #content-vue [class*="feature" i], #content-vue li, #content-vue table',
			)
			.count()
			.catch(() => 0)
		const hasText =
			(
				await page
					.locator('#content-vue')
					.innerText()
					.catch(() => '')
			).trim().length > 20
		expect(
			surfaces > 0 || hasText,
			'features & roadmap should render content',
		).toBeTruthy()
		assertNoShillinqFailures(rec, '/features-roadmap')
	})
})
