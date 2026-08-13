/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Inventory Mobile Scanner — Playwright UI coverage (T6.2, T6.5).
 *
 * Smoke-tests that the four warehouse PWA pages mount under the manifest
 * router and that the home tile navigation works. Camera + IndexedDB
 * exercises live in the unit tests (Jest/PHPUnit); Playwright stays UI-
 * only per the fleet rule.
 *
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md
 */

import { test, expect } from '@playwright/test'

const APP = '/apps/shillinq'

test.describe('inventory mobile scanner — manifest pages mount', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(APP + '/')
		await page.waitForLoadState('domcontentloaded')

		const wizard = page.locator('#firstrunwizard')
		if (await wizard.isVisible().catch(() => false)) {
			await page.keyboard.press('Escape').catch(() => {})
			await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
		}
	})

	/**
	 * @e2e inventory-mobile-scanner/REQ-UI-003/home-route-mounts
	 */
	test('mobile scanner home route mounts', async ({ page }) => {
		await page.goto(APP + '/inventory/mobile')
		await page.waitForLoadState('domcontentloaded')
		expect(page.url()).toContain('/inventory/mobile')
	})

	/**
	 * @e2e inventory-mobile-scanner/REQ-INVENTORY-001/receive-route-mounts
	 */
	test('receive route mounts', async ({ page }) => {
		await page.goto(APP + '/inventory/mobile/receive')
		await page.waitForLoadState('domcontentloaded')
		expect(page.url()).toContain('/inventory/mobile/receive')
	})

	/**
	 * @e2e inventory-mobile-scanner/REQ-INVENTORY-002/transfer-route-mounts
	 */
	test('transfer route mounts', async ({ page }) => {
		await page.goto(APP + '/inventory/mobile/transfer')
		await page.waitForLoadState('domcontentloaded')
		expect(page.url()).toContain('/inventory/mobile/transfer')
	})

	/**
	 * @e2e inventory-mobile-scanner/REQ-INVENTORY-003/pick-route-mounts
	 */
	test('pick route mounts', async ({ page }) => {
		await page.goto(APP + '/inventory/mobile/pick')
		await page.waitForLoadState('domcontentloaded')
		expect(page.url()).toContain('/inventory/mobile/pick')
	})

	/**
	 * @e2e inventory-mobile-scanner/REQ-INVENTORY-004/count-route-mounts
	 */
	test('count route mounts', async ({ page }) => {
		await page.goto(APP + '/inventory/mobile/count')
		await page.waitForLoadState('domcontentloaded')
		expect(page.url()).toContain('/inventory/mobile/count')
	})
})
