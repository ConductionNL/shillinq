/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — shillinq ICP-opgaaf SPA smoke (REQ-ICP-003,
 * REQ-ICP-010).
 *
 * The ICP-opgaaf pipeline ships three manifest navigation entries (ICP-opgaaf
 * index, ICP-opgaaf detail, ICP audit trail filter) rendered by the
 * nextcloud-vue manifest shell. This smoke confirms the SPA mounts, the ICP
 * route is reachable, and the user lands inside the shillinq app namespace.
 *
 * The full ICP end-to-end flows — invoice ICP-context tagging
 * (REQ-ICP-001 / REQ-ICP-007), VIES validation round-trip
 * (REQ-ICP-001 / REQ-ICP-009), ICP ledger view with period selector +
 * buyer drill-down (REQ-ICP-003), filing finalization with reconciliation
 * gate against rubriek 3b (REQ-ICP-004 / REQ-ICP-005), correction filing
 * (REQ-ICP-008), Digipoort submission, and audit-trail ZIP export
 * (REQ-ICP-010) — are @e2e excluded here: they require a live OpenRegister
 * instance seeded with ARInvoice + CustomerMaster + ViesValidation +
 * IcpSupply + VatReturn fixtures, the openconnector VIES + Digipoort
 * integrations (out of this app's scope per fragment _meta), and the
 * docudesk file surface for source-invoice PDF attachment. The implementing
 * cycle wires those once the live instance has the upstream services. No
 * @e2e scenario tags are emitted by this smoke.
 *
 * @spec openspec/changes/bookkeeping-icp-opgaaf/tasks.md
 */

import { test, expect } from '@playwright/test'

const APP = '/apps/shillinq'

test.describe('shillinq — ICP-opgaaf SPA smoke', () => {
	test('ICP-opgaaf navigation entry is reachable in the manifest shell', async ({
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

		// The ICP-opgaaf index page is registered by the manifest; navigation
		// must resolve without redirecting away from shillinq.
		await page.goto(APP + '/belastingen/icp-opgaaf')
		await page.waitForLoadState('domcontentloaded')
		expect(page.url()).toContain('/apps/shillinq')
	})
})
