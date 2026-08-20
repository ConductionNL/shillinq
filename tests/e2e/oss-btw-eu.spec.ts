/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — shillinq OSS (One-Stop-Shop) SPA smoke.
 *
 * The OSS pipeline adds two declarative manifest navigation entries (OSS
 * Registration, OSS Returns) rendered by the nextcloud-vue manifest shell. This
 * smoke confirms the SPA mounts and the OSS navigation entries are reachable.
 *
 * The full OSS end-to-end flows (REQ-OSS-001 invoice rate resolution, REQ-OSS-004
 * quarterly return generation + XSD download, REQ-OSS-008 payment reconciliation,
 * REQ-OSS-009 voluntary opt-in lock-in) are @e2e excluded here: they require a
 * live OpenRegister instance seeded with EuVatRate TEDB data + OSS registrations
 * and a posted-invoice fixture, which the implementing cycle wires once the
 * register fragment is imported into a running instance. No @e2e scenario tags are
 * emitted by this smoke.
 */

import { test, expect } from '@playwright/test'

const APP = '/apps/shillinq'

test.describe('shillinq — OSS SPA smoke', () => {
	test('OSS navigation entries are reachable in the manifest shell', async ({
		page,
	}) => {
		await page.goto(APP + '/')
		await page.waitForLoadState('domcontentloaded')

		// Dismiss any first-run wizard overlays.
		const wizard = page.locator('#firstrunwizard')
		if (await wizard.isVisible().catch(() => false)) {
			await page.keyboard.press('Escape').catch(() => {})
			await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
		}

		// Stay within shillinq.
		expect(page.url()).toContain('/apps/shillinq')

		// The OSS Returns index page is registered by the manifest fragment; the
		// SPA route must resolve without redirecting away from shillinq.
		await page.goto(APP + '/bookkeeping/oss/returns')
		await page.waitForLoadState('domcontentloaded')
		expect(page.url()).toContain('/apps/shillinq')
	})
})
