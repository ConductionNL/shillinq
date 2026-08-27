/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — the Requisitions surface.
 *
 * ## The gap this closes
 *
 * `openspec/changes/archive/2026-07-14-purchase-requisition/specs/
 * purchase-requisition/spec.md` `@e2e exclude`s every one of its six
 * requirements with the same reason: *"no dedicated Playwright suite exists
 * yet for this manifest page type in this app"*. That was true — a
 * `git grep -l Requisition -- tests/e2e/` returned nothing, so the entire
 * `Requisitions` / `RequisitionDetail` USER-FACING surface shipped with zero
 * browser coverage while the backend carried `RequisitionServiceTest`,
 * `RequisitionConversionServiceTest` and `RequisitionControllerTest`.
 *
 * This file covers the half those excludes correctly identify as browser
 * work and nothing more: that the two manifest pages RESOLVE, that they mount
 * the GENERIC renderer's typed page (`cn-index-page` / `cn-detail-page` — both
 * pages ship with no custom `component`, see `src/manifest.json`'s
 * `Requisitions` / `RequisitionDetail` entries), that the index binds the
 * columns the manifest declares, that the index is reachable by NAVIGATION and
 * not only by URL, and that a row opens its detail page.
 *
 * The approve/reject/convert LIFECYCLE is deliberately NOT driven here. Those
 * are server-authoritative custom endpoints gated by `BudgetBlocker::canCommit`
 * (REQ-REQ-002/-003); driving them from a spec would mutate the shared
 * administration's seeded requisitions irreversibly (`draft` -> `submitted` has
 * no UI undo), and their fail-closed behaviour is already proven against a REAL
 * unmodified `BudgetBlocker`/`PurchaseOrderService` in PHPUnit, which is what
 * the spec's own `@e2e exclude` reasons say. What was missing, and is added
 * here, is proof the pages a user actually opens exist and render.
 *
 * ## Fixtures
 *
 * `lib/Settings/register.d/purchase-requisition.json` DECLARES its own seed
 * objects — `REQ-2026-adm-demo-000001` (`draft`) and `REQ-2026-adm-demo-000002`
 * (`submitted`), the spec's own "seed data covers every lifecycle-reachable
 * starting status" scenario. They are imported with the register itself (by the
 * install repair step locally, and explicitly by `tests/e2e/ci-seed.sh` in CI),
 * so they are a DECLARED fixture of this app rather than ambient state a
 * previous spec happened to leave behind. The detail-page test asserts against
 * `REQ-2026-adm-demo-000001` BY NUMBER for exactly that reason: a bare
 * "click the first row" would silently pass on any unrelated object that
 * happened to sort first.
 *
 * ## Locator discipline
 *
 * Every locator below is a `data-testid` or a manifest-declared column label.
 * No nc-vue chrome is addressed by its accessible NAME: this instance renders
 * Dutch (the collapse toggle's `aria-label` is `Menu openen`, not
 * `Open menu`), so an English-name role locator either misses or — worse —
 * matches some other feature's element and reports a false PASS.
 *
 * @e2e purchase-requisition::requisitions-index-resolves-and-renders-columns
 * @e2e purchase-requisition::requisitions-reachable-through-navigation
 * @e2e purchase-requisition::requisition-row-opens-its-detail-page
 * @spec openspec/changes/archive/2026-07-14-purchase-requisition/specs/purchase-requisition/spec.md
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const REQUISITIONS_ROUTE = '/inkoop/requisitions'

/** The `draft` requisition declared by `register.d/purchase-requisition.json`. */
const SEEDED_REQUISITION_NUMBER = 'REQ-2026-adm-demo-000001'

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/** Strip `/index.php`, query and hash, and any trailing slash. */
function normalisePath(urlOrPath: string): string {
	const path = urlOrPath.startsWith('http')
		? new URL(urlOrPath).pathname
		: urlOrPath.split(/[?#]/)[0]
	return path.replace('/index.php', '').replace(/\/+$/, '') || '/'
}

/**
 * Deep-link to a manifest route and prove the SPA resolved it rather than
 * falling through to the `/:pathMatch(.*)*` catch-all redirect to Dashboard
 * (`src/main.js`) — the `budget-core-schema.spec.ts` / `budget-known-costs.spec.ts`
 * `gotoRoute()` precedent.
 */
async function gotoRoute(page: Page, route: string): Promise<void> {
	const target = APP + route
	await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 25_000 })
	await page.waitForSelector('#content-vue', { timeout: 15_000 })
	await dismissOverlays(page)
	expect(
		normalisePath(page.url()),
		`route ${route} must be declared by the manifest and resolve to itself`,
	).toBe(normalisePath(target))
}

test.describe('purchase-requisition — the Requisitions index (REQ-REQ-001)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
		// The root config allows 30s per test. The nav-reachability test loads
		// the Dashboard, expands a cluster and then loads the index — MEASURED at
		// 16s idle but 31s on a loaded box, i.e. right on the line. Raised for
		// the block (the `budget-scenarios.spec.ts` `test.setTimeout()`
		// precedent) so a slow instance reports a real failure rather than a bare
		// "Test timeout" that names no cause.
		test.setTimeout(120_000)
	})

	/**
	 * @e2e purchase-requisition::requisitions-index-resolves-and-renders-columns
	 *
	 * `/inkoop/requisitions` resolves to the generic index renderer and binds
	 * the `Requisition` column set the manifest declares — not merely "some
	 * app content", which the catch-all redirect to Dashboard would also
	 * satisfy.
	 */
	test('the Requisitions route resolves and the typed index page renders its declared columns', async ({
		page,
	}) => {
		await gotoRoute(page, REQUISITIONS_ROUTE)

		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})
		await expect(page.getByTestId('cn-page-title')).toContainText('Requisitions')

		// The manifest's `config.columns` for the `Requisition` schema. A
		// Dashboard fallback renders none of these, so the set is what
		// distinguishes "the Requisitions page mounted" from "the SPA is up".
		const table = page.getByTestId('cn-object-list-table')
		await expect(table).toBeVisible({ timeout: 15_000 })
		for (const header of [
			'Requisition #',
			'Requester',
			'Cost Centre',
			'Fiscal Year',
			'Needed By',
			'Amount (excl. VAT)',
			'Status',
		]) {
			await expect(
				table.locator('thead th').filter({ hasText: header }),
				`manifest column "${header}" is rendered`,
			).toHaveCount(1)
		}
	})

	/**
	 * @e2e purchase-requisition::requisitions-reachable-through-navigation
	 *
	 * A route that only a hand-typed URL reaches is not a shipped feature.
	 * The `Requisitions` leaf lives under the `Purchasing` cluster, which
	 * renders COLLAPSED: its children are in the DOM but hidden. Expanding it
	 * means clicking the entry's own collapse toggle — `.icon-collapse`, a
	 * structural class, because its `aria-label` is Dutch on this instance —
	 * since the top-level link NAVIGATES (to `/purchasing/overview`) instead of
	 * expanding.
	 */
	test('Requisitions is reachable by clicking through the Purchasing nav cluster', async ({
		page,
	}) => {
		await page.goto(`${APP}/`, {
			waitUntil: 'domcontentloaded',
			timeout: 25_000,
		})
		await page.waitForSelector('#content-vue', { timeout: 15_000 })
		await dismissOverlays(page)

		const purchasing = page.getByTestId('cn-nav-entry-Purchasing')
		await expect(
			purchasing,
			'the Purchasing cluster is a top-level nav entry',
		).toBeVisible({ timeout: 15_000 })

		const leaf = page.getByTestId('cn-nav-entry-Requisitions')
		await expect(
			leaf,
			'Requisitions is declared as a child of Purchasing, so it is in the DOM before expansion',
		).toHaveCount(1)
		await expect(leaf, '…but hidden while the cluster is collapsed').toBeHidden()

		// Assert the trigger exists BEFORE clicking it: a click that never
		// lands would otherwise be blamed on the navigation that follows.
		const toggle = purchasing.locator('button.icon-collapse').first()
		await expect(
			toggle,
			'the Purchasing entry has a collapse toggle',
		).toBeVisible({ timeout: 10_000 })
		await toggle.click()

		await expect(leaf, 'expanding Purchasing reveals Requisitions').toBeVisible({
			timeout: 10_000,
		})
		await leaf.click()

		await expect(page).toHaveURL(
			new RegExp(`${REQUISITIONS_ROUTE.replace(/\//g, '\\/')}$`),
			{ timeout: 15_000 },
		)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})
		await expect(page.getByTestId('cn-page-title')).toContainText('Requisitions')
	})
})

test.describe('purchase-requisition — the RequisitionDetail page (REQ-REQ-001)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
		// The root config allows 30s per test. The nav-reachability test loads
		// the Dashboard, expands a cluster and then loads the index — MEASURED at
		// 16s idle but 31s on a loaded box, i.e. right on the line. Raised for
		// the block (the `budget-scenarios.spec.ts` `test.setTimeout()`
		// precedent) so a slow instance reports a real failure rather than a bare
		// "Test timeout" that names no cause.
		test.setTimeout(120_000)
	})

	/**
	 * @e2e purchase-requisition::requisition-row-opens-its-detail-page
	 *
	 * The index's `config.detailRoute` points at `RequisitionDetail`
	 * (`/inkoop/requisitions/:id`). Clicking the seeded `draft` requisition's
	 * row must land on that page with the object's own id in the URL and the
	 * requisition number on screen — the click-through the manifest's own
	 * `_note` describes ("Click through to RequisitionDetail for lines,
	 * approve/reject and convert-to-PO actions").
	 */
	test('clicking the seeded requisition row opens RequisitionDetail for that object', async ({
		page,
	}) => {
		await gotoRoute(page, REQUISITIONS_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})

		const row = page
			.getByTestId('cn-object-row')
			.filter({ hasText: SEEDED_REQUISITION_NUMBER })
		await expect(
			row,
			`the register-declared seed requisition ${SEEDED_REQUISITION_NUMBER} is listed `
				+ '(imported with lib/Settings/register.d/purchase-requisition.json)',
		).toHaveCount(1)
		await expect(row).toBeVisible({ timeout: 15_000 })

		await row.click()

		// `:id` is the object UUID, so match the shape rather than a literal.
		await expect(page).toHaveURL(
			/\/apps\/shillinq\/inkoop\/requisitions\/[0-9a-f-]{36}$/,
			{ timeout: 15_000 },
		)
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({
			timeout: 15_000,
		})
		// NOT A TEST TIMING PROBLEM — do not "fix" this by raising the timeout
		// below or by awaiting the object GET (both were tried, 2026-08-21).
		//
		// When this fails, the object read has already completed and the page
		// has mounted; it simply renders NO FIELDS. The accessibility snapshot
		// from the failing run (shillinq#1085, CI 32530827572) is the whole
		// `main` region at the moment of failure:
		//
		//     - main:
		//       - heading "Requisition" [level=2]
		//       - button "Actions"
		//       - group "related":
		//           - note "No relations yet"
		//       - status
		//
		// Shell, actions and the related-lists group are all there. None of the
		// sixteen `config.fields` are — not requisitionNumber, not requester,
		// nothing. Awaiting the fetch cannot help a render that never happens,
		// and a longer clock would only mean waiting longer for the same empty
		// page. Tracked in shillinq#928.
		await expect(
			page
				.getByTestId('cn-detail-page')
				.getByText(SEEDED_REQUISITION_NUMBER)
				.first(),
			'the detail page shows the requisition that was clicked, not some other object',
		).toBeVisible({ timeout: 15_000 })
	})
})
