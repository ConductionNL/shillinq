/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Visual-regression baselines for Shillinq's key surfaces (GAP-5).
 *
 * Run:    npx playwright test --project visual
 * Update: npx playwright test --project visual --update-snapshots
 *
 * Baselines live in tests/e2e/visual/<spec>-snapshots/ and ARE committed.
 * See _visual-helpers.ts for the platform-rendering caveat.
 */
import { test } from '@playwright/test'
import { shootSurface } from './_visual-helpers'

const APP = '/index.php/apps/shillinq'

test.describe('Shillinq — visual baselines', () => {
	test('dashboard', async ({ page }) => {
		await shootSurface(page, `${APP}/#/`, 'dashboard.png')
	})

	// New W8 external-adapter admin surfaces. The status index + a detail
	// activation panel; baseline their chrome. Adapter status is dormant-by-
	// default + static, so the shots are deterministic.
	test('external adapters status', async ({ page }) => {
		await shootSurface(
			page,
			`${APP}/external-adapters`,
			'external-adapters-status.png',
		)
	})

	// NO per-adapter detail shot.
	//
	// `integration-config-to-openconnector` deleted the
	// `/external-adapters/<family-id>` routes; none is declared in
	// src/manifest*.json any more, so this shot pointed at a URL that now
	// falls through vue-router's catch-all to the Dashboard — it would have
	// silently re-baselined the wrong page.
	//
	// `external-adapters.spec.ts`'s "no test file references a removed
	// per-adapter route" guard walks the whole e2e tree for exactly this
	// literal and was failing on this file.
})
