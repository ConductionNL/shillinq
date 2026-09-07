/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * integration-config-to-openconnector — External Connections roster,
 * provisioning-state workflow coverage (REQ-ICO-003).
 *
 * `ExternalAdaptersAdminController::resolveProvisioning()` resolves each
 * family's declared openconnector source slug against OpenRegister's
 * generic object API (register: openconnector, schema: source) and returns
 * one of three states: `provisioned`, `declared-not-provisioned`, or
 * `unknown` (fail-soft, on any lookup error). Live-verified against the dev
 * instance while implementing this change: NONE of the 15 declared source
 * slugs are provisioned there today (0/262 seeded openconnector sources
 * matched any of them — see design.md §6.1/§8), so the `provisioned` state
 * cannot be exercised against real data on this box. Per Playwright best
 * practice for a fail-soft, conditionally-rendered UI state, this spec
 * drives all three states deterministically by intercepting
 * GET /api/admin/external-adapters (page.route) rather than depending on a
 * live provisioning fixture — the frontend contract under test
 * (src/views/external-adapters/ExternalAdaptersStatus.vue's
 * provisioningBadgeClass/provisioningLabel) is exercised exactly the same
 * way whether the JSON came from the real controller or a mock.
 *
 * @spec openspec/changes/integration-config-to-openconnector/specs/integration-config-to-openconnector/spec.md
 */

import type { ConsoleMessage, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const STATUS_ROUTE = `${APP}/external-adapters`
const ADAPTERS_API = '**/apps/shillinq/api/admin/external-adapters'

/** One representative adapter entry, shaped exactly like the real controller's response. */
function makeAdapter(overrides: Record<string, unknown> = {}) {
	return {
		id: 'mollie',
		title: 'Mollie Payments',
		category: 'payment',
		specSlug: 'bookings-deposits',
		requirements: ['REQ-DP-001'],
		configKeys: ['mollie.api.key', 'mollie.webhook.secret'],
		featureFlag: 'payments-mollie',
		sourceSlug: 'mollie-payments',
		description: 'Creates deposit-payment intents (iDEAL / Bancontact / SEPA).',
		steps: ['Create a Mollie profile.', 'Set app-config keys.'],
		dormant: true,
		provisioning: {
			status: 'declared-not-provisioned',
			deepLink: '/apps/openconnector/sources',
		},
		...overrides,
	}
}

/** Mock GET /api/admin/external-adapters with a single-adapter fixture. */
async function mockAdapters(
	page: Page,
	adapter: ReturnType<typeof makeAdapter>,
): Promise<void> {
	await page.route(ADAPTERS_API, async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				adapters: [adapter],
				summary: {
					total: 1,
					dormant: adapter.dormant ? 1 : 0,
					live: adapter.dormant ? 0 : 1,
				},
			}),
		})
	})
}

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

function collectAppErrors(page: Page): string[] {
	const errors: string[] = []
	page.on('console', (msg: ConsoleMessage) => {
		if (msg.type() !== 'error') return
		const text = msg.text()
		if (
			/Failed to load resource|net::ERR_|favicon|404 \(Not Found\)/i.test(text)
		) {
			return
		}
		errors.push(text)
	})
	return errors
}

test.describe('Shillinq — External Connections roster, provisioning states', () => {
	/**
	 * @e2e integration-config-to-openconnector::a-provisioned-source-shows-its-slug-status
	 */
	test('a provisioned source shows its slug status', async ({ page }) => {
		const errors = collectAppErrors(page)
		await mockAdapters(
			page,
			makeAdapter({
				provisioning: {
					status: 'provisioned',
					openconnectorObjectId: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
					deepLink: '/apps/openconnector/sources',
				},
			}),
		)

		await page.goto(STATUS_ROUTE, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)

		const row = page.locator(
			'.external-adapters__item[data-adapter-id="mollie"]',
		)
		await expect(row).toBeVisible({ timeout: 10_000 })
		await expect(
			row.locator(
				'.external-adapters__badge[data-provisioning-status="provisioned"]',
			),
		).toBeVisible()
		// A provisioned row does not need the "provision me" call to action.
		await expect(
			row.getByRole('link', { name: /provision in openconnector/i }),
		).toHaveCount(0)

		expect(errors, `app console errors: ${errors.join(' | ')}`).toEqual([])
	})

	/**
	 * @e2e integration-config-to-openconnector::an-undeclared-source-falls-back-to-the-deep-link-prompt
	 */
	test('an undeclared source falls back to the deep-link prompt', async ({
		page,
	}) => {
		const errors = collectAppErrors(page)
		await mockAdapters(
			page,
			makeAdapter({
				provisioning: {
					status: 'declared-not-provisioned',
					deepLink: '/apps/openconnector/sources',
				},
			}),
		)

		await page.goto(STATUS_ROUTE, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)

		const row = page.locator(
			'.external-adapters__item[data-adapter-id="mollie"]',
		)
		await expect(row).toBeVisible({ timeout: 10_000 })
		await expect(
			row.locator(
				'.external-adapters__badge[data-provisioning-status="declared-not-provisioned"]',
			),
		).toBeVisible()
		const provisionLink = row.getByRole('link', {
			name: /provision in openconnector/i,
		})
		await expect(provisionLink).toBeVisible()
		await expect(provisionLink).toHaveAttribute(
			'href',
			/\/apps\/openconnector\/sources/,
		)

		expect(errors, `app console errors: ${errors.join(' | ')}`).toEqual([])
	})

	/**
	 * @e2e integration-config-to-openconnector::openregister-unavailable-degrades-every-row-not-the-whole-page
	 */
	test('OpenRegister unavailable degrades every row, not the whole page', async ({
		page,
	}) => {
		const errors = collectAppErrors(page)
		await mockAdapters(
			page,
			makeAdapter({
				provisioning: {
					status: 'unknown',
					deepLink: '/apps/openconnector/sources',
				},
			}),
		)

		await page.goto(STATUS_ROUTE, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)

		// The page still renders (200, not a crash) — the summary + the row.
		await expect(page.locator('.external-adapters__summary')).toBeVisible({
			timeout: 10_000,
		})
		const row = page.locator(
			'.external-adapters__item[data-adapter-id="mollie"]',
		)
		await expect(row).toBeVisible()
		await expect(
			row.locator(
				'.external-adapters__badge[data-provisioning-status="unknown"]',
			),
		).toBeVisible()
		// An "unknown" row still degrades gracefully to the same fallback deep
		// link an operator needs — it is not visually indistinguishable from
		// "provisioned".
		await expect(
			row.locator(
				'.external-adapters__badge--live[data-provisioning-status="unknown"]',
			),
		).toHaveCount(0)

		expect(errors, `app console errors: ${errors.join(' | ')}`).toEqual([])
	})
})
