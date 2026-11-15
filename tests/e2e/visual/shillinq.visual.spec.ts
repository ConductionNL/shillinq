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
import { shootSurface } from './_visual-helpers.ts'

const APP = '/index.php/apps/shillinq'

test.describe('Shillinq — visual baselines', () => {
	test('dashboard', async ({ page }) => {
		await shootSurface(page, `${APP}/#/`, 'dashboard.png')
	})

	// NO External Connections shot. Since adopt-connection-registry the page
	// lists integriq's connection rows, and the CI instance has no integriq, so
	// the only screen a baseline could capture is the missing-dependency one.
	// tests/e2e/external-connections.spec.ts asserts that screen instead.
})
